<?php

namespace Strivebenifits\Messagehub\Repositories;

use App\Http\Repositories\BaseRepository;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Models\PushNotificationLog;
use App\Models\NotificationInvoice;
use App\Models\TwilioWebhooksDetails;
use App\Models\MongoDb\NotificationSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use DB;
use Config;
use Illuminate\Database\Connection;
use App\Jobs\sendNotifications;
use phpFCMSBv1\Client;
use phpFCMSBv1\Notification;
use phpFCMSBv1\Recipient;
use phpFCMSBv1\Data;
use Exception;
use App\Http\Repositories\UsersRepository;
use App\Jobs\sendSms;
use Auth;
use Session;

/**
 * Class NotificationMessageRepository
 * @package App\Http\Repositories
 */
class NotificationMessageRepository extends BaseRepository
{

    /**
     * @var UsersRepository
     */
    private $usersRepository;

    private $increment = 0;
    private $duplicateDevices = [];

    /**
     * NotificationMessageRepository constructor.
     * @param NotificationMessage $notificationMessage
     * @param Connection $eloquentORM
     */
    public function __construct(NotificationMessage $notificationMessage, UsersRepository $usersRepository,Connection $eloquentORM)
    {
        parent::__construct($eloquentORM);
        $this->usersRepository = $usersRepository;
        $this->model = $notificationMessage;
    }

    /**
     * getAllNotifications.
     * @param 
     * @return  Query Collection
     */
    public function getAllNotifications($role = '', $where = '')
    {
        if($role == 'Employer'){
            $notifications = $this->model->where('employer_id',Auth::user()->id)
                                ->select(['id','message','notification_type','created_at'])
                                ->orderBy('created_at', 'desc');
        }else{
            $notifications = $this->model
                                ->select(['id','message','notification_type','created_at'])
                                ->orderBy('created_at', 'desc');
        }
        if(!empty($where)){
            $notifications = $notifications->where($where);
        }
        return $notifications;
    }

    /**
     * getNotificationById.
     * @param user ID
     * @return Collection notificationDetails
     */
    public function getNotificationById($id){
        return $this->model::with('pushNotifications','pushNotifications.user')->find($id);
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
                if($type == 'command'){
                    $employees = array_column($requestData->employees,'id');
                }else{
                    $employees = $requestData->employees;
                }
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
                    $employees = $this->usersRepository->getEmployeeByReferer('in-app', [$employerId]);
                }

