<?php

namespace Strivebenifits\Messagehub\Repositories;

use App\Http\Repositories\BaseRepository;
use Strivebenifits\Messagehub\Models\NotificationMessageHub;
use Strivebenifits\Messagehub\Models\NotificationMessageHubPushLog;
use Strivebenifits\Messagehub\Models\NotificationInvoice;
use Strivebenifits\Messagehub\Models\NotificationMessageHubTextLog;
use Strivebenifits\Messagehub\Models\NotificationMessageHubEmailLog;
use App\Models\User;
use App\Models\EmployeeDemographic;
use App\Models\MessageMapping;
use Strivebenifits\Messagehub\Models\MongoDb\NotificationSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use DB;
use Config;
use Illuminate\Database\Connection;
use Strivebenifits\Messagehub\Jobs\sendNotifications;
use phpFCMSBv1\Client;
use phpFCMSBv1\Notification;
use phpFCMSBv1\Recipient;
use phpFCMSBv1\Data;
use Exception;
use Strivebenifits\Messagehub\Jobs\sendSms;
use Auth;
use Session;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Repositories\FilterTemplateBlocksRepository;
use App\Http\Repositories\FilterTemplateDynamicFieldsRepository;
use App\Http\Repositories\UsersRepository;
use App\Mail\NotificationEmail;
use Mail;
use App\Http\Managers\TemplateManager;

use App\Http\Repositories\MappedHashtagRepository;
use App\Jobs\ProcessBulkPushNotification;
use App\Jobs\ProcessBulkEmailNotification;
use App\Jobs\ProcessBulkTextNotification;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * Class MessagehubRepository
 * @package App\Http\Repositories
 */
class MessagehubRepository extends BaseRepository
{

    /**
     * @var UsersRepository
     */
    private $usersRepository;

    private $increment = 0;

    private $duplicateDevices = [];

    public $sms_enabled = false;

    private $thumbnailPath = "";

    private $notificationType = "";

    private $transactionId = "";

    private $notificationData = [];

    private $notificationIds = [];

    private $pushInfo = [];

    private $isResend = false;

    private $resendData = [];

    /**
     * @var TemplateManager
     */
    private TemplateManager $templateManager;

    private $mappedHashtagRepository;

    /**
     * MessagehubRepository constructor.
     * @param NotificationMessageHub $notificationMessage
     * @param Connection $eloquentORM
     * @param TemplateManager $templateManager
     */
    public function __construct(NotificationMessageHub $notificationMessage, 
        Connection $eloquentORM,
        TemplateManager $templateManager, 
        MappedHashtagRepository $mappedHashtagRepository)
    {
        parent::__construct($eloquentORM);
        $this->model = $notificationMessage;
        $this->templateManager = $templateManager;
        $this->mappedHashtagRepository = $mappedHashtagRepository;
    }

    public function setSmsEnabled($value){
        $this->sms_enabled = $value;
    }

    public function setThumbnailPath($path){
        $this->thumbnailPath = $path;
    }

    public function setNotificationType($value){
        $this->notificationType = $value;
    }

    public function getNotificationType(){
        return $this->notificationType;
    }

    public function setNotificationData($data){
        $this->notificationData = $data;

        $this->notificationData['message'] = !empty($this->notificationData['message'])?$this->model->parseMessage($this->notificationData['message']):'';
        $this->notificationData['title'] = !empty($this->notificationData['title'])?$this->notificationData['title']:'';
        $this->notificationData['email_subject'] = !empty($this->notificationData['email_subject'])?$this->notificationData['email_subject']:'';
        $this->notificationData['email_template'] = !empty($this->notificationData['email_template'])?$this->notificationData['email_template']:'';
        $this->notificationData['email_body'] = !empty($this->notificationData['email_body'])?$this->notificationData['email_body']:'';
        $this->notificationData['target_screen'] = !empty($this->notificationData['target_screen'])?$this->notificationData['target_screen']:'';
        $this->notificationData['target_screen_param'] = !empty($this->notificationData['target_screen_param'])?$this->notificationData['target_screen_param']:'';

        if(isset($data['id']) && $data['notification_type'] == 'email'){
            $this->notificationData['email_subject'] = $data['title'];
            $this->notificationData['email_body'] = $data['message'];
        }

        $this->notificationData['created_by'] = !empty($data['created_by'])?$data['created_by']:auth()->user()->id;
        $this->notificationData['created_as'] = !empty($data['created_as'])?$data['created_as']:getEmployerId();

        if(!empty($data['thumbnail_path'])){
            $this->setThumbnailPath($data['thumbnail_path']);
        }
    }

    public function setPushInfo($brokerId){
        //Get the assigned app of the broker who created this employer
        $assigned_app = $this->getAppDetails($brokerId);

        //$appIdentifier = $assigned_app->app_identifier;
        $this->pushInfo['androidApi'] = $assigned_app->android_api;
        $this->pushInfo['appStoreTarget'] = $assigned_app->app_store_target;
        
        //Set file path
        $this->pushInfo['iosCertificateFile'] = public_path().'/push/ios/'.$assigned_app->ios_certificate_file;
        $this->pushInfo['fcmKey'] = public_path().'/push/android/'.$assigned_app->fcm_key;
    }

    public function setResendData($notification){
        $this->resendData = $notification->toArray();
        $this->isResend = true;
    }

    /**
     * getEmployersFilter.
     * @param 
     * @return  array employers
     */
    public function getEmployersFilter($role, $uid=null)
    {
        $employers = [];
        $userid = ($uid)?$uid:Auth::user()->id;
        switch ($role) {
            case config('role.EMPLOYER'):
                $employers = [$userid];
                break;
            case config('role.BROKER'):
                if(loggedinAsEmployer()){
                    $employers = [getEmployerId()];
                }else{
                    $employers = array_column($this->getEmployerList($userid), 'id');
                }
                break;
            case config('role.HR_ADMIN'):
            case config('role.HR'):
                $employers = [User::where('id',$userid)->select('referer_id')->first()->referer_id];
                break;
            default:
                if(loggedinAsEmployer()){
                    $employers = [getEmployerId()];
                }
                break;
        }
        return $employers;
    }

    /**
     * getAllNotificationsByRole.
     * @param 
     * @return  Query Collection
     */
    public function getAllNotificationsByRole($role, $uid=null)
    {
        $userid = ($uid)?$uid:Auth::user()->id;
        $employers = $this->getEmployersFilter($role, $userid);
        $notifications = $this->model;

        $notifications = $notifications->where(function($query) use ($employers) {
            $query->whereHas('pushNotifications', function (Builder $q) use ($employers){
                            if(!empty($employers)){
                                $q->whereIn('employer_id', $employers);
                            }
                        });
            $query->orWhereHas('textNotifications', function (Builder $q) use ($employers){
                        if(!empty($employers)){
                            $q->whereIn('employer_id', $employers);
                        }
                    });
            $query->orWhereHas('emailNotifications', function (Builder $q) use ($employers){
                        if(!empty($employers)){
                            $q->whereIn('employer_id', $employers);
                        }
                    });
        });

        $notifications->select(['notifications_message_hub.id','notifications_message_hub.message','notifications_message_hub.title','notifications_message_hub.notification_type','notifications_message_hub.created_at'])->orderBy('created_at', 'desc');
        return $notifications;
    }

