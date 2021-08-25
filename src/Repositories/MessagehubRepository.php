<?php

namespace Strivebenifits\Messagehub\Repositories;

use App\Http\Repositories\BaseRepository;
use Strivebenifits\Messagehub\Models\NotificationMessageHub;
use Strivebenifits\Messagehub\Models\NotificationMessageHubPushLog;
use Strivebenifits\Messagehub\Models\NotificationInvoice;
use Strivebenifits\Messagehub\Models\NotificationMessageHubTextLog;
use App\Models\User;
use App\Models\EmployeeDemographic;
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

    /**
     * MessagehubRepository constructor.
     * @param NotificationMessageHub $notificationMessage
     * @param Connection $eloquentORM
     */
    public function __construct(NotificationMessageHub $notificationMessage, Connection $eloquentORM)
    {
        parent::__construct($eloquentORM);
        $this->model = $notificationMessage;
    }

    public function setSmsEnabled($value){
        $this->sms_enabled = $value;
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
        });

        $notifications->select(['notifications_message_hub.id','notifications_message_hub.message','notifications_message_hub.notification_type','notifications_message_hub.created_at'])->orderBy('created_at', 'desc');
        return $notifications;
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
        return $this->model::with('pushNotifications','pushNotifications.employee')->find($id);
    }

    /**
     * Prepare data to send push notification
     * @param Array employerArr
     * @return Schedule Push Notification Message
     */
    public function processPushNotification($employerList, $brokerId, $requestData, $thumbnailPath, $transactionId, $type = 'web')
    {
        try {
            if($requestData->send_to == 'send_to_all'){
                $employees = [];
            }else{
                $employees = $requestData->employees;
            }

            //Get the assigned app of the broker who created this employer
            $assigned_app = $this->getAppDetails($brokerId);

            $appIdentifier = $assigned_app->app_identifier;
            $androidApi = $assigned_app->android_api;
            $appStoreTarget = $assigned_app->app_store_target;
            
            //Set file path
            $iosCertificateFile = public_path().$assigned_app->ios_certificate_file;
            $fcmKey = public_path().'/push/'.$assigned_app->fcm_key;

            $message = $this->model->parseMessage($requestData->message);
            $title = ($requestData->title)?$requestData->title:'';

            foreach($employerList as $employerId){
                if(empty($employees)){
                    $employees = $this->getEmployeeList(config('messagehub.notification.type.INAPP'), [$employerId]);
                }

                if(!empty($employees)){
                    $notificationMessageId = $this->model->insertNotificationData(config('messagehub.notification.type.INAPP'), $transactionId, $message, $requestData, $thumbnailPath);
                    foreach($employees as $employee){
                        $this->dispatchPushNotification($employee, $employerId, $notificationMessageId, $message, $iosCertificateFile, $androidApi, $fcmKey, $title, $appStoreTarget);
                    }
                }
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

    //Send Push notification to Queue
    public function dispatchPushNotification($employee, $employerId, $notificationMessageId, $message, $iosCertificateFile, $androidApi, $fcmKey, $title , $appStoreTarget)
    {
        try {
            if(is_array($employee)){
                $employeeId  = $employee['id'];
                $deviceToken = $employee['device_id'];
                $deviceType  = $employee['device_type'];
            }else{
                $employeeId = $employee;
                $device_details = $this->getDevicebyEmployee($employeeId);

                if(!$device_details){
                    return 0;
                }
                $deviceToken = $device_details->device_id;
                $deviceType  = $device_details->device_type;
            }

            //This condition will handle sending multiple push if different users logged in same device.
            if(!in_array($deviceToken, $this->duplicateDevices)){
                $this->duplicateDevices[] = $deviceToken;

                $deviceType = $this->getDeviceType($deviceType);

                $send_data = array('employee_id' => (string) $employeeId, 'employer_id' => (string) $employerId, 'message_id'=> (string) $notificationMessageId,'device_type' => (string) $deviceType,'device_token'=> (string) $deviceToken,'message' => (string) $message,'ios_certificate_file' => (string) $iosCertificateFile,'android_api' => (string) $androidApi,'fcm_key' => $fcmKey,'title' => $title,'app_store_target' => $appStoreTarget );

                $seconds=10+($this->increment*2);
                sendNotifications::dispatch($send_data)->delay($seconds);
                $this->increment ++;
            }
        } catch (Exception $e) {
            Log::error($e);   
        }
    }

    /**
     * Prepare data to send Text notification
     * @param Array request
     * @param Array employerIds
     * @return Schedule TEXT Message
     */
    public function processTxtNotifications($request, $transactionId, $employerIds)
    {
        try {
            foreach ($employerIds as $key => $employerId) {
                $employees = $this->getEmployeeBySentType($request, $employerId);

                //Job to send sms via twilio
                $this->dispatchTextNotification($employees, $employerId, $request, $transactionId);
            }

            $message = 'Your users will receive the SMS shortly!';
            $status_code = 200;
        } catch (Exception $e) {
            Log::error($e);
            $status_code = 400;
            $message = $e->getMessage();
        }
        return ['status_code' => $status_code, 'message' => $message];
    }

    public function getEmployeeBySentType($request, $employerId = '')
    {
        if($request->send_to == 'send_to_all'){
            return $this->getEmployeeList($request->notification_type, $employerId);
        }else{
            return $this->getPhoneNumberByUser($request->employees);
        }
    }

    //Send Text notification to Queue
    public function dispatchTextNotification($employees,$employerId,$requestData,$transactionId)
    {
        try {
            $message = $this->model->parseMessage($requestData->message);
            $title = ($requestData->title)?$requestData->title:'';
            $messageId = $this->model->insertNotificationData(config('messagehub.notification.type.TEXT'), $transactionId, $message, $requestData);

            $smsData = ['employees' => $employees, 'message' => $message, 'employer_id' => $employerId, 'message_id' => $messageId];

            //Send push notifications to queue
            sendSms::dispatch($smsData)->delay(Carbon::now()->addSeconds(10));
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    /**
     * APNS Push Notification service
     * @param 
     * @return array with status
     */
    public function sendApns($url, $app_store_target, $badgeCount, $notificationId, $pushMessage, $cert)
    {
        try{          
            $headers = array(
                "apns-topic: ".$app_store_target,
                "User-Agent: My Sender"
            );
            $http2ch = curl_init();
            curl_setopt($http2ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            $message = '{"aps":{"alert":"'.$pushMessage.'","sound":"default","badge": '.$badgeCount.'},"customData": {"notification_id" : '.$notificationId.'}}';
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
                return [0, 'message' => "Curl failed: " .  curl_error($http2ch)];
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
    public function fcmPush($data, $unreadCount, $notificationId)
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
            $notification -> setNotification($data['title'], $data['message']);
            // Build FCM request payload
            if($data['device_type'] !== 'appNameIOS'){
                $fcmData = new Data();
                $fcmData->setPayload(array('data' => ['unread_count' =>(string) $unreadCount, 'notification_id' =>(string) $notificationId]));
                $client -> build($recipient, $notification, $fcmData);
            }else{
                $client -> build($recipient, $notification);
            }
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
                        ->select('device_id','device_type')
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
    public function generateTransactionId($notificationType)
    {
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
            
            default:
                // code...
                break;
        }

        return  $prefix.date('Ym').'-'.str_pad(++$tmpCounter, 6, '0', STR_PAD_LEFT);
    }

    /**
     * scheduleNotification
     * @param array employerIds
     * @param int brokerId
     * @param Request requestData
     * @param string thumbnailPath
     * @param string notificationType
     * @return  Store Data into Mongodb for queue
     */
    public function scheduleNotification($requestData, $notificationType,  $thumbnailPath = '')
    {
        try {
            $data = $requestData->all();

            if(!empty($requestData->employers)){
                $data['employers'] = [];
                foreach ($requestData->employers as $key => $employer) {
                    $data['employers'][] = base64_decode($employer);
                }
            }
            
            if($requestData->sent_type == 'choose-employer' && empty($data['employers'])){
                extract($this->getBrokerAndEmployerId(Session::get('role')));
                $data['employers'][] = $employerId;
            }
            //$data['employers'] = $employerIds;
            //$data['broker_id'] = $brokerId;
            //$data['employees'] = $employees;
            $data['created_by'] = Auth::user()->id;
            $data['thumbnail'] = $thumbnailPath;
            $data['notification_type'] = $notificationType;

            $data['schedule_time'] = $requestData->get('schedule_time');
            $data['schedule_date'] = date('Y-m-d',strtotime($requestData->get('schedule_date')));

            //Calculate UTC time based on given time and store UTC time in data.
            date_default_timezone_set($requestData->get('timezone'));

            $sid = $data['schedule_date'].' '.$data['schedule_time'];
            $data['scheduled_utc_time'] = gmdate('Y-m-d H:i',strtotime($sid));

            unset($data['brokers']);

            if($schedule_id = $requestData->schedule_id){
                $notificationSchedule = NotificationSchedule::where('_id',$schedule_id)->update($data);
                if($notificationSchedule->id == 200){
                    $response = ['status_code'=> 200,'message'=>'Schedule was updated successfully'];
                }else{
                    $response = ['status_code'=>400,'message'=>'Unable to update schedule'];
                }
            }else{
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

    public function unreadNotificationMessages($user_id, $timestamp) {
        $query = NotificationMessageHubPushLog::where('notifications_message_hub_push_log.read_status', 0)
                ->where('notifications_message_hub.is_delete', 0)
                ->WhereDate('notifications_message_hub.expiry_date', '>=', Carbon::now()->format('Y-m-d'));

        return $this->getNotifications($query, $user_id, $timestamp)->count();
    }

    public function getNotifications($query, $user_id, $timestamp) {
        $query = $query->join('notifications_message_hub','notifications_message_hub.id','=','notifications_message_hub_push_log.message_id')
            ->where('notifications_message_hub_push_log.employee_id', $user_id)
            ->where('notifications_message_hub_push_log.updated_at','>=',$timestamp);

        if( date('Y', strtotime($timestamp)) < date('Y'))  // for timestamp 0
        {
            $query = $query->where('notifications_message_hub.is_delete', 0);
        }
            
        $query = $query->whereDate('notifications_message_hub.valid_from', '<=', Carbon::now()->format('Y-m-d'))
            ->where(function($q) {
                $q->whereDate('notifications_message_hub.expiry_date', '0000-00-00')
                ->orWhereDate('notifications_message_hub.expiry_date', '>=', Carbon::now()->format('Y-m-d'));
            });
        return $query;
    }

    public function insertNotificationLog($data, $message_id)
    {
        $insert_data = array('employee_id' => $data['employee_id'],
                    'employer_id' => $data['employer_id'],
                    'message_id'   => $message_id,
                    'read_status'  => 0,
                    'is_success'   => 0,
                    'exception_message' => '',
                    'created_at'   => Carbon::now(), 
                    'updated_at'   => Carbon::now()
                    );

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
        return DB::table('notifications_message_hub_text_log')
            ->selectRaw(
                "sum(case when STATUS = 'queued' then 1 else 0 end) as queued,sum(case when STATUS = 'sent' then 1 else 0 end) as sent,sum(case when STATUS = 'failed' then 1 else 0 end) as failed,sum(case when STATUS = 'undelivered' then 1 else 0 end) as undelivered,sum(case when STATUS = 'delivered' OR STATUS = 'success' then 1 else 0 end) as delivered"
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
                "sum(case when STATUS = 'sent' then 1 else 0 end) as sent, sum(case when STATUS = 'delivered' then 1 else 0 end) as delivered,sum(case when STATUS = 'open' OR STATUS = 'read' then 1 else 0 end) as open, sum(case when STATUS = 'failed' then 1 else 0 end) as failed"
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
                            ->selectRaw('group_concat(DISTINCT notifications_message_hub.id) as messageids,notifications_message_hub_text_log.employer_id,notifications_message_hub.created_by')
                            ->groupBy('notifications_message_hub_text_log.employer_id')
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
        
        $employers = $this->getEmployersFilter($role, Auth::user()->id);

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
    public function getBrokerAndEmployerId($role)
    {
        switch($role){
            case config('role.ADMIN'):
            case config('role.BROKER'):
                $employer_id = $broker_id = Auth::user()->id;
            break;
            case config('role.EMPLOYER'):
                $employer_id = Auth::user()->id;
                $broker_id = Auth::user()->referer_id;
            break;
            case config('role.HR_ADMIN'):
            case config('role.HR'):
                $employer_id = Auth::user()->referer_id;
                $broker_id = Auth::user()->broker_id;
                break;
            case config('role.CHLOE'):
                $employer_id = Auth::user()->id;
                $broker_id = Auth::user()->id;
            break;
            case config('role.BROKEREMPLOYEE'):
                $employer_id = json_decode($employer_id);
                $broker_id = Auth::user()->referer_id;
            break;
            default:
                $employer_id = (Auth::user())?Auth::user()->id:$u_id;
                $broker_id = User::where('id',$employer_id)->select('referer_id')->first()->referer_id;
            break;
        }
        return ['employerId' => $employer_id, 'brokerId' =>$broker_id];
    }

    public function getBrokerAndEmployerById($u_id)
    {
        $employer_id = $u_id;
        $broker_id = User::where('id',$u_id)->select('referer_id')->first()->referer_id;
        return ['employerId' => $employer_id, 'brokerId' =>$broker_id];
    }

    /**
     * return Get the list of employees based on given employer array.
     *
     * @param Array $employers, for which we need to get data
     * @return Array $selectedEmployees
     */
    public function getEmployeeList($type, $employers, $selectedEmployees=array(), $emails = array())
    {
        $employeeData = [];
        if(!is_array($employers)){
            $employers = [$employers];
        }
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
                return $q->getActiveAppUser()
                                ->addSelect('employee_device_mapping.device_id','employee_device_mapping.device_type');
            });

            //Get only those users with mobile number
            $query->when(in_array($type,[config('messagehub.notification.type.TEXT')]), function ($q) use($type) {
                $q->getUserByMobile()->condHasMobile();
                return $q->addSelect('employee_demographics.phone_number');
            });

            if($type == config('messagehub.notification.type.INAPPTEXT')){
                $query1 = clone $query;
                $query = $query->getUserByMobile()->condHasMobile()->addSelect('employee_demographics.phone_number', DB::raw('null as device_id'), DB::raw('null as device_type'))->get();
                $query1 = $query1->getActiveAppUser()
                                ->addSelect('employee_device_mapping.device_id','employee_device_mapping.device_type', DB::raw('"" as phone_number'))->get();
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
                            '$all' => $employers
                        ]
                    ],
                    
                ]];
        }

        return  NotificationSchedule::raw(function($collection) use ($param)
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
                    'notifications_message_hub.is_delete',
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
}