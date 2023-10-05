<?php

namespace Strivebenifits\Messagehub\Repositories;

use App\Http\Managers\ElasticManager;
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
use App\Http\Repositories\AutomatedNotificationDataRepository;
use App\Mail\NotificationEmail;
use Mail;
use App\Http\Managers\TemplateManager;
use App\Http\Repositories\ElasticRepository;
use App\Http\Repositories\MappedHashtagRepository;
use App\Http\Repositories\MappedUserTagRepository;
//use App\Http\Repositories\MessageMappingRepository;
use App\Jobs\ProcessBulkPushNotification;
use App\Jobs\ProcessBulkEmailNotification;
use App\Jobs\ProcessBulkTextNotification;
use App\Jobs\ProcessBulkEmailNotificationAppNotDownloaded;
use App\Jobs\ProcessBulkTextNotificationAppNotDownloaded;
use App\Jobs\ProcessGamificationRecognitionPointAllocation;
use App\Mail\AppNotDownloadedEmail;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Strivebenifits\Messagehub\Models\PinnedMessages;
use App\Models\UserpostHashtagMapping;
use App\Models\UserpostContentMapping;
use App\Models\NotificationTags;
use App\Models\Roles;
use App\Models\MongoDb\NotificationsMessageHubPushLog as NotificationsMessageHubPushLogMongo;
use \Illuminate\Support\Str;
use App\Models\EmployeeDeviceMapping;
use App\Http\Repositories\EmployeeDetailsRepository;

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
    private $mappedUserTagRepository;

    /**
     * MessagehubRepository constructor.
     * @param NotificationMessageHub $notificationMessage
     * @param Connection $eloquentORM
     * @param TemplateManager $templateManager
     */
    public function __construct(NotificationMessageHub $notificationMessage, 
        Connection $eloquentORM,
        TemplateManager $templateManager, 
        MappedHashtagRepository $mappedHashtagRepository,
        MappedUserTagRepository $mappedUserTagRepository)
    {
        parent::__construct($eloquentORM);
        $this->model = $notificationMessage;
        $this->templateManager = $templateManager;
        $this->mappedHashtagRepository = $mappedHashtagRepository;
        $this->mappedUserTagRepository = $mappedUserTagRepository;
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
        $this->notificationData['filterTemplate'] = !empty($data['send_to'])?($data['send_to'] == 'send_to_filter_list'?$data['filterTemplate']:''):'';


        if(isset($data['id']) && $data['notification_type'] == 'email'){
            $this->notificationData['email_subject'] = $data['title'];
            $this->notificationData['email_body'] = $data['message'];
        }

        $this->notificationData['created_by'] = !empty($data['created_by'])?$data['created_by']:auth()->user()->id;
        $this->notificationData['created_as'] = !empty($data['created_as'])?$data['created_as']:getEmployerId();
        $this->notificationData['logo'] = !empty($data['logo_path'])?$data['logo_path']:'';
        $this->notificationData['thumbnail'] = !empty($data['thumbnail_path'])?$data['thumbnail_path']:'';
        $this->notificationData['target_title'] = !empty($data['target_title'])?$data['target_title']:'';
        $this->notificationData['includeSpouseDependents'] = !empty($data['toggleSpouse'])? true: false;
        $this->notificationData['includeDemoAccounts'] = !empty($data['toggleDemoAccount'])? true: false;
        $this->notificationData['message_id'] = !empty($data['message_id']) && !empty($data['created_from']) && $data['created_from'] == config('messagehub.post_type.global_raffle')? $data['message_id']: null;

        /*if(!empty($this->notificationData['categoryId']) && !empty($this->notificationData['subCategoryId'])) {
            $this->notificationData['includeSpouseDependents'] = true;
        }*/

        $this->notificationData['created_from'] = !empty($data['created_from']) ? $data['created_from'] : 'notification';
        $this->notificationData['timeZoneOffset'] = !empty($data['timeZoneOffset']) ? $data['timeZoneOffset'] : '+00:00';

        if($this->notificationData['created_from'] == 'user_post'){
            $this->notificationData['includeSpouseDependents'] = true;
        }

        if(!empty($data['thumbnail_path'])){
            $this->setThumbnailPath($data['thumbnail_path']);
        }

        $this->notificationData['mappedId'] = !empty($data['mappedId']) && !empty($data['created_from']) && $data['created_from'] == config('messagehub.post_type.global_raffle')? $data['mappedId']: null;

        $this->notificationData['deviceType'] = !empty($data['deviceType']) ? $data['deviceType']: '';
        $this->notificationData['user_agent'] = !empty($data['user_agent']) ? $data['user_agent']: '';

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
    /*public function getEmployersFilter($role, $uid=null)
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
            case config('role.BROKEREMPLOYEE'):
                //$employers = [Session::get('employerId')];
            
                //get refererId of logged in user
                $brokerId = User::where('id',$userid)->select('referer_id')->first()->referer_id;
                $employers = array_column($this->getEmployerList($brokerId), 'id');
                break;
            default:
                if(loggedinAsEmployer()){
                    $employers = [getEmployerId()];
                }
                break;
        }
        return $employers;
    }*/

    public function getEmployersFilter($role, $uid=null)
    {
        $employers = [];
        $userid = ($uid)?$uid:Auth::user()->id;
        switch ($role) {
            case config('role.EMPLOYER'):
                $employers = [$userid];
                break;
            case config('role.BROKER'):
            case config('role.BROKEREMPLOYEE'):
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

        return $notifications->select(['notifications_message_hub.id','notifications_message_hub.message','notifications_message_hub.title','notifications_message_hub.notification_type','notifications_message_hub.created_at','notifications_message_hub.filter_value','notifications_message_hub.created_from','notifications_message_hub.created_as'])->orderBy('created_at', 'desc');
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

        return $notifications->select(['notifications_message_hub.id','notifications_message_hub.message','notifications_message_hub.title','notifications_message_hub.notification_type','notifications_message_hub.created_at','notifications_message_hub.filter_value','notifications_message_hub.expiry_date','notifications_message_hub.created_from','notifications_message_hub.created_as','notifications_message_hub.sent_from','notifications_message_hub.logo','notifications_message_hub.target_title','notifications_message_hub.target_screen','notifications_message_hub.action_url','notifications_message_hub.thumbnail','notifications_message_hub.category_id'])->orderBy('created_at', 'desc');
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
            $notifications->addSelect('notifications_message_hub_push_log.employee_id as employee_id','notifications_message_hub_push_log.employer_id as employer_id','notifications_message_hub_push_log.status','notifications_message_hub_push_log.open_status','notifications_message_hub_push_log.engaged_status','notifications_message_hub_push_log.completed_status','notifications_message_hub_push_log.created_at');

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

        return $notifications->join('users','employee_id','=','users.id')->addSelect('users.email');
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
            $data['in-app'] = $this->getChartData($query1, 'in-app');
        }

        if($type == '' || $type=='text')
        {
            $query2 = $this->getAllNotificationsDetails('text', $startDate, $endDate, $employeeIds, $employerIds, $filterTemplate, $searchMessage);
            $data['text'] = $this->getChartData($query2);
        }
        return $data;
    }

    public function getChartData($query, $type=null)
    {
        $query->select(
                    DB::raw("sum(case when STATUS = 'sent' then 1 else 0 end) as sent,sum(case when STATUS = 'delivered' OR STATUS = 'success' then 1 else 0 end) as delivered,sum(case when STATUS = 'undelivered' then 1 else 0 end) as undelivered,sum(case when STATUS = 'failed' then 1 else 0 end) as failed, sum(case when STATUS = 'opened' then 1 else 0 end) as open,sum(case when STATUS = 'read' then 1 else 0 end) as `read`,sum(case when STATUS = 'engaged' then 1 else 0 end) as `engaged`,sum(case when STATUS = 'completed' then 1 else 0 end) as `completed`, DATE_FORMAT(x.created_at, '%Y-%M') as created_at")
                );

        if(!empty($type) && $type == 'in-app'){
            $query = $query->addSelect(
                DB::raw("sum(case when OPEN_STATUS = '1' && ENGAGED_STATUS = '0' && COMPLETED_STATUS ='0' then 1 else 0 end) as openFlagCount,sum(case when ENGAGED_STATUS = '1' && COMPLETED_STATUS ='0' then 1 else 0 end) as engagedFlagCount, sum(case when ENGAGED_STATUS = '1' && COMPLETED_STATUS ='1' then 1 else 0 end) as engagedCompletedFlagCount, sum(case when COMPLETED_STATUS ='1' then 1 else 0 end) as completedFlagCount")
            );
        }
        
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
        return $this->model::with('pushNotifications','textNotifications','pushNotifications.employee', 'pushNotificationsByEmployer', 'pushNotificationsByBroker')->find($id);
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
            $appInstanceIds = [];
            $employeeArr = [];

            if($this->notificationData['send_to'] == 'send_to_all'){
                $employeeList = [];
            }
            else if($this->notificationData['send_to'] == 'send_to_filter_list'){
                $employeeList = [];

                $filterTemplate = $this->notificationData['filterTemplate'];
            }else if($this->notificationData['send_to'] == 'send_to_app_instances'){
                $employeeList = [];

                $appInstanceIds = $this->notificationData['appInstance'];
            }else{
                $employees = $employeeList = $this->notificationData['employees'];
                $employeeArr = $employeeList;
            }

            //$this->setPushInfo($brokerId);

            $chunkEmployerList = array_chunk($employerList, 2);

            foreach($chunkEmployerList as $employerList){
                $batchList = [];
                foreach($employerList as $employerId){
                    if(empty($employeeList)){
                        if((isset($this->notificationData['is_automated_notification']) && $this->notificationData['is_automated_notification'] == 1) || (isset($this->notificationData['is_gamification_reminder']) && $this->notificationData['is_gamification_reminder'] == 1)){
                            //Fetch employee timezone wise
                            $employees = $this->getEmployeeList(config('messagehub.notification.type.INAPP'), [$employerId], [], [], $filterTemplate, $appInstanceIds, $this->notificationData['includeSpouseDependents'],$this->notificationData['employeesPartOfIt'],$this->notificationData['includeDemoAccounts'], $this->notificationData['timezone']);
                            array_push($employeeArr,array_column($employees, 'id'));
                        }else{
                            $employees = $this->getEmployeeList(config('messagehub.notification.type.INAPP'), [$employerId], [], [], $filterTemplate, $appInstanceIds, $this->notificationData['includeSpouseDependents'],[],$this->notificationData['includeDemoAccounts']);
                            array_push($employeeArr,array_column($employees, 'id'));
                        }
                    }
                    $batchList[] = new ProcessBulkPushNotification($brokerId, $employerId, $employees, $this->notificationData, $employeeArr);
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
    public function dispatchPushNotification($employee, $employerId, $employeeArr = [], $employeeDataToElk = false)
    {
        try {
            $deviceToken = $deviceType = $is_flutter = '';
            $userName = '';
            if(is_array($employee)){
                $employeeId  = $employee['id'];
                $deviceToken = $employee['device_id'];
                $deviceType  = $employee['device_type'];
                $is_flutter  = $employee['is_flutter'];
                $userName = $employee['first_name'].' '.$employee['last_name'];
            }else{
                $employeeId = $employee;
                $device_details = $this->getDevicebyEmployee($employeeId);

                if($device_details->isNotEmpty()){
                    $device_details = $device_details->first();
                    $deviceToken = $device_details->device_id;
                    $deviceType  = $device_details->device_type;
                    $is_flutter  = $device_details->is_flutter;
                }
            }

            //code for auto gamification
            $is_gamification_reminder = 0;
            if(isset($this->notificationData['is_gamification_reminder']) && $this->notificationData['is_gamification_reminder'] == 1){
                //$this->notificationData['message'] = str_replace("[USER-NAME]",$userName,$this->notificationData['message']);
                $is_gamification_reminder = 1;
            }

            //code for strive user level
            $striveUserLevel = 0;
            if(!empty($this->notificationData['created_from']) && $this->notificationData['created_from'] == config('messagehub.post_type.global_raffle')){
                $striveUserLevel = 1;
            }

            if($this->isResend || $is_gamification_reminder == 1){
                $messageId = ($this->isResend) ? $this->notificationData['id'] : '';
                $pushMessageId = ($this->isResend) ? $this->resendData['id'] : '';
            }else{
                //$messageId = $this->addNotification($employerId, true, $employeeArr);

                $messageId = $this->addNotification($employerId, true, $employeeArr, $striveUserLevel, $employeeDataToElk);

                if($striveUserLevel && !empty($this->notificationData['message_id'])) {
                    $messageId = $this->notificationData['message_id'];
                }

                if(!empty($this->notificationData['content_id'])){
                    $contentId = $this->notificationData['content_id'];
                    $checkExists = UserpostContentMapping::where(['message_id' => $messageId, 'content_id' => $contentId])->first();
                    if(is_null($checkExists)){
                        UserpostContentMapping::create([
                            'message_id' => $messageId, 
                            'content_id' => $contentId, 
                        ]);
                    }
                }

                if(isset($this->notificationData['userpost_hashtag_id']) && !empty($this->notificationData['userpost_hashtag_id'])){
                    $userpostHashtag = explode(",",$this->notificationData['userpost_hashtag_id']);
                    foreach($userpostHashtag as $hashtag){
                        $checkExists = UserpostHashtagMapping::where(['message_id' => $messageId, 'userpost_hashtag_id' => $hashtag])->first();
                        if(is_null($checkExists)){
                            $hashtagData = [
                                'message_id' => $messageId, 
                                'userpost_hashtag_id' => $hashtag, 
                                'created_at' =>carbon::now()
                            ];
                            UserpostHashtagMapping::insert($hashtagData);
                        }
                    }
                }
                if(isset($this->notificationData['pin_message']) && $this->notificationData['pin_message'] == 1){
                    $checkPin = PinnedMessages::where('message_id',$messageId)->first();
                    if(is_null($checkPin)){
                        $pinnedMessages = PinnedMessages::where('employer_id',$employerId)
                                            ->whereNotNull('pin_at')->orderBy('pin_at','ASC')
                                            ->get();
                        //Check if user pinned more than configured limited messages
                        if(count($pinnedMessages) == Config('constants.LIMIT_PIN_MESSAGE')){
                            PinnedMessages::where('message_id',$pinnedMessages[0]->message_id)->delete();
                        }
                        $pinData = ['message_id' => $messageId,'employer_id' => $employerId,'pin_at' => Carbon::now()];
                        PinnedMessages::insert($pinData);
                    }
                }

                if(isset($this->notificationData['userpost_tags']) && !empty($this->notificationData['userpost_tags'])){
                    $userpostTag = explode(",",$this->notificationData['userpost_tags']);
                    foreach($userpostTag as $hashtag){
                        $checkExists = NotificationTags::where(['notification_id' => $messageId, 'tag_id' => $hashtag])->first();
                        if(is_null($checkExists)){
                            $tagData = [
                                'notification_id' => $messageId,
                                'tag_id' => $hashtag,
                                'created_at' => carbon::now()
                            ];
                            NotificationTags::insert($tagData);
                        }
                    }
                }
                $pushMessageId = '';
            }
            $pushNotificationData = array();
            $pushNotificationData['message_id'] = (string) $messageId;
            $pushNotificationData['status'] = 400;

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
                    'is_resend' => $this->isResend,
                    'is_gamification_reminder' => $is_gamification_reminder,
                    'status' => 200,
                );

                $seconds=0+($this->increment*2);
                //sendNotifications::dispatch($pushNotificationData)->delay($seconds);
                $this->increment ++;
            }else{//user has no device so make entry into notifications_message_hub_push_log with failed status when not resending message
                if(!$this->isResend && $is_gamification_reminder == 0){
                    $exceptionMessage = 'App not Downloaded';
                    $messageStatus = 'App Not Downloaded';

                    $employeeDetailsRepository = app()->make(EmployeeDetailsRepository::class);
                    $employeeDetailData = $employeeDetailsRepository->first(['user_id' => $employeeId],['id','is_app_downloaded']);

                    if($employeeDetailData && $employeeDetailData->is_app_downloaded == 1){
                        $exceptionMessage = 'Device not Found';
                        $messageStatus = 'failed';
                    }

                    $send_data = array('employee_id' => (string) $employeeId, 'employer_id' => (string) $employerId, 'message_id'=> (string) $messageId,'message' => (string) $this->notificationData['message'],'title' => $this->notificationData['title'],'is_flutter' => $is_flutter,'target_screen' => $this->notificationData['target_screen'],'exception_message' => $exceptionMessage);

                    $logID = $this->insertNotificationLog($send_data, $messageId, $messageStatus);

                    Log::info('--------------------------------');
                    Log::info('send_data  '.json_encode($send_data));
                    Log::info('logID '.json_encode($logID));
                    Log::info('deviceToken '.$deviceToken);
                    Log::info('created_from '.$this->notificationData['created_from']);

                    // Send Email/Text message to users who has not downloaded app. message will not send for user post(from mobile)
                    if($deviceToken == '' && (!in_array($this->notificationData['created_from'], ['user_post','recognition_user_post','customised_challenge_post']))){

                        ProcessBulkEmailNotificationAppNotDownloaded::dispatch($employerId,$employeeId, $this->notificationData);
                        //ProcessBulkTextNotificationAppNotDownloaded::dispatch($employerId,$employeeId, $this->notificationData);
                    }else{
                        Log::info('outside -- '. $employeeId);
                    }

                    Log::info('--------------------------------');
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
    public function dispatchTextNotification($employees,$employerId,$appNotDownloaded = false)
    {
        try {
            $notificationMessageId = !$appNotDownloaded ? $this->addNotification($employerId) : null;
            $chunkEmployeeList = array_chunk($employees, 20);

            foreach($chunkEmployeeList as $employeeList){
                $batchList = [];
                foreach($employeeList as $employee){
                    $smsData = ['employee' => $employee,
                            'employer_id' => $employerId,
                            'message' => $this->notificationData['message'],
                            'message_id' => $notificationMessageId,
                            'app_not_downloaded' => $appNotDownloaded
                        ];               
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
            $appInstanceIds = '';

            if($this->notificationData['send_to'] == 'send_to_all'){
                $employeeList = [];
            }
            else if($this->notificationData['send_to'] == 'send_to_filter_list'){
                $employeeList = [];

                $filterTemplate = $this->notificationData['filterTemplate'];
            }else if($this->notificationData['send_to'] == 'send_to_app_instances'){
                $employeeList = [];

                $appInstanceIds = $this->notificationData['appInstance'];
            }else{
                $userRepository = app()->make(UsersRepository::class);
                $employees = $employeeList = $userRepository->getByWhereIn($this->notificationData['employees'], 'id', [], ['id','username','first_name','last_name','email']);
            }

            $chunkEmployerList = array_chunk($employerList, 2);

            foreach($chunkEmployerList as $employerList){
                $batchList = [];
                foreach($employerList as $employerId){
                    if(empty($employeeList)){
                        $employees = $this->getEmployeeList(config('messagehub.notification.type.EMAIL'), [$employerId], [], [], $filterTemplate, $appInstanceIds,$this->notificationData['includeSpouseDependents'],[],$this->notificationData['includeDemoAccounts']);
                    }
                    //Task: https://strive.atlassian.net/browse/BP-3607 16-Aug-2023
                    /*if($this->notificationData['notification_type'] == config('messagehub.notification.type.EMAIL')){
                        foreach($employees as $index => $employee){
                            $checkUnsubscribe = DB::table('unsubscribes')->where('user_id',$employee['id'])
                                                ->where('emailtemplate_id',base64_decode($this->notificationData['email_template']))
                                                ->where('is_unsubscribe','1')->first();
                            if($checkUnsubscribe){
                                unset($employees[$index]);
                            }
                        }
                    }*/
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

            foreach ($employees as $employee) {

                $email_subject = $this->notificationData['email_subject'];
                $email_body = $this->notificationData['email_body'];

                if(method_exists($this->templateManager,'mapEmailTemplateKeywords')){
                    $email_subject = $this->templateManager->mapEmailTemplateKeywords($email_subject, $employerId, $employee);
                    $email_body = $this->templateManager->mapEmailTemplateKeywords($email_body, $employerId, $employee);
                }

                $emailData = ['employee' => $employee,
                            'employer_id' => $employerId,
                            'email_subject' => $email_subject,
                            'email_template' => $this->notificationData['email_template'],
                            'email_body' => $email_body,
                            'message_id' => $notificationMessageId,
                            'unsubscribe_flag' => $this->notificationData['unsubscribe_flag']];

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
    public function addNotification($employerId, $mapping = false, $employeeArr = [], $striveUserLevel = false, $employeeDataToElk = false)
    {
        if(!isset($this->notificationIds[$this->notificationType]['messageId'][$employerId])){
            if(!$striveUserLevel) {
                $notificationMessageId = $this->model->insertNotificationData($this->notificationType, $this->transactionId, $this->notificationData, $this->thumbnailPath);

                if (isset($this->notificationData['tags']) && !empty($this->notificationData['tags'])) {
                    $this->insertNotificationMessageHubTagsMappingData($notificationMessageId, $this->notificationData['tags']);
                }
            } else {
                if($striveUserLevel && !empty($this->notificationData['message_id'])) {
                    $notificationMessageId = $this->notificationData['message_id'];
                }
            }
            $this->notificationIds[$this->notificationType]['messageId'][$employerId] = $notificationMessageId;

            if($mapping){
                $employeeId  = !empty($this->notificationData['created_by'])?$this->notificationData['created_by']:auth()->user()->id;
                $this->addMessageMappingData($notificationMessageId, $employerId, $employeeId, $employeeArr, $striveUserLevel, $employeeDataToElk);
            }
        }

        return $this->notificationIds[$this->notificationType]['messageId'][$employerId];
    }

    /**
     * insert notification tag data
     * @param $notificationId
     * @param $tags
     */
    public function insertNotificationMessageHubTagsMappingData($notificationId, $tags)
    {
        $tagMappingArray = [];
        foreach ($tags as $tag) {
            $tagMappingArray[] = [
                'notification_id' => $notificationId,
                'tag_id' => $tag,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ];
        }
        NotificationTags::insert($tagMappingArray);
    }

    /**
     * Add notification if to mapping table
     * @param $notificationMessageId
     * @return MessageId
     */
    public function addMessageMappingData($notificationMessageId, $employerId, $employeeId = Null, $employeeArr = [], $striveUserLevel = false, $employeeDataToElk = false)
    {
        try {
            if(!$striveUserLevel) {
                $mappingDetails = ['new_message_id' => $notificationMessageId,'created_at' => Carbon::now()];
                $mappedId = MessageMapping::insertGetId($mappingDetails);
            } else {
                $mappedId = $this->notificationData['mappedId'];
            }

            //Extract tagged users in message
            $userTagArr = array_unique(extractUserTag($this->notificationData['message']));

            if(!empty($employeeArr)){
                $elasticManager = app()->make(ElasticManager::class);
                $elasticManager->postNotificationToElk($employeeArr, $notificationMessageId, $mappedId, $this->notificationData);
                //$userRepository = app()->make(UsersRepository::class);
                //$loggedAsAdmin  = $userRepository->checkUsersByRole($employeeId, [Roles::ROLE_ADMIN]);

                //Ticket: https://strive.atlassian.net/browse/BP-2654
                //If challenge is created with send_as_community_post checked then prevent its elk notification store to hide multiple bell notifications
                if($this->notificationData['created_from'] != 'customised_challenge_notification' && ($this->notificationType == config('messagehub.notification.type.INAPP') || $this->notificationType == config('messagehub.notification.type.INAPPTEXT'))) {

                    //Ticket: https://strive.atlassian.net/browse/BP-2854 POINT 2
                    //Remove Employee Id who have created post and who are tagged in post
                    foreach($employeeArr as $mainKey=>$valueEmployeeArr){
                        if(!is_array($valueEmployeeArr)) {
                    if (($key = array_search($this->notificationData['created_by'], $employeeArr)) !== false) {
                        unset($employeeArr[$key]);
                            }
                            if(!empty($userTagArr) && in_array($this->notificationData['created_from'], ['user_post','recognition_user_post','customised_challenge_post'])){
                                $employeeArr = array_diff_key($employeeArr, array_flip($userTagArr));
                            }
                            break;
                        }else{
                            if (($key = array_search($this->notificationData['created_by'], $valueEmployeeArr)) !== false) {
                                unset($valueEmployeeArr[$key]);
                                unset($employeeArr[$mainKey]);
                                $employeeArr[$mainKey] = array_values($valueEmployeeArr);
                            }
                            if(!empty($userTagArr) && in_array($this->notificationData['created_from'], ['user_post','recognition_user_post','customised_challenge_post'])){
                                $valueEmployeeArr = array_diff_key($valueEmployeeArr, array_flip($userTagArr));
                                unset($employeeArr[$mainKey]);
                                $employeeArr[$mainKey] = array_values($valueEmployeeArr);
                            }
                        }
                    }

                    $notification_type = config('notification.NOTIFICATION_ELK_TYPE.PRIORITY');
                    if(in_array($this->notificationData['created_from'], ['user_post','recognition_user_post','customised_challenge_post'])){
                        $notification_type = config('notification.NOTIFICATION_ELK_TYPE.GENERAL');
                    }

                    if(!$employeeDataToElk){
                        $elasticManager->postNotificationToElk($employeeArr, $notificationMessageId, $mappedId, $this->notificationData, $employerId, $notification_type);
                    }
                }
            }

            if(!$striveUserLevel) {
                $notificationMessage = $this->model::where(['notifications_message_hub.id' => $notificationMessageId]);
                $notificationMessage->update(['mapped_id' => $mappedId]);
                //Extract rewards and save them
                Log::info("Extract user reward ". $mappedId);
                $usersRewardPoint = extractUserReward($this->notificationData['message']);
                if(!empty($usersRewardPoint)){
                    ProcessGamificationRecognitionPointAllocation::dispatch($usersRewardPoint, $employeeId, $employerId, $mappedId, $this->notificationData);
                }

                //Manage Post Recignition
                if(isset($this->notificationData['reward_receivers']) && !empty($this->notificationData['reward_receivers'])){
                    ProcessGamificationRecognitionPointAllocation::dispatch($this->notificationData['reward_receivers'], $employeeId, $employerId, $mappedId, $this->notificationData);
                }

                //Save related data for Birthday wishes, onbording & Workaniversery
                if(!empty($this->notificationData['created_from']) && in_array($this->notificationData['created_from'], ['birthday_wishes','work_anniversary_wishes','onboarding'])){
                    if(!empty($this->notificationData['automated_employees'])){
                        $automatedEmployeesData = [];
                        $created_at = Date('Y-m-d H:i:s');
                        foreach($this->notificationData['automated_employees'] as $automatedEmployee){
                            $yearCompleted = null;
                            if($this->notificationData['created_from'] == 'work_anniversary_wishes'){
                                $yearCompleted = Date('Y') -  Carbon::createFromFormat('Y-m-d', $automatedEmployee['hire_date'])->format('Y');
                            }
                            $automatedEmployeesData[] = ['message_id' => $notificationMessageId, 'user_id' => $automatedEmployee['id'], 'year_completed' => $yearCompleted, 'type' => $this->notificationData['created_from'], 'created_at' => $created_at];
                        }
                        $automatedNotificationDataRepository = app()->make(AutomatedNotificationDataRepository::class);
                        $automatedNotificationDataRepository->insert($automatedEmployeesData);
                    }
                }

                //Saved tagged users
                if(!empty($userTagArr)){
                    $employeeListTagAll = [];
                    if(in_array(Config::get('constants.MESSAGE_TAG_ALL_USER'), $userTagArr)){
                        $type = 'in-app';
                        $employeeListTagAll = $this->getEmployeeList($type, $employerId,[],[],'',[],$this->notificationData['includeSpouseDependents'],[],$this->notificationData['includeDemoAccounts']);
                    }
                    
                    $pushMessage['title'] = $this->notificationData['title'];
                    $pushMessage['userId'] = $employeeId;
                    $pushMessage['message'] = $this->notificationData['message'];
                    if($this->notificationData['created_from'] == 'user_post') {
                        $notificationMessageData = $notificationMessage->addSelect('notifications_message_hub.title','notifications_message_hub.message','notifications_message_hub.created_from',DB::raw('messagehub_template_subcategories.title as sub_cat_title'))->leftJoin('messagehub_template_subcategories','messagehub_template_subcategories.id','notifications_message_hub.template_subcategory_id')->first();
                        $pushMessage['title'] = $notificationMessageData->sub_cat_title;
                    }
                    $userRepository = app()->make(UsersRepository::class);
                    $userData = $userRepository->first(['id' => $employeeId], ['id','broker_id','referer_id']);
                    $brokerId = getBrokerFromEmployee($userData);

                    $this->mappedUserTagRepository->deviceType = $this->notificationData['deviceType'] ?? '';
                    $this->mappedUserTagRepository->user_agent = $this->notificationData['user_agent'] ?? '';

                    $this->mappedUserTagRepository->manageCommentUsertag($userTagArr, $mappedId, $notificationMessageId, $employeeListTagAll, $employerId, $brokerId, $pushMessage, $this->notificationData['created_from']);
                }

                //Extract hash tag and  save them
                $hashTagArr = extractHashTag($this->notificationData['message']);
                if(!empty($hashTagArr)){
                    $this->mappedHashtagRepository->manageCommentHashtag($hashTagArr, $mappedId);
                }

                //Extract message tag and save them
                $messageTagArr = extractUserMessageTag($this->notificationData['message']);
                if(!empty($messageTagArr)){
                    $messageMappedTagRepository = app()->make(MessageMappedTagRepository::class);
                    $messageMappedTagRepository->manageCommentTag($messageTagArr, $mappedId);
                }
            }
        } catch (Exception $e) {
            Log::error("Message Mapping Log: ".$e);
        }
    }
    public function getEmployeeBySentType($employerId = '')
    {
        if($this->notificationData['send_to'] == 'send_to_all'){
            return $this->getEmployeeList($this->notificationType, $employerId,[],[],'',[], $this->notificationData['includeSpouseDependents'],[], $this->notificationData['includeDemoAccounts']);
        }
        else if(in_array($this->notificationData['send_to'], ['send_to_filter_list'])){
            return $this->getEmployeeList($this->notificationType, $employerId, [], [], $this->notificationData['filterTemplate'],[],$this->notificationData['includeSpouseDependents'],[],$this->notificationData['includeDemoAccounts']);
        }else if(in_array($this->notificationData['send_to'], ['send_to_app_instances'])){
            return $this->getEmployeeList($this->notificationType, $employerId, [], [], $this->notificationData['filterTemplate'], $this->notificationData['appInstance'],$this->notificationData['includeSpouseDependents'],[],$this->notificationData['includeDemoAccounts']);
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
        $deviceTokenVal = $data['device_token'];
        $deviceTokenList = explode(',', $deviceTokenVal);
        $web_success = 0;
        $is_success = 0;
        $deviceTypeList = [];
        foreach ($deviceTokenList as $deviceToken) {
            try {
                $deviceData = EmployeeDeviceMapping::where('device_id', $deviceToken)->first();
                $deviceType = $deviceData->device_type ?? '';

                $client = new Client($data['fcm_key']);
                $recipient = new Recipient();
                // Either Notification or Data (or both) instance should be created
                $notification = new Notification();
                // Recipient could accept individual device token,
                // the name of topic, and conditional statement

                $recipient -> setSingleRecipient($deviceToken);
                // Setup Notificaition title and body

                $dataMessage = Str::limit(processPushNotificationText($data['message']), config('messagehub.payload_message_char_limit'),'....');
                $notification -> setNotification($data['title'], $dataMessage);

                // Build FCM request payload
                //if($data['device_type'] !== 'appNameIOS'){
                    $comment_id = isset($data['comment_id']) ? $data['comment_id'] : 0;
                    $parent_comment_id = isset($data['parent_comment_id']) ? $data['parent_comment_id'] : 0;

                    $fcmData = new Data();

                    $fcmDataArr = [];
                    $fcmDataArr['data'] = ['random_id' => (string) rand(10000000,99999999),'unread_count' =>(string) $unreadCount, 'notification_id' =>(string) $notificationId, 'comment_id' =>(string) $comment_id, 'parent_comment_id' =>(string) $parent_comment_id,  'msg_type' => "new",  'target_screen' => $data['target_screen'],  'target_screen_param' => isset($data['target_screen_param'])?$data['target_screen_param']:''];

                    $fcmDataArr['apns'] = ['payload' => ['aps'=>['badge'=>$unreadCount,'contentAvailable' => true]]];

                    $fcmDataArr['data']['comment_type'] = $comment_type;

                    Log::info("FCM DATA: ".json_encode($fcmDataArr)."  Message: ".$data['message']);

                    $fcmData->setPayload($fcmDataArr);
                    $client -> build($recipient, $notification, $fcmData);
                    array_push($deviceTypeList, $deviceType);
                // }else{
                //     $client -> build($recipient, $notification);
                // }
                if(isset($data['is_gamification_reminder']) && $data['is_gamification_reminder'] == 1 && !config('notification.PUSH_GAMIFICATION_REMINDER')){
                    $message = '-- Added To Log Only --';

                    if($deviceType == 'web') {
                        $web_success = 1;
                    } else {
                        $is_success = 1;
                    }
                }else{
                    $message = $client -> fire();
                    if($message ===true) {
                        if($deviceType == 'web') {
                            $web_success = 1;
                        } else {
                            $is_success = 1;
                        }
                    }

                }
            } catch (Exception $e) {
                Log::error($e);
                $is_success = 0;
                $message = $e->getMessage();
                $web_success = 0;
            }
        }

        return ['is_success' => $is_success, 'exception_message' => $message, 'web_success' => $web_success, 'device_type_list' => $deviceTypeList];
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
                        ->select(DB::raw('GROUP_CONCAT(device_id) AS device_id'),'device_type','is_flutter')
                        ->where('employee_id','=',$employeeId)
                        ->whereNotNull('device_type')
                        ->where('device_type','!=','')
                        ->orderBy('updated_at','DESC')
                        ->groupBy('employee_id')
                        ->get();
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
            $data['logo'] = $this->notificationData['logo'];
            $data['notification_type'] = $this->notificationType;
            unset($data['logo_path']);
            unset($data['thumbnail_path']);

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
    public function prepareRecurringEventData(&$eventData, $timezone = '')
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
            $eventData['next_at'] = nextEventOccurance($eventData, $eventData['schedule_datetime'], true);
            if(isset($eventData['schedule_end_datetime']) && $eventData['schedule_end_datetime'] != null && strtotime($eventData['next_at']) > strtotime($eventData['schedule_end_datetime'])){
                $eventData['next_at'] = '';
                $eventData['next_scheduled_utc_time'] = '';
            }else{
                if($timezone){
                    $eventData['next_scheduled_utc_time'] = convertToUtc($timezone, $eventData['next_at']);
                }else{
                    $eventData['next_scheduled_utc_time'] = convertToUtc($this->notificationData['timezone'], $eventData['next_at']);
                }
            }
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
            $query->where('notifications_message_hub_push_log.updated_at','>=',$timestamp);
        }
            
        return $query->whereDate('notifications_message_hub.valid_from', '<=', Carbon::now()->format('Y-m-d'))
            ->where(function($q) {
                $q->WhereDate('notifications_message_hub.expiry_date', '>=', Carbon::now()->format('Y-m-d'));
                $q->orwhereDate('notifications_message_hub.expiry_date','=', '0000-00-00');
            });
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
            $query->where('push_notification_logs.updated_at','>=',$timestamp);
        }

        return $query->whereDate('notification_messages.valid_from', '<=', Carbon::now()->format('Y-m-d'))
            ->where(function($q) {
                $q->WhereDate('notification_messages.expiry_date', '>=', Carbon::now()->format('Y-m-d'));
                $q->orWhereDate('notification_messages.expiry_date','=', '0000-00-00 00:00:00');
            });
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
            if($messageStatus == 'read'){
                $insert_data['read_status'] = 1;
                $insert_data['open_status'] = 1;
                $insert_data['completed_status'] = 1;
            }
        }

        $log = NotificationMessageHubPushLog::create($insert_data);

        //Insert push log data into mongodbb collection notificationsMessageHubPushLog
        $levelPushData = new NotificationsMessageHubPushLogMongo();
        $levelPushData->employee_id = $insert_data['employee_id'];
        $levelPushData->employer_id = $insert_data['employer_id'];
        $levelPushData->message_id = $insert_data['message_id'];
        $levelPushData->read_status = $insert_data['read_status'];
        $levelPushData->open_status = $insert_data['open_status'] ?? 0;
        $levelPushData->completed_status = $insert_data['completed_status'] ?? 0;
        $levelPushData->status = !empty($messageStatus) ? $messageStatus : 'sent';
        $levelPushData->is_success = $insert_data['is_success'];
        $levelPushData->exception_message = $insert_data['exception_message'];
        $levelPushData->save();
        // return $levelPushData->id;
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
    public function getPushNotificationLogCount($message_id, $striveUserNotification = false)
    {
        $query = DB::table('notifications_message_hub_push_log')
            ->join('notifications_message_hub','notifications_message_hub_push_log.message_id','=','notifications_message_hub.id')
            ->selectRaw("COUNT(notifications_message_hub_push_log.id) AS `sent`, 
COUNT(notifications_message_hub_push_log.id) AS `delivered`,  
sum(case when `status` = 'app not downloaded' OR ( is_success = 0 AND exception_message = 'App not Downloaded' AND `status` != 'app not downloaded') then 1 else 0 END) AS `app_not_downloaded`,
sum(case when `status` = 'failed' OR (is_success = 0 AND `status` not IN ('app not downloaded','failed') AND exception_message != 'App not Downloaded') then 1 else 0 END) AS `failed`,
SUM(case when notifications_message_hub_push_log.is_success = 0 AND `status` not IN ('app not downloaded','failed') then 1 else 0 END) AS `later_downloaded`,
SUM(notifications_message_hub_push_log.open_status) AS `viewed`, 
SUM(case when (read_status = 1 AND engaged_status=1) then 1
             when (read_status = 1 AND engaged_status=0) then 1
             when (read_status = 0 AND engaged_status=1) then 1
             ELSE 0
        end) as `engaged`,
  SUM(case when (notifications_message_hub.target_screen != '' OR notifications_message_hub.action_url != '' or created_from in ( 'explore_notifcation','customised_challenge_notification') ) then notifications_message_hub_push_log.completed_status ELSE  (case when (read_status = 1 AND engaged_status=1) then 1
             when (read_status = 1 AND engaged_status=0) then 1
             when (read_status = 0 AND engaged_status=1) then 1
             ELSE 0
        END) END ) AS `completed`");

        /*->selectRaw(
            "sum(case when STATUS = 'sent' then 1 else 0 end) as sent, sum(case when STATUS = 'delivered' then 1 else 0 end) as delivered, sum(case when STATUS = 'opened' then 1 else 0 end) as open, sum(case when STATUS = 'engaged' then 1 else 0 end) as engaged, sum(case when STATUS = 'completed' then 1 else 0 end) as completed, sum(case when STATUS = 'App Not Downloaded' then 1 else 0 end) as app_not_downloaded, sum(case when STATUS = 'read' then 1 else 0 end) as `read`, sum(case when STATUS = 'failed' then 1 else 0 end) as failed"
        );*/

        if ($striveUserNotification) {
            if (Session::get('role') == Roles::ROLE_BROKER) {
                $query->join('users','users.id','=','notifications_message_hub_push_log.employee_id');
                $query->where('users.broker_id', getBrokerId());
            } else {
                $query->where('employer_id', getEmployerId());
            }
        }

        return $query->where('message_id', $message_id)->first();
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
                $employer_id = Session::get('employerId');
                $broker_id = ($refererId)?:Auth::user()->referer_id;
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
    public function getEmployeeList($type, $employers, $selectedEmployees=array(), $emails = array(), $filterTemplate = '', $appInstanceIds = [],$includeSpouseDependents = false, $excludeEmployees = array(), $includeDemoAccounts = false, $userTimezone = null)
    {
        $employeeData = [];
        if(!is_array($employers)){
            $employers = [$employers];
        }

        extract($this->getFilterData($filterTemplate));

        foreach($employers as $employer){
            $query = User::join('employeedetails','employeedetails.user_id','=','users.id')
                        //->where('employeedetails.is_demo_account',0)
                        ->where('users.referer_id','=',$employer)
                        ->enabled()
                        ->active()
                        ->select('users.id','users.username','users.first_name','users.last_name','users.email','users.created_at','users.last_login', 'users.timezone', 'app_instances.name AS app_instance_name', 'app_instances.id AS app_instance_id');

            if(!$includeDemoAccounts){
                $query->where('employeedetails.is_demo_account', 0);
            }

            //filter record based on timezone provided
            if(!empty($userTimezone)) {
                $query->where('users.timezone',$userTimezone);
            }

            if(!empty($selectedEmployees)){
                $query->whereIn('users.id',$selectedEmployees);
            }
            if(!empty($excludeEmployees)){
                $query->whereNotIn('users.id',$excludeEmployees);
            }

            // if(!empty($appInstanceIds)){
            //     $query = $query->join('app_instance_assigned','app_instance_assigned.user_id','=','users.id')->whereIn('app_instance_assigned.app_instance_id', $appInstanceIds);
            // }

            if(!empty($appInstanceIds)){
                if(!$includeSpouseDependents){
                    $query->join('app_instance_assigned','app_instance_assigned.user_id','=','users.id')
                        ->whereIn('app_instance_assigned.app_instance_id', $appInstanceIds);
                } else {
                    $query->where(function($q1) use ($appInstanceIds){
                        $q1->whereIn('users.id', function ($q2) use ($appInstanceIds) {
                            $q2->select('user_id')->from('app_instance_assigned')
                                ->whereIn('app_instance_assigned.app_instance_id', $appInstanceIds);
                            });
                    })->orWhere(function($q1) use ($appInstanceIds){
                        $q1->whereIn('users.id', function ($q2) use ($appInstanceIds) {
                            $q2->select('spouse_id')->from('emplyoee_spouse')
                                ->join('app_instance_assigned','app_instance_assigned.user_id','=','emplyoee_spouse.employee_id')
                                ->whereIn('app_instance_assigned.app_instance_id', $appInstanceIds);
                            });
                    });
                }
                $query->join('app_instances', 'app_instance_assigned.app_instance_id', '=', 'app_instances.id');
            } else {
                //$query->leftJoin('app_instance_assigned','app_instance_assigned.user_id','=','users.id')
                //->join('app_instances', 'app_instance_assigned.app_instance_id', '=', 'app_instances.id');

                if (!$includeSpouseDependents) {
                    $query->join('app_instance_assigned', 'app_instance_assigned.user_id', '=', 'users.id');
                } else {
                    $query->leftJoin('emplyoee_spouse', 'emplyoee_spouse.spouse_id', '=', 'users.id');
                    $query->join('app_instance_assigned', function($join){
                        $join->on('app_instance_assigned.user_id', '=', 'users.id');
                        $join->orOn('app_instance_assigned.user_id', '=', 'emplyoee_spouse.employee_id');
                    });
                }
                $query->join('app_instances', 'app_instance_assigned.app_instance_id', '=', 'app_instances.id');
            }

            //filter record based on email if provided
            if(!empty($emails)){
                $query->whereIn('users.email',$emails);
            }

            if(!$includeSpouseDependents){
                $query->whereNotIn('users.id', function ($q) {
                    $q->select('spouse_id')->from('emplyoee_spouse');
                });
            }

            //Get only those users who has downloaded app
            $query->when(in_array($type,[config('messagehub.notification.type.INAPP')]), function ($q) {
                return $q->leftJoin('employee_device_mapping', 'employee_device_mapping.employee_id', '=', 'users.id')->addSelect(DB::raw('GROUP_CONCAT(employee_device_mapping.device_id) AS device_id'),'employee_device_mapping.device_type','employee_device_mapping.is_flutter')->groupBy('users.id');
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
                $query1 = $query1->leftJoin('employee_device_mapping', 'employee_device_mapping.employee_id', '=', 'users.id')->addSelect(DB::raw('GROUP_CONCAT(employee_device_mapping.device_id) AS device_id'),'employee_device_mapping.device_type','employee_device_mapping.is_flutter', DB::raw('"" as phone_number'))->groupBy('users.id')->get();
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
    public function getEmployeeCount($type, $employers, $filterTemplate = '', $appInstanceIds = [], $includeSpouseDependents = false, $includeDemoAccounts = false)
    {
        $employeeData = [];
        if(!is_array($employers)){
            $employers = [$employers];
        }

        extract($this->getFilterData($filterTemplate));

        $query = User::join('employeedetails','employeedetails.user_id','=','users.id')
                    //->where('employeedetails.is_demo_account',0)
                    ->whereIn('users.referer_id',$employers)
                    ->enabled()
                    ->active()
                    ->select('users.id');

        if(!$includeDemoAccounts){
            $query->where('employeedetails.is_demo_account', 0);
        }


        if (!empty($appInstanceIds)) {
            if(!$includeSpouseDependents){
                $query->join('app_instance_assigned','app_instance_assigned.user_id','=','users.id')
                    ->whereIn('app_instance_assigned.app_instance_id', $appInstanceIds);
            } else {
                $query->where(function($q1) use ($appInstanceIds){
                    $q1->whereIn('users.id', function ($q2) use ($appInstanceIds) {
                        $q2->select('user_id')->from('app_instance_assigned')
                            ->whereIn('app_instance_assigned.app_instance_id', $appInstanceIds);
                        });
                })->orWhere(function($q1) use ($appInstanceIds){
                    $q1->whereIn('users.id', function ($q2) use ($appInstanceIds) {
                        $q2->select('spouse_id')->from('emplyoee_spouse')
                            ->join('app_instance_assigned','app_instance_assigned.user_id','=','emplyoee_spouse.employee_id')
                            ->whereIn('app_instance_assigned.app_instance_id', $appInstanceIds);
                        });
                });
            }
        }

        if(!$includeSpouseDependents){
            $query = $query->whereNotIn('users.id', function ($q) {
                $q->select('spouse_id')->from('emplyoee_spouse');
            });
        }

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
    public function getEmployerList($brokers, $selectedEmployers=array(), $emails = array(), $excludeBlockedEmployer = False, $onlyGamificationEmployer = False, $onlyRecognitionEmployer = False, $onlyRedeemptionEmployer = False)
    {
        $employerData = [];
        if(!is_array($brokers)){
            $brokers = [$brokers];
        }
        foreach($brokers as $brokerId){
            $query = '';
            $query = User::join('employerdetails', 'users.id', '=', 'employerdetails.user_id');
            if(Session::get('role') === config('role.BROKEREMPLOYEE')){ 
                $query->join('broker_employee_cms_mapping', 'users.id','=','broker_employee_cms_mapping.employer_id') 
                    ->where('broker_employee_id','=',Auth::user()->id);
            }

            $query->where('users.referer_id', $brokerId)
                    ->enabled()
                    ->active()
                    ->select('users.id','users.company_name','users.email','users.first_name','users.last_name','users.last_login');

            //Exclude blocekd employees
            if($excludeBlockedEmployer){
                $query->leftJoin('notification_blocked_employer', function($join)
                    {
                        $join->on('users.id', '=', 'notification_blocked_employer.user_id')
                            ->whereNull('notification_blocked_employer.deleted_at');
                    })
                    ->whereNull('notification_blocked_employer.user_id');
            }

            //Include only those employer level enable/disable
            if($onlyRedeemptionEmployer || $onlyRecognitionEmployer || $onlyGamificationEmployer){
                $query->join('gamification_employer_settings','users.id','=','gamification_employer_settings.employer_id')
                ->where(function($query) use($onlyGamificationEmployer, $onlyRecognitionEmployer, $onlyRedeemptionEmployer) {
                        if($onlyGamificationEmployer){
                            return $query->where('gamification_employer_settings.allow_gamification', 1);
                        }
                        if($onlyRecognitionEmployer){
                            return $query->where('gamification_employer_settings.allow_recognition', 1);
                        }
                        if($onlyRedeemptionEmployer){
                            return $query->where('gamification_employer_settings.allow_redeem', 1);
                        }
                    });
            }

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
        if (!empty($employers)) {
            $param = [
                [
                    '$match' => [
                        'employers' => [
                            '$in' => $employers
                        ]
                    ],

                ], [
                    '$addFields' => [
                        "scheduledDateSort" => [
                            '$cond' => [
                                'if' => [
                                    '$eq' => [
                                        '$is_repeated',
                                        "does_not_repeat"
                                    ]
                                ],
                                'then' => '$scheduled_utc_time',
                                'else' => '$next_scheduled_utc_time'
                            ]
                        ],
                    ]
                ],
                ['$sort' => ["status" => -1, "scheduledDateSort" => 1]]
            ];
        } else {
            $param = [
                [
                    '$addFields' => [
                        "scheduledDateSort" => [
                            '$cond' => [
                                'if' => [
                                    '$eq' => [
                                        '$is_repeated',
                                        "does_not_repeat"
                                    ]
                                ],
                                'then' => '$scheduled_utc_time',
                                'else' => '$next_scheduled_utc_time'
                            ]
                        ],
                    ]
                ],
                ['$sort' => ["status" => -1, "scheduledDateSort" => 1]]
            ];
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
            $schedule_datetime =  explode(" ",$data['schedule_datetime']);
            $data['schedule_time'] = $schedule_datetime[1]." ".$schedule_datetime[2];
            $data['schedule_date'] = date('Y-m-d',strtotime($schedule_datetime[0]));
            $data['scheduled_utc_time'] = convertToUtc($data['timezone'], $data['schedule_date'].' '.$data['schedule_time']);
            $this->prepareRecurringEventData($data, $data['timezone']);
            if($data['is_repeated'] == 'does_not_repeat'){
                $data['schedule_end_datetime'] = null;
                $data['next_at'] = null;
                $data['next_scheduled_utc_time'] = null;
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

    /*
     * Update notification message
     * @param request
     * @return
     */
    public function updateMessage($request, $thumbnailUrl = ''){
        try{
            $data = [
                'title' => $request->title,
                'message' => (!empty($request->message)) ? $this->model->parseMessage($request->message) : '',
                'expiry_date' => ($request->expiry_date !== '') ? date('Y-m-d', strtotime($request->expiry_date)) : '',
                'sent_from' => !empty($request->sent_from) ? $request->sent_from : 'HR Team',
                'logo' => !empty($request->logo) ? $request->logo : '',
                'target_title' => !empty($request->target_title) ? $request->target_title : '',
                'target_screen' => !empty($request->target_screen) ? $request->target_screen : 'oehub',
                'target_screen_param' => !empty($request->target_screen_param) ? $request->target_screen_param : '',
                'action_url' => !empty($request->url) ? $request->url : '',
                'category_id' => !empty($request->post_category_id) ? $request->post_category_id : 0,
                'logo' => $thumbnailUrl,
                'thumbnail' => !empty($request->thumbnail) ? $request->thumbnail : '',
            ];
            $id = base64_decode($request->id);
            NotificationMessageHub::where('id',$id)->update($data);

            if(isset($request['tags']) && !empty($request['tags'])){
                NotificationTags::where('notification_id', $id)->delete();
                $tagData = [];
                foreach($request['tags'] as $hashtag){
                    $tagData[] = [
                        'notification_id' => $id,
                        'tag_id' => $hashtag,
                        'created_at' => carbon::now()
                    ];
                }
                NotificationTags::insert($tagData);
            }
            $elkRepository = app()->make(ElasticRepository::class);
            $elasticManager = app()->make(ElasticManager::class);
            $recordId = $elkRepository->getDocumentId(config('analytics.strive_global_connect'), ['message_id' => $id]);
            if($request->expiry_date){
               $data['expiry_date'] = date('Y-m-d', strtotime($request->expiry_date)) . 'T' . date('H:i:s', strtotime($request->expiry_date)) . '.000Z';
            }else{
                $data['expiry_date'] = date('Y-m-d', strtotime('+5 years')) . 'T' . date('H:i:s') . '.000Z';
            }

            $data['updated_at'] = date('Y-m-d') . 'T' . date('H:i:s') . '.000Z';

            if(!empty($recordId)) {
                $elasticManager->updateElkDocByParams(config('analytics.strive_global_connect'), $recordId, $data);
            }
            return $response = ['status_code' => 200,'message' => 'Message updated successfully'];
        }
        catch (Exception $e) {
            Log::error($e);
            return $response = ['status_code' => 400, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send Email notification to users who has not downloaded app
     * @param
     * @return
     */
    public function dispatchEmailNotificationForAppNotDownloaded($employees, $emailData)
    {
        try {
            Log::info('EmailNotificationForAppNotDownloaded--');
            Log::info('employees--'. json_encode($employees));

            $message = (new AppNotDownloadedEmail($emailData))->onQueue('email_queue');
            Mail::to($employees['email'])->queue($message);
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    /**
     * get selected filtertemplate name by using its id
     * @param id
     * @return 
     **/
    public function getFilterTemplateName($filterTemplateId)
    {
        try {
            if (!empty($filterTemplateId)) {
                return DB::table('filter_template')
                            ->where('id',$filterTemplateId)
                            ->select('name')
                            ->first();
            }
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    /**
     * get selected appinstances name by using its id
     * @param id
     * @return 
     **/
    public function getFilterAppName($appId)
    {
        try {
            if(!empty($appId)) {
                return DB::table('app_instances')
                            ->whereIn('id', $appId)
                            ->pluck('name')->toArray();
            }
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    /**
     * getNotificationsWithSubCategory.
     * @param 
     * @return  Query Collection
     */
    public function getNotificationsWithSubCategory($messageId)
    {
        $notifications = $this->model;

        return $notifications->select('notifications_message_hub.message','notifications_message_hub.title',DB::raw('messagehub_template_subcategories.title as sub_cat_title'),'notifications_message_hub.created_by','notifications_message_hub.created_from')
            ->leftJoin('messagehub_template_subcategories','messagehub_template_subcategories.id','notifications_message_hub.template_subcategory_id')
            ->where('notifications_message_hub.id', $messageId)
            ->first();
    }

    /**
     * get Notification userAction based on flag
     *
     * @param $notification
     * @param $status
     * @return collection modal
     */
    public function getNotificationUserActionBasedOnFlag($notification, $status)
    {

        if($notification->created_from == config('messagehub.post_type.global_raffle')) {
            if(getEmployerId()) {
                $userActionDetails = $notification->pushNotificationsByEmployer();
            } else {
                $userActionDetails = $notification->pushNotificationsByBroker();
            }
        } else {
            $userActionDetails = $notification->pushNotifications();
        }

        if($status == "opened"){
            $userActionDetails->where([['open_status', 1],['engaged_status', 0],['completed_status',0],['read_status',0]]);
        }
        if($status == "engaged"){
            $userActionDetails->where([['engaged_status',1],['completed_status',0]]);
        }
        if($status == "completed"){
            $userActionDetails->where('completed_status',1);
        }
        if($status == "read"){
            $userActionDetails->where([['read_status',1],['engaged_status',0],['completed_status',0]]);
        }
        if($status == "engagedCompleted"){
            $userActionDetails->where([['engaged_status',1],['completed_status',1]]);
        }
        return $userActionDetails->get();
    }


    /**
     * https://strive.atlassian.net/browse/BP-3252
     * @param $message_id
     * @return object 
     */
    public function getPushNotificationLogCountEmployeeList($message_id, $status, $striveUserNotification = false)
    {
        $query = DB::table('notifications_message_hub_push_log')
            ->join('users', 'users.id', '=', 'notifications_message_hub_push_log.employee_id')
            ->join('notifications_message_hub','notifications_message_hub_push_log.message_id','=','notifications_message_hub.id')
            ->where('message_id', $message_id);

        if($status == 'delivered') {
            //$query->where('notifications_message_hub_push_log.is_success', 1);
        } elseif($status == 'viewed') {
            $query->where('notifications_message_hub_push_log.open_status', 1);
        } elseif($status == 'engaged') {
            $query->where(function($query1){
                $query1->where(function($q){
                    $q->where('notifications_message_hub_push_log.read_status', 1)->where('notifications_message_hub_push_log.engaged_status', 1);
                })->orWhere(function($q){
                    $q->where('notifications_message_hub_push_log.read_status', 1)->where('notifications_message_hub_push_log.engaged_status', 0);
                })->orWhere(function($q){
                    $q->where('notifications_message_hub_push_log.read_status', 0)->where('notifications_message_hub_push_log.engaged_status', 1);
                });
            });
        } elseif($status == 'completed') {
            $query->where(function($query1){
                $query1->where(function($q){
                    $q->where('notifications_message_hub_push_log.completed_status', 1);
                    $q->where(function($q1){
                        $q1->where('notifications_message_hub.target_screen', '!=', '')->orWhere('notifications_message_hub.action_url', '!=', '')
                        ->orWhereIn('created_from', ['explore_notifcation','customised_challenge_notification']);
                    });
                })->orWhere(function($q){
                    $q->where('notifications_message_hub_push_log.read_status', 1)->where('notifications_message_hub_push_log.engaged_status', 1)
                    ->where('notifications_message_hub.target_screen', '')->where('notifications_message_hub.action_url', '');
                })->orWhere(function($q){
                    $q->where('notifications_message_hub_push_log.read_status', 1)->where('notifications_message_hub_push_log.engaged_status', 0)
                    ->where('notifications_message_hub.target_screen', '')->where('notifications_message_hub.action_url', '');
                })->orWhere(function($q){
                    $q->where('notifications_message_hub_push_log.read_status', 0)->where('notifications_message_hub_push_log.engaged_status', 1)
                    ->where('notifications_message_hub.target_screen', '')->where('notifications_message_hub.action_url', '');
                });
            });
        }

        if ($striveUserNotification) {
            if (Session::get('role') == Roles::ROLE_BROKER) {
                $query->where('users.broker_id', getBrokerId());
            } else {
                $query->where('employer_id', getEmployerId());
            }
        }

        return $query->select(DB::raw("CONCAT(users.first_name, ' ', users.last_name) AS fullName"), 'users.email','notifications_message_hub.created_from')->get();
    }

    public function setNotificationIds($Ids){
        $this->notificationIds = $Ids;
    }
}