    /**
     * getAllNotificationsByMessageIds.
     * @param 
     * @return  Query Collection
     */
    public function getAllNotificationsByMessageIds($messageIds)
    {
        $notifications = $this->model;

        $notifications = $notifications->whereIn('notifications_message_hub.id', $messageIds);

        $notifications->select(['notifications_message_hub.id','notifications_message_hub.message','notifications_message_hub.title','notifications_message_hub.notification_type','notifications_message_hub.created_at'])->orderBy('created_at', 'desc');
        return $notifications;
    }

    /**
     * getAllNotificationsDetails.
     * @param 
     * @return  Query Collection
     */
    public function getAllNotificationsDetails($type = '', $startDate = '', $endDate = '', $employeeIds = '', $employerIds = '', $filterTemplate = '', $searchMessage = '')
    {
        $notifications = DB::table('notifications_message_hub AS x')
                            ->whereNull('x.deleted_at')
                            ->select(['x.id','x.message','x.title','x.notification_type','x.filter_value']);

        if($type == 'in-app'){
            $notifications->join('notifications_message_hub_push_log','notifications_message_hub_push_log.message_id','=','x.id');
            $notifications->addSelect('notifications_message_hub_push_log.employee_id as employee_id','notifications_message_hub_push_log.employer_id as employer_id','notifications_message_hub_push_log.status','notifications_message_hub_push_log.created_at');

        }

        if($type == 'text'){
            $notifications->join('notifications_message_hub_text_log','notifications_message_hub_text_log.message_id','=','x.id');
            $notifications->addSelect('notifications_message_hub_text_log.employee_id as employee_id','notifications_message_hub_text_log.employer_id as employer_id','notifications_message_hub_text_log.status','notifications_message_hub_text_log.created_at');
        }

        if($type == ''){
            $query1 = clone $notifications;
            $query2 = clone $notifications;

            $query1->join('notifications_message_hub_push_log','notifications_message_hub_push_log.message_id','=','x.id');
            $query1->addSelect('notifications_message_hub_push_log.employee_id as employee_id','notifications_message_hub_push_log.employer_id as employer_id','notifications_message_hub_push_log.status','notifications_message_hub_push_log.created_at');

            $query2->join('notifications_message_hub_text_log','notifications_message_hub_text_log.message_id','=','x.id');
            $query2->addSelect('notifications_message_hub_text_log.employee_id as employee_id','notifications_message_hub_text_log.employer_id as employer_id','notifications_message_hub_text_log.status','notifications_message_hub_text_log.created_at');

            $query1->union($query2);

            $notifications = DB::table(DB::raw("({$query1->toSql()}) as x"))
                                ->select(['x.message','x.title', 'x.notification_type', 'x.employee_id','x.employer_id', 'x.status', 'x.created_at', 'x.filter_value']);
        }

        if(!empty($startDate) && !empty($endDate)){
            $notifications->whereDate('x.created_at','>=', $startDate)
                        ->whereDate('x.created_at','<=', $endDate);
        }
        
        if(!empty($employeeIds)){
            if(is_array($employeeIds)){
                $notifications->whereIn('employee_id', $employeeIds);
            }else{
                $notifications->where('employee_id','=',$employeeIds);
            }
        }else{
            if(!empty($employerIds)){
                $notifications->whereIn('employer_id', $employerIds);
            }
        }

        if(!empty($filterTemplate)){
            $notifications->where('x.filter_value', $filterTemplate);
        }

        if(!empty($searchMessage)){
            $notifications->where('x.message','like', '%'.$searchMessage.'%');
        }

        $notifications->join('users','employee_id','=','users.id')->addSelect('users.email');
        return $notifications;
    }


    /**
     * getAllNotificationsChartData.
     * @param 
     * @return  Query Collection
     */
    public function getAllNotificationsChartData($type, $startDate = '', $endDate = '', $employeeIds = null, $employerIds = null, $filterTemplate = null, $searchMessage = null)
    {
        $data = ['in-app' => '', 'text' => ''];
        if($type == '' || $type =='in-app'){
            $query1 = $this->getAllNotificationsDetails('in-app', $startDate, $endDate, $employeeIds, $employerIds, $filterTemplate, $searchMessage);
            $data['in-app'] = $this->getChartData($query1);
            
        }

        if($type == '' || $type=='text')
        {
            $query2 = $this->getAllNotificationsDetails('text', $startDate, $endDate, $employeeIds, $employerIds, $filterTemplate, $searchMessage);
            $data['text'] = $this->getChartData($query2);
        }
        return $data;
    }


    public function getChartData($query)
    {
        $query->select(
                    DB::raw("sum(case when STATUS = 'sent' then 1 else 0 end) as sent,sum(case when STATUS = 'delivered' OR STATUS = 'success' then 1 else 0 end) as delivered,sum(case when STATUS = 'undelivered' then 1 else 0 end) as undelivered,sum(case when STATUS = 'failed' then 1 else 0 end) as failed, sum(case when STATUS = 'opened' then 1 else 0 end) as open,sum(case when STATUS = 'read' then 1 else 0 end) as `read`, DATE_FORMAT(x.created_at, '%Y-%M') as created_at")
                );
        $query->groupBy(DB::raw('YEAR(x.created_at)'), DB::raw('MONTH(x.created_at)'))
                    ->orderBy('x.created_at', 'desc');
        return $query->get();
    }
    public function getMessageIds($employers)
    {
        $query1 = NotificationMessageHubPushLog::whereIn('employer_id', $employers)->pluck('message_id')->toArray();
        $query2 = NotificationMessageHubTextLog::whereIn('employer_id', $employers)->pluck('message_id')->toArray();
        return array_unique(array_merge($query1,$query2));
    }    

    /**
     * getNotificationById.
     * @param user ID
     * @return Collection notificationDetails
     */
    public function getNotificationById($id){
        return $this->model::with('pushNotifications','textNotifications','pushNotifications.employee')->find($id);
    }

    /**
     * Prepare data to send push notification
     * @param Array employerArr
     * @return Schedule Push Notification Message
     */
    public function processPushNotification($employerList, $brokerId)
    {
        try {
            $filterTemplate = '';

            if($this->notificationData['send_to'] == 'send_to_all'){
                $employeeList = [];
            }
            else if($this->notificationData['send_to'] == 'send_to_filter_list'){
                $employeeList = [];

                $filterTemplate = $this->notificationData['filterTemplate'];
            }else{
                $employees = $employeeList = $this->notificationData['employees'];
            }

            //$this->setPushInfo($brokerId);

            $chunkEmployerList = array_chunk($employerList, 2);

            foreach($chunkEmployerList as $employerList){
                $batchList = [];
                foreach($employerList as $employerId){
                    if(empty($employeeList)){
                        $employees = $this->getEmployeeList(config('messagehub.notification.type.INAPP'), [$employerId], [], [], $filterTemplate);
                    }
                    $batchList[] = new ProcessBulkPushNotification($brokerId, $employerId, $employees, $this->notificationData);
                }
                Bus::batch([
                    $batchList
                ])->then(function (Batch $batch) {
                })->onQueue('bulk_push_notification_queue')
                ->name('bulk_push_notification_queue')->dispatch();
            }

            $status_code = 200;
            $message = 'Your users will receive notifications shortly!';
        } catch (Exception $e) {
            Log::error($e);
            $status_code = 400;
            $message = $e->getMessage();
        }
        return ['status_code' => $status_code, 'message' => $message];
    }