                $notificationMessageId = $this->model->insertNotificationData('in-app', $employerId, $transactionId, $message, $requestData, $thumbnailPath);
                foreach($employees as $employee){
                    $this->dispatchPushNotification($employee, $notificationMessageId, $message, $iosCertificateFile, $androidApi, $fcmKey, $title, $appStoreTarget);
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
    public function dispatchPushNotification($employee, $notificationMessageId, $message, $iosCertificateFile, $androidApi, $fcmKey, $title , $appStoreTarget)
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

                $send_data = array('employee_id' => (string) $employeeId,'message_id'=> (string) $notificationMessageId,'device_type' => (string) $deviceType,'device_token'=> (string) $deviceToken,'message' => (string) $message,'ios_certificate_file' => (string) $iosCertificateFile,'android_api' => (string) $androidApi,'fcm_key' => $fcmKey,'title' => $title,'app_store_target' => $appStoreTarget );

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
     * @return Schedule TEXT Message
     */
    public function processTxtNotifications($request, $transactionId)
    {
        try {
            $employerIds = json_decode($request->employer_id);
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
            return $this->usersRepository->getEmployeeByReferer($request->notification_type, $employerId);
        }else{
            return $this->usersRepository->getPhoneNumberByUser($request->employees);
        }
    }

    //Send Text notification to Queue
    public function dispatchTextNotification($employees,$employerId,$requestData,$transactionId)
    {
        try {
            $message = $this->model->parseMessage($requestData->message);
            $title = ($requestData->title)?$requestData->title:'';
            $messageId = $this->model->insertNotificationData('text',$employerId, $transactionId, $message, $requestData);

            $smsData = ['employees' => $employees, 'message' => $message, 'employer_id' => $employerId, 'message_id' => $messageId];

            //Send push notifications to queue
            sendSms::dispatch($smsData)->delay(Carbon::now()->addSeconds(10));
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    //APNS Push notification
    public function sendApns($url,$app_store_target,$badgeCount,$insertedLog,$pushMessage,$cert)
    {
        try{          
            $headers = array(
                "apns-topic: ".$app_store_target,
                "User-Agent: My Sender"
            );
            $http2ch = curl_init();
            curl_setopt($http2ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            $message = '{"aps":{"alert":"'.$pushMessage.'","sound":"default","badge": '.$badgeCount.'},"customData": {"notification_id" : '.$insertedLog.'}}';
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

            // get response
            $status = curl_getinfo($http2ch, CURLINFO_HTTP_CODE);

            return ['status' => $status, 'message' => ""];

        }catch(Exception $e){
            Log::error($e);
            return ['status' => 0, 'message' => $e->getMessage()];
        }
    }

    //FCM Push Notification service
    public function fcmPush($data,$badgeCount,$insertedLog)
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
                //$fcmData = new Data();
                //$fcmData->setData((String)$insertedLog,(String)$badgeCount);
                $client -> build($recipient, $notification);
            }else{
                $client -> build($recipient, $notification);
            }
            $result = $client -> fire();
            $response = ['is_success' => $result ===true?1:0,
                        'exception_message' => $result
                    ];            
        } catch (Exception $e) {
            Log::error($e);
            $response = ['is_success' => 0,
                        'exception_message' => $e->getMessage()
                    ];
        }
        return $response;
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
            case 'in-app':
                    $prefix = 'PN';
                break;
            case 'text':
                    $prefix = 'TXT';
                break;
            case 'in-app-text':
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
     * @param int employerId
     * @param int brokerId
     * @param Request requestData
     * @param string thumbnailPath
     * @param string notificationType
     * @return  Store Data into Mongodb for queue
     */
    public function scheduleNotification($employerId, $brokerId, $requestData, $notificationType,  $thumbnailPath = '')
    {
        try {
            if($requestData->get('send_to') == 'send_to_all'){
                $employees = $this->usersRepository->getEmployeeByReferer($notificationType, json_decode($requestData->get('employer_id')));
            }else{
                $employees = $requestData->get('employees');
            }

            $data = $requestData->all();
            $data['employers'] = $employerId;
            $data['broker_id'] = $brokerId;
            $data['created_by'] = Auth::user()->id;
            $data['employees'] = $employees;
            $data['thumbnail'] = $thumbnailPath;
            $data['notification_type'] = $notificationType;

            if($requestData->get('schedule_time') == '00:00'){
                $data['specific_time'] = 0;
            }else{                
                $data['specific_time'] = 1;
            }
            $data['schedule_time'] = $requestData->get('schedule_time');
            $data['schedule_date'] = date('Y-m-d',strtotime($requestData->get('schedule_date')));

            //Calculate UTC time based on given time and store UTC time in data.
            date_default_timezone_set($requestData->get('timezone'));

            $sid = $data['schedule_date'].' '.$data['schedule_time'];
            $data['scheduled_utc_time'] = gmdate('Y-m-d H:i',strtotime($sid));

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
        $query = PushNotificationLog::where('push_notification_logs.read_status', 0)
                ->where('notification_messages.is_delete', 0)
                ->WhereDate('notification_messages.expiry_date', '>=', Carbon::now()->format('Y-m-d'));

        return $this->getNotifications($query, $user_id, $timestamp)->count();
    }

    public function getNotifications($query, $user_id, $timestamp) {
        $query = $query->join('notification_messages','notification_messages.id','=','push_notification_logs.message_id')
            ->where('push_notification_logs.employee_id', $user_id)
            ->where('push_notification_logs.updated_at','>=',$timestamp);

        if( date('Y', strtotime($timestamp)) < date('Y'))  // for timestamp 0
        {
            $query = $query->where('notification_messages.is_delete', 0);
        }
            
        $query = $query->whereDate('notification_messages.valid_from', '<=', Carbon::now()->format('Y-m-d'))
            ->where(function($q) {
                $q->whereDate('notification_messages.expiry_date', '0000-00-00')
                ->orWhereDate('notification_messages.expiry_date', '>=', Carbon::now()->format('Y-m-d'));
            });
        return $query;
    }

    public function insertNotificationLog($data, $message_id)
    {
        $insert_data = array('employee_id' => $data['employee_id'],
                    'message_id'   => $message_id,
                    'read_status'  => 0,
                    'is_success'   => 0,
                    'exception_message' => '',
                    'created_at'   => Carbon::now(), 
                    'updated_at'   => Carbon::now()
                    );
        $log = PushNotificationLog::create($insert_data);
        return $log->id;
    }

    public function updateNotificationLog($id, $data)
    {
        PushNotificationLog::where('id',$id)->update($data);
    }

    /**
     * @param $messageId
     * @return array 
     */
    public function getTextNotificationLogCount($message_id)
    {
        return DB::table('twilio_webhooks_details')
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
        return DB::table('push_notification_logs')
            ->selectRaw(
                "sum(case when STATUS = 'sent' then 1 else 0 end) as sent, sum(case when STATUS = 'delivered' then 1 else 0 end) as delivered,sum(case when STATUS = 'open' OR STATUS = 'read' then 1 else 0 end) as open, sum(case when STATUS = 'failed' then 1 else 0 end) as failed"
            )
            ->where('message_id', $message_id)->first();
    }

    /**
     * @param $startDate, $endDate
     * @return array 
     */
    public function getRemainingInvoice($startDate, $endDate)
    {
        return $this->model->whereNull('invoice_id')
                                ->whereDate('created_at','>=', $startDate)
                                ->whereDate('created_at','<=', $endDate)
                                ->selectRaw('group_concat(id) as messageids,employer_id,created_by')
                                ->groupBy('employer_id')
                                ->orderBy('created_at', 'desc');
    }

    /**
     * @param $startDate, $endDate
     * @return array 
     */
    public function getUsedCredit($startDate, $endDate)
    {
        $query = $this->getRemainingInvoice($startDate, $endDate)->whereIn('notification_type',['text','in-app-text']);
        switch (Session::get('role')) {
            case 'Employer':
                $query->where('employer_id',Auth::user()->id);
                break;
            
            default:
                // code...
                break;
        }
                            
        return $query;
    }

    /**
     * @param $data
     * @return id 
     */
    public function insertInvoice($data)
    {
        $invoiceId =  NotificationInvoice::create($data);
        return $invoiceId->id;
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
     * @param $messageIds
     * @return collection 
     */
    public function getSmsSent($messageIds)
    {
        return TwilioWebhooksDetails::whereIn('message_id',$messageIds)->select('id','sms_type','status')->where('status','!=','failed')->get();
    }

    /**
     * @param $messageIds
     * @return collection 
     */
    public function getInvoices()
    {
        $query = NotificationInvoice::select('id','user_id','paid_by', 'invoice_no','message_count','amount','tax','discount','start_date','end_date','status');
        switch (Session::get('role')) {
            case 'Employer':
                $query->where('user_id',Auth::user()->id);
                break;
            
            default:
                // code...
                break;
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
}