    /**
     *  Send Push notification to Queue
     *  @param
     *  @return
     */    
    public function dispatchPushNotification($employee, $employerId)
    {
        try {
            $deviceToken = $deviceType = $is_flutter = '';
            if(is_array($employee)){
                $employeeId  = $employee['id'];
                $deviceToken = $employee['device_id'];
                $deviceType  = $employee['device_type'];
                $is_flutter  = $employee['is_flutter'];
            }else{
                $employeeId = $employee;
                $device_details = $this->getDevicebyEmployee($employeeId);

                if($device_details){
                    $deviceToken = $device_details->device_id;
                    $deviceType  = $device_details->device_type;
                    $is_flutter  = $device_details->is_flutter;
                }
            }

            if($this->isResend){
                $messageId = $this->notificationData['id'];
                $pushMessageId = $this->resendData['id'];
            }else{
                $messageId = $this->addNotification($employerId, true);
                $pushMessageId = '';
            }
            $pushNotificationData = array();

            //This condition will handle sending multiple push if different users logged in same device.
            if(!in_array($deviceToken, $this->duplicateDevices) && $deviceToken != ''){
                $this->duplicateDevices[] = $deviceToken;

                $deviceType = $this->getDeviceType($deviceType);

                $pushNotificationData = array(
                    'employee_id' => (string) $employeeId, 
                    'employer_id' => (string) $employerId, 
                    'message_id'=> (string) $messageId,
                    'push_message_id'=> (string) $pushMessageId,
                    'device_type' => (string) $deviceType,
                    'device_token'=> (string) $deviceToken,
                    'message' => (string) $this->notificationData['message'],
                    'ios_certificate_file' => (string) $this->pushInfo['iosCertificateFile'],
                    'android_api' => (string) $this->pushInfo['androidApi'],
                    'fcm_key' => $this->pushInfo['fcmKey'],
                    'title' => $this->notificationData['title'],
                    'app_store_target' => $this->pushInfo['appStoreTarget'], 
                    'is_flutter' => $is_flutter,
                    'target_screen' => $this->notificationData['target_screen'],
                    'target_screen_param' => $this->notificationData['target_screen_param'],
                    'is_resend' => $this->isResend
                );

                $seconds=0+($this->increment*2);
                //sendNotifications::dispatch($pushNotificationData)->delay($seconds);
                $this->increment ++;
            }else{//user has no device so make entry into notifications_message_hub_push_log with failed status when not resending message
                if(! $this->isResend){
                    $send_data = array('employee_id' => (string) $employeeId, 'employer_id' => (string) $employerId, 'message_id'=> (string) $messageId,'message' => (string) $this->notificationData['message'],'title' => $this->notificationData['title'],'is_flutter' => $is_flutter,'target_screen' => $this->notificationData['target_screen'],'exception_message'=>'App not Downloaded' );
                    $messageStatus = 'failed';

                    $logID = $this->insertNotificationLog($send_data, $messageId, $messageStatus);
                }
            }

            return $pushNotificationData;
        } catch (Exception $e) {
            Log::error($e);   
        }
    }

    /**
     * Get All employee data for each employer and then passe it to send into job
     * @param Array request
     * @param Array employerIds
     * @return Schedule TEXT Message
     */
    public function processTxtNotifications($employerIds)
    {
        try {
            Log::info(json_encode($employerIds));
            ProcessBulkTextNotification::dispatch($employerIds, $this->notificationData);
            // foreach ($employerIds as $key => $employerId) {
            //     $employees = $this->getEmployeeBySentType($employerId);
            //     $this->dispatchTextNotification($employees, $employerId);
            // }

            $message = 'Your users will receive the SMS shortly!';
            $status_code = 200;
        } catch (Exception $e) {
            Log::error($e);
            $status_code = 400;
            $message = $e->getMessage();
        }
        return ['status_code' => $status_code, 'message' => $message];
    }

    /**
     * Send Text notification to Queue for each employee
     * @param
     * @return
     */
    public function dispatchTextNotification($employees,$employerId)
    {
        try {
            $notificationMessageId = $this->addNotification($employerId);
            $chunkEmployeeList = array_chunk($employees, 20);

            foreach($chunkEmployeeList as $employeeList){
                $batchList = [];
                foreach($employeeList as $employee){
                    $smsData = ['employee' => $employee,
                            'employer_id' => $employerId,
                            'message' => $this->notificationData['message'],
                            'message_id' => $notificationMessageId];               
                        $batchList[] = new sendSms($smsData);
                }
                if(!empty($batchList)){
                    Bus::batch([
                        $batchList
                    ])->then(function (Batch $batch) {
                    })->onQueue('txt_notification_queue')
                    ->name('txt_notification_queue')->dispatch();
                }
            }
        } catch (Exception $e) {
            Log::error($e);
        }
    }


    /**
     * Get All employee data for each employer and then passe it to send into job
     * @param Array request
     * @param Array employerIds
     * @return Schedule Email
     */
    public function processEmailNotifications($employerList)
    {
        try {
            $filterTemplate = '';

            if($this->notificationData['send_to'] == 'send_to_all'){
                $employeeList = [];
            }
            else if($this->notificationData['send_to'] == 'send_to_filter_list'){
                $employeeList = [];

                $filterTemplate = $this->notificationData['filterTemplate'];
            }else{
                $userRepository = app()->make(UsersRepository::class);
                $employees = $employeeList = $userRepository->getByWhereIn($this->notificationData['employees'], 'id', [], ['id','email']);
            }

            $chunkEmployerList = array_chunk($employerList, 2);

            foreach($chunkEmployerList as $employerList){
                $batchList = [];
                foreach($employerList as $employerId){
                    if(empty($employeeList)){
                        $employees = $this->getEmployeeList(config('messagehub.notification.type.EMAIL'), [$employerId], [], [], $filterTemplate);
                    }
                    $batchList[] = new ProcessBulkEmailNotification($employerId, $employees, $this->notificationData);
                }
                Bus::batch([
                    $batchList
                ])->then(function (Batch $batch) {
                })->onQueue('bulk_email_notification_queue')
                ->name('bulk_email_notification_queue')->dispatch();
            }

            $message = 'Your users will receive the Email!';
            $status_code = 200;
        } catch (Exception $e) {
            Log::error($e);
            $status_code = 400;
            $message = $e->getMessage();
        }
        return ['status_code' => $status_code, 'message' => $message];
    }

    /**
     * Send Email notification to Queue for each employee
     * @param
     * @return
     */
    public function dispatchEmailNotification($employees,$employerId)
    {
        try {

            if($this->isResend){
                $notificationMessageId = $this->notificationData['id'];
            }else{
                $notificationMessageId = $this->addNotification($employerId, true);
            }

            if(method_exists($this->templateManager,'mapEmailTemplateKeywords')){
                $this->notificationData['email_body'] = $this->templateManager->mapEmailTemplateKeywords($this->notificationData['email_body'], $employerId);
            }

            foreach ($employees as $employee) {
                $emailData = ['employee' => $employee,
                            'employer_id' => $employerId,
                            'email_subject' => $this->notificationData['email_subject'],
                            'email_template' => $this->notificationData['email_template'],
                            'email_body' => $this->notificationData['email_body'],
                            'message_id' => $notificationMessageId];

                if(!$this->isResend){
                    NotificationMessageHubEmailLog::create(['employee_id' => $employee['id'], 'employer_id' => $employerId, 'message_id' => $notificationMessageId]);
                }
                $message = (new NotificationEmail($emailData))->onQueue('email_queue');
                Mail::to($employee['email'])->queue($message);
            }
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    /**
     * Add Notification to Message hub Notification table and store message ids to array to not create duplicate entry for type "in-ap-text"
     * @param employerId
     * @return MessageId
     */
    public function addNotification($employerId, $mapping = false)
    {
        if(!isset($this->notificationIds[$this->notificationType]['messageId'][$employerId])){
            $notificationMessageId = $this->model->insertNotificationData($this->notificationType, $this->transactionId, $this->notificationData, $this->thumbnailPath);
            $this->notificationIds[$this->notificationType]['messageId'][$employerId] = $notificationMessageId;

            if($mapping){
                $this->addMessageMappingData($notificationMessageId);
            }
        }

        return $this->notificationIds[$this->notificationType]['messageId'][$employerId];
    }

    /**
     * Add notification if to mapping table
     * @param $notificationMessageId
     * @return MessageId
     */
    public function addMessageMappingData($notificationMessageId)
    {
        $mappingDetails = ['new_message_id' => $notificationMessageId,'created_at' => Carbon::now()];
        $mappedId = MessageMapping::insertGetId($mappingDetails);

        //Extract hash tag and  save them
        $hashTagArr = extractHashTag($this->notificationData['message']);
        $this->mappedHashtagRepository->manageCommentHashtag($hashTagArr, $mappedId);

        $this->model::where(['id' => $notificationMessageId])->update(['mapped_id' => $mappedId]);
    }

    public function getEmployeeBySentType($employerId = '')
    {
        if($this->notificationData['send_to'] == 'send_to_all'){
            return $this->getEmployeeList($this->notificationType, $employerId);
        }
        else if(in_array($this->notificationData['send_to'], ['send_to_filter_list'])){
            return $this->getEmployeeList($this->notificationType, $employerId, [], [], $this->notificationData['filterTemplate']);
        }else{
            return $this->getPhoneNumberByUser($this->notificationData['employees']);
        }
    }

    /**
     * APNS Push Notification service
     * @param 
     * @return array with status
     */
    public function sendApns($url, $app_store_target, $badgeCount, $notificationId, $pushMessage, $cert, $data, $comment_type = '')
    {
        try{
            Log::info($cert);
            $headers = array(
                "apns-topic: ".$app_store_target,
                "User-Agent: My Sender"
            );
            $http2ch = curl_init();
            $target_screen_param = isset($data['target_screen_param'])?$data['target_screen_param']:'';
            
            $comment_id = isset($data['comment_id']) ? $data['comment_id'] : 0;
            $parent_comment_id = isset($data['parent_comment_id']) ? $data['parent_comment_id'] : 0;
            
            curl_setopt($http2ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            $message = '{"aps":{"alert":"'.$pushMessage.'","sound":"default","badge": '.$badgeCount.'},"customData": {"notification_id" : '.$notificationId.',"comment_id" : '.$comment_id.',"parent_comment_id" : '.$parent_comment_id.',"message_type" : "new", "target_screen" : "'.$data['target_screen'].'","target_screen_param" : "'.$target_screen_param.'","comment_type" : "'.$comment_type.'"}}';

            Log::info('Apn Message: '.$message);

            if (!defined('CURL_HTTP_VERSION_2_0')) {
                define('CURL_HTTP_VERSION_2_0', 3);
            }
            // other curl options
            curl_setopt_array($http2ch, array(
                CURLOPT_URL => $url,
                CURLOPT_PORT => 443,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POST => TRUE,
                CURLOPT_POSTFIELDS => $message,
                CURLOPT_RETURNTRANSFER => TRUE,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSLCERT => $cert,
                CURLOPT_HEADER => 1
            ));

            $result = curl_exec($http2ch);
            if ($result === FALSE) {
                return ['status' => 0, 'message' => "Curl failed: " .  curl_error($http2ch)];
            }

            $status = curl_getinfo($http2ch, CURLINFO_HTTP_CODE);
            $message = "";
        }catch(Exception $e){
            Log::error($e);
            $status = 0;
            $message = $e->getMessage();
        }
        return ['status' => $status, 'message' => $message];
    }

    /**
     * FCM Push Notification service
     * @param Array data
     * @param int unreadCount
     * @param int notificationId
     * @return array with status
     */
    public function fcmPush($data, $unreadCount, $notificationId, $comment_type = '')
    {
        try {
            $client = new Client($data['fcm_key']);
            $recipient = new Recipient();
            // Either Notification or Data (or both) instance should be created
            $notification = new Notification();
            // Recipient could accept individual device token,
            // the name of topic, and conditional statement
            $recipient -> setSingleRecipient($data['device_token']);
            // Setup Notificaition title and body
            $notification -> setNotification($data['title'], processPushNotificationText($data['message']));
            // Build FCM request payload
            //if($data['device_type'] !== 'appNameIOS'){
                $comment_id = isset($data['comment_id']) ? $data['comment_id'] : 0;
                $parent_comment_id = isset($data['parent_comment_id']) ? $data['parent_comment_id'] : 0;

                $fcmData = new Data();
                
                $fcmDataArr = [];
                $fcmDataArr['data'] = ['unread_count' =>(string) $unreadCount, 'notification_id' =>(string) $notificationId, 'comment_id' =>(string) $comment_id, 'parent_comment_id' =>(string) $parent_comment_id,  'msg_type' => "new",  'target_screen' => $data['target_screen'],  'target_screen_param' => isset($data['target_screen_param'])?$data['target_screen_param']:''];
                
                $fcmDataArr['apns'] = ['payload' => ['aps'=>['badge'=>$unreadCount,'contentAvailable' => true]]];
                
                $fcmDataArr['data']['comment_type'] = $comment_type;

                $fcmData->setPayload($fcmDataArr);
                $client -> build($recipient, $notification, $fcmData);

            // }else{
            //     $client -> build($recipient, $notification);
            // }
            $result = $client -> fire();
            $is_success = $result ===true?1:0;
            $message = $result;
        } catch (Exception $e) {
            Log::error($e);
            $is_success = 0;
            $message = $e->getMessage();
        }

        return ['is_success' => $is_success, 'exception_message' => $message];
    }

    /**
     * getAppDetails
     * @param Broker ID
     * @return Data App Details
     */
    public function getAppDetails($brokerId)
    {
        return DB::table('brokerdetails')
                        ->join('app','app.id','=','brokerdetails.assigned_app')
                        ->select('app.app_identifier','app.ios_certificate_file','app.android_api','app.fcm_key','app.app_store_target')
                        ->where('brokerdetails.user_id','=',$brokerId)
                        ->first();
    }

    /**
     * getDeviceDetails
     * @param employeeId
     * @return Data Device Details
     */
    public function getDevicebyEmployee($employeeId)
    {
        return DB::table('employee_device_mapping')
                        ->select('device_id','device_type','is_flutter')
                        ->where('employee_id','=',$employeeId)
                        ->whereNotNull('device_type')
                        ->where('device_type','!=','')
                        ->orderBy('updated_at','DESC')
                        ->first();
    }

    /**
     * getDeviceType
     * @param deviceType
     * @return 
     */    
    public function getDeviceType($deviceType)
    {
        if($deviceType == Config::get('constants.ANDROIDDEVICE')){
            return 'appNameAndroid';
        }else{
            return 'appNameIOS';
        }
    }

    /**
     * generate TransactionId
     * @param string notificationType
     * @return  TransactionID
     */
    public function generateTransactionId($notificationType = null)
    {
        if($notificationType == null){
            $notificationType = $this->notificationType;
        }
        $tmpCounter = $this->model::latest('id')->where('notification_type',$notificationType)->first();
        $tmpCounter = ($tmpCounter)?$tmpCounter->id:0;

        $prefix = '';
        switch ($notificationType) {
            case config('messagehub.notification.type.INAPP'):
                    $prefix = 'PN';
                break;
            case config('messagehub.notification.type.TEXT'):
                    $prefix = 'TXT';
                break;
            case config('messagehub.notification.type.INAPPTEXT'):
                    $prefix = 'PNTXT';
                // code...
                break;
            case config('messagehub.notification.type.EMAIL'):
                    $prefix = 'EML';
                // code...
                break;
            
            default:
                // code...
                break;
        }

        return  $this->transactionId = $prefix.date('Ym').'-'.str_pad(++$tmpCounter, 6, '0', STR_PAD_LEFT);
    }

    /**
     * scheduleNotification
     * @return  Store Data into Mongodb for queue
     */
    public function scheduleNotification()
    {
        try {
            $data = $this->notificationData;

            if(!empty($this->notificationData['employers'])){
                $data['employers'] = [];
                foreach ($this->notificationData['employers'] as $key => $employer) {
                    $data['employers'][] = (int) base64_decode($employer);
                }
            }
            
            if($this->notificationData['sent_type'] == 'choose-employer' && empty($data['employers'])){
                //extract($this->getBrokerAndEmployerId(Session::get('role')));
                extract($this->getBrokerAndEmployerById($this->notificationData['employerId']));
                $data['employers'][] = $employerId;
            }

            $data['created_by'] = $this->notificationData['created_by'];
            $data['created_as'] = $this->notificationData['created_as'];
            $data['thumbnail'] = $this->thumbnailPath;
            $data['notification_type'] = $this->notificationType;

            $schedule_datetime =  explode(" ",$this->notificationData['schedule_datetime']);

            $data['schedule_time'] = $schedule_datetime[1]." ".$schedule_datetime[2];
            $data['schedule_date'] = date('Y-m-d',strtotime($schedule_datetime[0]));
            
            $data['scheduled_utc_time'] = convertToUtc($this->notificationData['timezone'], $data['schedule_date'].' '.$data['schedule_time']);

            unset($data['brokers']);

            $this->prepareRecurringEventData($data);

            if(!empty($this->notificationData['schedule_id'])){
                $notificationSchedule = NotificationSchedule::where('_id',$this->notificationData['schedule_id'])->update($data);
                if($notificationSchedule->id == 200){
                    $response = ['status_code'=> 200,'message'=>'Schedule was updated successfully'];
                }else{
                    $response = ['status_code'=>400,'message'=>'Unable to update schedule'];
                }
            }else{
                $data['status'] = 'Scheduled';
                $notificationSchedule = NotificationSchedule::create($data);

                if($notificationSchedule->id){
                    $response = ['status_code'=> 200,'message'=>'Schedule was stored successfully'];
                }else{
                    $response = ['status_code'=>400,'message'=>'Unable to create schedule'];
                }
            }
        } catch (Exception $e) {
            Log::error($e);
            $response = ['status_code'=>400,'message'=> $e->getMessage()];
        }
        return $response;
    }

    /*
     * prepareRecurringEventData
     * @return set next event date and recurring data
     */
    public function prepareRecurringEventData(&$eventData)
    {
        $eventData['recurrence'] = $eventData['is_repeated'];
        $eventData['repeat_interval'] = $eventData['repeat_interval'];

        switch ($eventData['is_repeated']) {
            case 'every_weekday':
            case 'daily':
            case 'weekly':
            case 'monthly':
            case 'yearly':
                $eventData['is_custom'] = false;
                break;
            case 'custom':
                $eventData['is_custom'] = true;
                if($eventData['custom_repeat_type'] == 'day'){
                    $eventData['recurrence'] = 'daily';
                }
                elseif($eventData['custom_repeat_type'] == 'week'){
                    $eventData['recurrence'] = 'weekly';
                    $eventData['on_specific_days_of_month'] = $eventData['custom_days_of_week'];    
                }
                else if($eventData['custom_repeat_type'] == 'month'){
                    $eventData['recurrence'] = 'monthly';
                    if($eventData['custom_recurrence_type'] == 'on_day'){
                        $eventData['on_specific_day'] = $eventData['custom_on_day_txt'];
                    }else{
                        $eventData['on_specific_sequence'] = $eventData['custom_on_specific_sequence'];
                        $eventData['on_specific_days_of_month'] = $eventData['custom_on_specific_days_of_month'];
                    }    
                }
                else if($eventData['custom_repeat_type'] == 'year'){
                    $eventData['recurrence'] = 'yearly';
                    if($eventData['custom_recurrence_type'] == 'on_day'){
                        $eventData['on_specific_day'] = $eventData['custom_on_day_txt'];
                        $eventData['on_specific_month'] = $eventData['custom_on_day_month'];
                    }else{
                        $eventData['on_specific_sequence'] = $eventData['custom_on_specific_sequence'];
                        $eventData['on_specific_days_of_month'] = $eventData['custom_on_specific_days_of_month'];
                        $eventData['on_specific_month'] = $eventData['custom_on_the_specific_month'];
                    }                    
                }
                break;
            default:
                // code...
                break;
        }

        if($eventData['is_repeated'] != 'does_not_repeat'){
            $eventData['next_at'] = nextEventOccurance($eventData, $eventData['schedule_datetime']);
            $eventData['next_scheduled_utc_time'] = convertToUtc($this->notificationData['timezone'], $eventData['next_at']);
        }
        else{
            unset($eventData['schedule_end_datetime']);
        }
    }

    public function unreadNotificationMessages($user_id, $timestamp) {
        $query = NotificationMessageHubPushLog::where('notifications_message_hub_push_log.read_status', 0)
                ->whereNull('notifications_message_hub.deleted_at');

        return $this->getNotifications($query, $user_id, $timestamp)->count();
    }

    public function getNotifications($query, $user_id, $timestamp,) {
        $query = $query->join('notifications_message_hub','notifications_message_hub.id','=','notifications_message_hub_push_log.message_id')
            ->where('notifications_message_hub_push_log.employee_id', $user_id);

        if( $timestamp != 0)
        {
            $query = $query->where('notifications_message_hub_push_log.updated_at','>=',$timestamp);
        }
            
        $query = $query->whereDate('notifications_message_hub.valid_from', '<=', Carbon::now()->format('Y-m-d'))
            ->where(function($q) {
                $q->WhereDate('notifications_message_hub.expiry_date', '>=', Carbon::now()->format('Y-m-d'));
                $q->orwhereDate('notifications_message_hub.expiry_date','=', '0000-00-00');
            });
        return $query;
    }

    public function unreadOldNotificationMessages($user_id, $timestamp) 
    {
        return $this->notificationOld(DB::table('push_notification_logs'), $user_id, $timestamp)
                ->where('push_notification_logs.read_status', 0)
                ->where('notification_messages.is_delete', 0)->count();
    }

    public function notificationOld($query, $user_id, $timestamp)
    {
        $query = $query->join('notification_messages','notification_messages.id','=','push_notification_logs.message_id')
                ->where('push_notification_logs.employee_id', $user_id);

        if( $timestamp != 0) // for timestamp 0
        {
            $query = $query->where('push_notification_logs.updated_at','>=',$timestamp);
        }

        $query = $query->whereDate('notification_messages.valid_from', '<=', Carbon::now()->format('Y-m-d'))
            ->where(function($q) {
                $q->WhereDate('notification_messages.expiry_date', '>=', Carbon::now()->format('Y-m-d'));
                $q->orWhereDate('notification_messages.expiry_date','=', '0000-00-00 00:00:00');
            });
        return $query;
    }

    public function insertNotificationLog($data, $messageId, $messageStatus = '')
    {
        $insert_data = array('employee_id' => $data['employee_id'],
                    'employer_id' => $data['employer_id'],
                    'message_id'   => $messageId,
                    'read_status'  => 0,
                    'is_success'   => 0,
                    'exception_message' => isset($data['exception_message'])?$data['exception_message']:'',
                    'created_at'   => Carbon::now(), 
                    'updated_at'   => Carbon::now()
                    );
        if(!empty($messageStatus)){
            $insert_data['status'] = $messageStatus;
        }

        $log = NotificationMessageHubPushLog::create($insert_data);
        return $log->id;
    }

    public function updateNotificationLog($id, $data)
    {
        NotificationMessageHubPushLog::where('id',$id)->update($data);
    }

    public function updateNotificationLogByParam($where, $data)
    {
        NotificationMessageHubPushLog::where($where)->update($data);
    }

    /**
     * @param $messageId
     * @return array 
     */
    public function getTextNotificationLogCount($message_id)
    {
        //sum(case when STATUS = 'queued' then 1 else 0 end) as queued,
        return DB::table('notifications_message_hub_text_log')
            ->selectRaw(
                "sum(case when STATUS = 'sent' then 1 else 0 end) as sent,sum(case when STATUS = 'delivered' OR STATUS = 'success' then 1 else 0 end) as delivered,sum(case when STATUS = 'undelivered' then 1 else 0 end) as undelivered,sum(case when STATUS = 'failed' then 1 else 0 end) as failed"
            )
            ->where('message_id', $message_id)->first();
    }

    /**
     * @param $messageId
     * @return array 
     */
    public function getPushNotificationLogCount($message_id)
    {
        return DB::table('notifications_message_hub_push_log')
            ->selectRaw(
                "sum(case when STATUS = 'sent' then 1 else 0 end) as sent, sum(case when STATUS = 'opened' then 1 else 0 end) as open,sum(case when STATUS = 'read' then 1 else 0 end) as `read`, sum(case when STATUS = 'failed' then 1 else 0 end) as failed"
            )
            ->where('message_id', $message_id)->first();
    }

    /**
     * filter record by empty invoiceids
     * @param $startDate, $endDate
     * @return array 
     */
    public function getNotGeneratedInvoice($startDate, $endDate)
    {
        return $this->model->join('notifications_message_hub_text_log','notifications_message_hub_text_log.message_id','=','notifications_message_hub.id')
                            ->whereNull('notifications_message_hub.invoice_id')
                            ->whereDate('notifications_message_hub.created_at','>=', $startDate)
                            ->whereDate('notifications_message_hub.created_at','<=', $endDate)
                            ->withTrashed()
                            ->whereRaw(' `notifications_message_hub`.`created_as` = `notifications_message_hub_text_log`.`employer_id`')
                            ->selectRaw('group_concat(DISTINCT notifications_message_hub.id) as messageids,notifications_message_hub_text_log.employer_id,notifications_message_hub.created_by,notifications_message_hub.created_as')
                            ->groupBy('notifications_message_hub.created_as')
                            ->orderBy('notifications_message_hub.created_at', 'desc');
    }

    /**
     * get All notification detail by TEXT
     * @param $startDate, $endDate
     * @return array 
     */
    public function getNotificationsByText($startDate, $endDate)
    {
        $employers = $this->getEmployersFilter(Session::get('role'), Auth::user()->id);

        $query = $this->getNotGeneratedInvoice($startDate, $endDate);
        if(!empty($employers)){
            $query->whereIn('employer_id', $employers);
        }
        $query->whereIn('notification_type',[config('messagehub.notification.type.TEXT'),config('messagehub.notification.type.INAPPTEXT')]);
                   
        return $query;
    }

    /**
     * getAllSentTextDetails
     * @param $startDate, $endDate
     * @return array 
     */
    public function getAllSentTextDetails($startDate, $endDate)
    {
        $query = $this->model->join('notifications_message_hub_text_log','notifications_message_hub_text_log.message_id','=','notifications_message_hub.id')
                            ->whereDate('notifications_message_hub.created_at','>=', $startDate)
                            ->whereDate('notifications_message_hub.created_at','<=', $endDate)
                            ->whereIn('notifications_message_hub.notification_type',[config('messagehub.notification.type.TEXT'),config('messagehub.notification.type.INAPPTEXT')])
                            ->withTrashed()
                            ->whereRaw(' `notifications_message_hub`.`created_as` = `notifications_message_hub_text_log`.`employer_id`')
                            ->select('notifications_message_hub_text_log.id','notifications_message_hub_text_log.sms_type','notifications_message_hub_text_log.status','notifications_message_hub_text_log.mobile_number','notifications_message_hub_text_log.created_at','notifications_message_hub_text_log.employee_id','notifications_message_hub_text_log.employer_id');

        $employers = $this->getEmployersFilter(Session::get('role'), Auth::user()->id);
        if(!empty($employers)){
            $query->whereIn('notifications_message_hub_text_log.employer_id', $employers);
        }
        return $query;
    }

    /**
     * @param $data
     * @return id 
     */
    public function insertInvoice($data)
    {
        return NotificationInvoice::create($data);
    }

    /**
     * @param 
     * @return id 
     */
    public function getLastId()
    {
        if($lastRecord = NotificationInvoice::latest()->first()){
            return $lastRecord->id;
        }
        return 0;
    }

    /**
     * Get Details of all SMS
     * @param $messageIds
     * @return collection 
     */
    public function getSentSmsDetails($messageIds)
    {
        return NotificationMessageHubTextLog::whereIn('message_id',$messageIds)->select('id','sms_type','status','created_at','employee_id','employer_id','mobile_number');
    }

    /**
     * @param $messageIds
     * @return collection 
     */
    public function getInvoices($role = '')
    {
        $role = ($role =='')?Session::get('role'):$role;
        $query = NotificationInvoice::select('notification_invoices.id','notification_invoices.user_id','notification_invoices.paid_by', 'notification_invoices.invoice_no','notification_invoices.message_count','notification_invoices.amount','notification_invoices.tax','notification_invoices.discount','notification_invoices.start_date','notification_invoices.end_date','notification_invoices.status');

        if(loggedinAsEmployer()){
            $employers = $this->getEmployersFilter($role, Auth::user()->id);
        }else if($role == config('role.BROKER')){
            $employers = [Auth::user()->id];
        }

        if(!empty($employers)){
            $query->whereIn('user_id', $employers);
        }

        return $query;
    }

    /**
     * @param (int) $message
     * @return amount 
     */
    public function getTxtAmount($message)
    {
        $data = DB::table('txt_notification_prices')
                        ->where('min_message','<=',$message)
                        ->where('max_message','>=',$message)
                        ->select('price_per_message')
                        ->first();
        if(!$data){
            $data = DB::table('txt_notification_prices')
                        ->select('price_per_message')
                        ->orderBy('max_message','desc')
                        ->first();
        }
        return $data->price_per_message*$message;
    }

    /*
     * return a employer_id and broker id of logged in user
     * @param $role
     * @return Array
     */
    public function getBrokerAndEmployerId($role, $userId = '', $refererId = '', $brokerId = '')
    {
        switch($role){
            case config('role.ADMIN'):
            case config('role.BROKER'):
                $employer_id = $broker_id = ($userId)?:Auth::user()->id;
            break;
            case config('role.EMPLOYER'):
                $employer_id = ($userId)?:Auth::user()->id;
                $broker_id = ($refererId)?:Auth::user()->id;
            break;
            case config('role.HR_ADMIN'):
            case config('role.HR'):
                $employer_id = ($refererId)?:Auth::user()->id;
                $broker_id = ($brokerId)?:Auth::user()->id;
                break;
            case config('role.CHLOE'):
                $employer_id = ($userId)?:Auth::user()->id;
                $broker_id = ($userId)?:Auth::user()->id;
            break;
            case config('role.BROKEREMPLOYEE'):
                $employer_id = json_decode($employer_id);
                $broker_id = ($refererId)?:Auth::user()->id;
            break;
            default:
                $employer_id = ($userId)?:Auth::user()->id;
                $broker_id = User::where('id',$employer_id)->select('referer_id')->first()->referer_id;
            break;
        }
        return ['employerId' => $employer_id, 'brokerId' =>$broker_id];
    }

    public function getBrokerAndEmployerById($u_id)
    {
        $user = User::where('id',$u_id)->select('referer_id','broker_id','id')->first();
        if($user){
            /*
            * If user is Employer then referer_id will be broker id
            * If user is Employee then referer_id will be Employer id and broker_id will be Broker id
            */
            if($user->broker_id != 0){
                $broker_id = $user->broker_id;
            }else{
                $broker_id = $user->referer_id;
            }

            /*
            * If user is Employer then referer_id will be broker id
            * If user is Employee then referer_id will be Employer id and broker_id will be Broker id
            */
            if($user->broker_id != 0){
                $employer_id = $user->referer_id;
            }else{
                $employer_id = $user->id;
            }

            return ['employerId' => $employer_id, 'brokerId' => $broker_id];
        }
        return ['employerId' => '', 'brokerId' => ''];
    }

    private function getFilterData($filterTemplate = ''){
        $filterData = [];
        $blockData = [];

        if (!empty($filterTemplate)) {
            $filterTemplateBlocksRepository = app()->make(FilterTemplateBlocksRepository::class);
            $filterTemplateDynamicFieldsRepository = app()->make(FilterTemplateDynamicFieldsRepository::class);

            $filterData = $filterTemplateDynamicFieldsRepository->get(['template_id' => $filterTemplate]);
            $blockData = $filterTemplateBlocksRepository->get(['template_id' => $filterTemplate]);
        }
        return ['filterData' => $filterData, 'blockData' => $blockData];
    }

    /**
     * return Get the list of employees based on given employer array.
     *
     * @param Array $employers, for which we need to get data
     * @return Array $selectedEmployees
     */
    public function getEmployeeList($type, $employers, $selectedEmployees=array(), $emails = array(), $filterTemplate = '')
    {
        $employeeData = [];
        if(!is_array($employers)){
            $employers = [$employers];
        }

        extract($this->getFilterData($filterTemplate));

        foreach($employers as $employer){
            $query = User::join('employeedetails','employeedetails.user_id','=','users.id')
                        ->where('employeedetails.is_demo_account',0)
                        ->where('users.referer_id','=',$employer)
                        ->enabled()
                        ->active()
                        ->select('users.id','users.first_name','users.last_name','users.email','users.created_at','users.last_login');
            if(!empty($selectedEmployees)){
                $query = $query->whereIn('users.id',$selectedEmployees);
            }
            //filter record based on email if provided
            if(!empty($emails)){
                $query = $query->whereIn('users.email',$emails);
            }

            //Get only those users who has downloaded app
            $query->when(in_array($type,[config('messagehub.notification.type.INAPP')]), function ($q) {
                /*return $q->getActiveAppUser()
                                ->addSelect('employee_device_mapping.device_id','employee_device_mapping.device_type','employee_device_mapping.is_flutter');*/
                return $q->leftJoin('employee_device_mapping', 'employee_device_mapping.employee_id', '=', 'users.id')->addSelect('employee_device_mapping.device_id','employee_device_mapping.device_type','employee_device_mapping.is_flutter');
            });

            //Get only those users with mobile number
            $query->when(in_array($type,[config('messagehub.notification.type.TEXT')]), function ($q) use($type) {
                $q->getUserByMobile()->condHasMobile();
                return $q->addSelect('employee_demographics.phone_number');
            });

            if(!empty($filterData) && !empty($blockData)){
                $query->filter($filterData, $blockData, 'users');
            }

            if($type == config('messagehub.notification.type.INAPPTEXT')){
                $query1 = clone $query;
                $query = $query->getUserByMobile()->condHasMobile()->addSelect('employee_demographics.phone_number', DB::raw('null as device_id'), DB::raw('null as device_type'), DB::raw('null as is_flutter'))->get();
                $query1 = $query1->leftJoin('employee_device_mapping', 'employee_device_mapping.employee_id', '=', 'users.id')->addSelect('employee_device_mapping.device_id','employee_device_mapping.device_type','employee_device_mapping.is_flutter', DB::raw('"" as phone_number'))->get();
                $employeeData = array_merge($employeeData, $query1->merge($query)->toArray());
            }else{
                $employeeData = array_merge($employeeData, $query->get()->toArray());
            }
        }
        if(in_array($type,[config('messagehub.notification.type.TEXT'),config('messagehub.notification.type.INAPPTEXT')])){
            foreach($employeeData as &$employee){
                $employee['phone_number'] = !empty($employee['phone_number'])?decrypt($employee['phone_number']):'';
            }
        }
        return $employeeData;
    }

    /**
     * return Get the Count of employees based on given employer array.
     *
     * @param Array $employers, for which we need to get data
     * @return int $EmployeesCount
     */
    public function getEmployeeCount($type, $employers, $filterTemplate = '')
    {
        $employeeData = [];
        if(!is_array($employers)){
            $employers = [$employers];
        }

        extract($this->getFilterData($filterTemplate));

        $query = User::join('employeedetails','employeedetails.user_id','=','users.id')
                    ->where('employeedetails.is_demo_account',0)
                    ->whereIn('users.referer_id',$employers)
                    ->enabled()
                    ->active()
                    ->select('users.id');

        //Get only those users who has downloaded app
        $query->when(in_array($type,[config('messagehub.notification.type.INAPP')]), function ($q) {
            //return $q->getActiveAppUser();
        });

        //Get only those users with mobile number
        $query->when(in_array($type,[config('messagehub.notification.type.TEXT')]), function ($q) use($type) {
            $q->getUserByMobile()->condHasMobile();
        });

        if(!empty($filterData) && !empty($blockData)){
            $query->filter($filterData, $blockData, 'users');
        }

        return $query->count();
    }

    /**return a listing of phone number.
     *
     * @param Array $users
     * @return Array
     */
    public function getPhoneNumberByUser($users)
    {
        return EmployeeDemographic::whereIn('user_id',$users)->select('user_id as id','phone_number')->get()->toArray();
    }

    /*
     * return Get the list of employer based on given broker array.
     *
     * @param Array $brokers
     * @return Array Employers List
     */
    public function getEmployerList($brokers, $selectedEmployers=array(), $emails = array())
    {
        $employerData = [];
        if(!is_array($brokers)){
            $brokers = [$brokers];
        }
        foreach($brokers as $brokerId){
            $query = User::join('employerdetails', 'users.id', '=', 'employerdetails.user_id')
                ->where('users.referer_id', $brokerId)
                ->enabled()
                ->active()
                ->select('users.id','users.company_name','users.email','users.first_name','users.last_name','users.last_login');
            if($this->sms_enabled == true){
                $query->textEnabled();
            }

            if(!empty($selectedEmployers)){
                $query->whereIn('users.id',$selectedEmployers);
            }
            //filter record based on email if provided
            if(!empty($emails)){
                $query->whereIn('users.email',$emails);
            }
            $employerData = array_merge($employerData, $query->get()->toArray());
        }

        return $employerData;
    }

    /*
     *return Get the list of Active Brokers.
     * @param $role
     * @return Array $Brokers List
     */
    public function getBrokerList($role)
    {
        $brokers = [];
        if($role == config('role.ADMIN')){
            $brokers = User::join('brokerdetails','users.id', '=', 'brokerdetails.user_id')
                        ->where('users.is_active', '=', 1)
                        ->select('users.id','users.company_name', 'users.first_name', 'users.last_name', 'users.email', 'users.phone', 'users.mobile', 'users.last_login')->get()->toArray();
        }
        return $brokers;
    }

    /*
     * Get All Scheduled Notifications
     * @param $role
     * @return collection data
     */
    public function getScheduledNotifications($role)
    {
        $employers = $this->getEmployersFilter($role, Auth::user()->id);
        $param = [];
        if(!empty($employers)){
            $param = [[
                    '$match' => [
                        'employers' =>[
                            '$in' => $employers
                        ]
                    ],
                    
                ],[ '$sort'=> ["scheduled_utc_time"=> -1] ]];
        }else{
            $param = [[ '$sort'=> ["scheduled_utc_time"=> -1] ]];
        }

        return NotificationSchedule::raw(function($collection) use ($param)
        {
            return $collection->aggregate(
                $param
            );
        });
    }

    /*
     * Delete Scheduled Notifications
     * @param schedule Id
     * @return
     */
    public function removeScheduledNotifications($id)
    {
        NotificationSchedule::where('_id', $id)->delete();
    }

    /*
     * Update Invoice Status
     * @param int InvoiceId
     * @param string Status
     * @param string Note
     * @return
     */
    public function updateInvoiceStatus($invoiceId, $invoiceStatus, $note=null)
    {
        NotificationInvoice::where('id', $invoiceId)->update(['status' => $invoiceStatus, 'note' => $note]);
    }

    /**
     * @param Int Invoiceid
     * @return Invoice Details
     */
    public function getInvoiceById($id)
    {
        return NotificationInvoice::where('id',$id);
    }

    /**
     * @param $data
     * @return id 
     */
    public function getNotificationsById($invoiceId)
    {
        return NotificationMessageHub::where('invoice_id', $invoiceId);
    }

    /**
     * Get All App list
     *
     * @return Query object
     */
    public function getAppList()
    {
        return DB::table('app');
    }

    /*
     * Get active Brokers belongs to a app
     * @param array appids
     * @return query object
     */
    public function getAppBrokers($app_ids)
    {
        return  User::join('brokerdetails','brokerdetails.user_id','=','users.id')
                        ->where('users.is_active',1)
                        ->whereIn('brokerdetails.assigned_app',$app_ids)
                        ->select('users.id')->pluck('id')->toArray();
    }

    /*
     * Get getMessagesByUser
     * @param int user_id
     * @param timestamp
     * @return query object
     */
    public function getMessagesByUser($user_id, $timestamp)
    {
        $query = NotificationMessageHubPushLog::select(
                    'notifications_message_hub_push_log.id',
                    'notifications_message_hub_push_log.read_status as is_read',
                    'notifications_message_hub.notification_type',
                    'notifications_message_hub.deleted_at as is_delete',
                    'notifications_message_hub.title',
                    'notifications_message_hub.summary',
                    'notifications_message_hub.message',
                    'notifications_message_hub.action_url as url',
                    'notifications_message_hub.thumbnail',
                    'notifications_message_hub.created_at as published_on',
                    'notifications_message_hub.valid_from',
                    'notifications_message_hub.expiry_date'
                )->latest('notifications_message_hub.updated_at');

        return $this->getNotifications($query, $user_id, $timestamp);
    }

    /*
     * Get Scheduled Notifications
     * @param schedule Id
     * @return
     */
    public function getScheduledNotificationById($id)
    {
        return NotificationSchedule::where('_id', $id)->first();
    }

    public function updateScheduledNotification($where, $data)
    {
        try {
            if ($this->thumbnailPath) {
                $data['thumbnail'] = $this->thumbnailPath;
            }
            $notificationSchedule = NotificationSchedule::where($where)->update($data);
            if ($notificationSchedule) {
                $response = ['status_code' => 200, 'message' => 'Schedule updated successfully'];
            } else {
                $response = ['status_code' => 400, 'message' => 'Unable to update schedule'];
            }
        } catch (Exception $e) {
            Log::error($e);
            $response = ['status_code' => 400, 'message' => $e->getMessage()];
        }
        return $response;
    }
}