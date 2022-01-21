<?php

namespace Strivebenifits\Messagehub;

use Strivebenifits\Messagehub\Repositories\MessagehubRepository;
use Exception;
use Log;
use Validator;
use App\Http\Services\S3Service;
use Carbon\Carbon;
use Session;
use App\Jobs\SendLogToElastic;
use Illuminate\Support\Str;

class MessagehubManager
{
    /**
     * @var messagehubRepository
     */
    private $messagehubRepository;
    
    /**
     * NotificationMessageManager constructor.
     * @param messagehubRepository $messagehubRepository
     */
    public function __construct(
        MessagehubRepository $messagehubRepository
    )
    {
        $this->messagehubRepository = $messagehubRepository;
    }

    /**
     * getAllNotificationsByRole.
     * @param $role
     * @return  json data
     */
    public function getAllNotificationsByRole($role, $uid=null)
    {
        try {
            return $this->messagehubRepository->getAllNotificationsByRole($role, $uid);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * getAllNotificationsByMessageIds.
     * @param $notificationids
     * @return  json data
     */
    public function getAllNotificationsByMessageIds($notificationIds)
    {
        try {
            return $this->messagehubRepository->getAllNotificationsByMessageIds($notificationIds);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * getAllNotificationsDetails.
     * @param $request
     * @return  json data
     */
    public function getAllNotificationsDetails($request)
    {
        try {
            extract($this->prepareReportData($request));
            return $this->messagehubRepository->getAllNotificationsDetails($request->notificationType, $startDate, $endDate, $employeeId, $employerId, $request->filterTemplate, isset($request->search_message)?$request->search_message:'');
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * getAllNotificationsChartData.
     * @param $request
     * @return  json data
     */
    public function getAllNotificationsChartData($request)
    {
        try {
            extract($this->prepareReportData($request));
            return $this->messagehubRepository->getAllNotificationsChartData($request->notificationType, $startDate, $endDate, $employeeId, $employerId, $request->filterTemplate, isset($request->search_message)?$request->search_message:'');
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    public function prepareReportData($request)
    {
        if(!empty($request->daterange)){
            list($startDate,$endDate) = explode('-',$request->daterange);
            $startDate = date('Y-m-d',strtotime(trim($startDate)));
            $endDate = date('Y-m-d',strtotime(trim($endDate)));
        }
        $startDate = $endDate = '';
        $employeeId = base64_decode($request->employee_id);
        $employerId = $request->employer_id;
        $brokerId = $request->broker_id;

        if(!empty($employerId)){
            $employerId = [base64_decode($employerId)];
        }else{
            if(!empty($brokerId)){
                $employerId = array_column($this->messagehubRepository->getEmployerList(base64_decode($brokerId)), 'id');
            }else{
                $employerId = $this->messagehubRepository->getEmployersFilter(Session::get('role'), auth()->user()->id);
            }
        }

        return [ 'startDate' => $startDate, 'endDate' => $endDate, 'employeeId' => $employeeId, 'employerId' => $employerId, 'brokerId' => $brokerId];
    }

    /**
     * getNotificationById.
     * @param user ID
     * @return Collection notificationDetails
     */
    public function getNotificationById($id)
    {
        return $this->messagehubRepository->getNotificationById($id);
    }

    public function generateTransactionId($notificationType = null)
    {
        return $this->messagehubRepository->generateTransactionId($notificationType);
    }

    public function setNotificationType($notificationType)
    {
        return $this->messagehubRepository->setNotificationType($notificationType);
    }

    public function setNotificationData($data)
    {
        return $this->messagehubRepository->setNotificationData($data);
    }

    /**
     * processNotifications.
     * @param array $employerIds
     * @param int $brokerId
     * @return array [status code, message]
     */
    public function processNotifications($employerIds, $brokerId = null)
    {
        $notificationType = $this->messagehubRepository->getNotificationType();
        //TEXT Message
        if(in_array($notificationType, [config('messagehub.notification.type.TEXT'), config('messagehub.notification.type.INAPPTEXT')])){
            Log::info('---TXT Notification---');
            extract($this->messagehubRepository->processTxtNotifications($employerIds));
        }
        //Push Notification
        if(in_array($notificationType, [config('messagehub.notification.type.INAPP'), config('messagehub.notification.type.INAPPTEXT')])){
            Log::info('---Push Notification---');
            extract($this->messagehubRepository->processPushNotification($employerIds,$brokerId));
        }

        if(in_array($notificationType, [config('messagehub.notification.type.EMAIL')])){
            extract($this->messagehubRepository->processEmailNotifications($employerIds));
        }
        return ['status_code' => $status_code, 'message' => $message];
    }

    /*
     * scheduleNotification
     * 
     */
    public function scheduleNotification()
    {
        extract($this->messagehubRepository->scheduleNotification());
        return ['status_code' => $status_code, 'message' => $message];
    }

    /*
     * Store ThumbnailImage
     * return thumbnail_path
     */
    public function storeImage($request)
    {
        $status_code = 200;
        $thumbnail_path = '';
        $message = '';

        //Validate the image
        if($request->file('thumbnail')){
            $rules  =   array(
                    'thumbnail' => 'mimes:jpg,jpeg,png',
                );
            $v = Validator::make($request->all(), $rules);
            $input = $request->file('thumbnail');
            $image_info = getimagesize($input);
            $image_width = $image_info[0];
            $image_height = $image_info[1];
            if($image_width > 300 && $image_height > 200){
                $status_code = 400;
                $message = 'Thumbnail should not exceed the mentioned dimensions(W: 300, H: 200)';
            }
            if( $v->passes() ) {
                $name = $input->getClientOriginalName();
                $filePath = 'carriers/' . time().Str::slug($name);
                $s3Service = new S3Service;
                $response = $s3Service->uploadFile($filePath, 's3', $input);
                if($response['status_code'] == 200){
                    $this->messagehubRepository->setThumbnailPath($response['file_url']);
                }
            }else{
                $thumbnail_path = '';
                $status_code = 400;
                $message = 'Please upload valid thumbnail image';
            }
        }
        return ['status_code' => $status_code, 'message' => $message, 'thumbnail_path' => $thumbnail_path];
    }

    /**
     * Get the notification data
     *  @param $id
     * @return collection
     */
    public function getNotificationFormData($request)
    {
        $notification = [];
        if(!empty($request->id)){
            $notification = $this->messagehubRepository->getNotificationById($request->id);
        }

        return $notification;
    }


    /**
     * sendOutNotifications to devices
     * @param array $data
     * @return 
     */
    public function sendOutNotifications($data)
    {
        try{
            $pushMessage = htmlspecialchars(trim(strip_tags($data['message'])));
            $message_id = $data['message_id'];
            Log::info('Message Data : '.json_encode($data));
            $fcm_key = $data['fcm_key'];

            //Get badge count // Add one for the new message
            $unreadCount = $this->unreadNotificationMessages($data['employee_id'],date('Y-m-d', 0)) + $this->unreadOldNotificationMessages($data['employee_id'],date('Y-m-d', 0)) + 1;
            
            $logID = $this->messagehubRepository->insertNotificationLog($data, $message_id);

            //If the user is from flutter app
            if($data['is_flutter'] == 1){
                $fcmPush = $this->messagehubRepository->fcmPush($data,$unreadCount,$logID);
                Log::info(json_encode($fcmPush));
                $is_success = $fcmPush['is_success'];
                $exception_message = $fcmPush['exception_message'];
            }else{
                //Old Logic
                //If the device is ios, first hit APNS.
                if($data['device_type'] === 'appNameIOS'){
                    try{
                        Log::info('--inside apn--');
                        $url = env('APNS_URL').$data['device_token'];

                        $iosPayload = array('badge' => $unreadCount,'custom' => array('customData' => array('notification_id' => $logID)));
                        $app_store_target = $data['app_store_target'];
                        extract($this->messagehubRepository->sendApns($url,$app_store_target,$unreadCount,$logID,$pushMessage,$data['ios_certificate_file']));

                        $is_success = $status==200?1:0;
                        $exception_message = $message;
                    }
                    catch(Exception $e){
                        Log::error(' apn error'.$e->getMessage());
                        //If exception occurred, then hit FCM for the old live apps.
                        $fcmPush = $this->messagehubRepository->fcmPush($data,$unreadCount,$logID);
                        Log::info('--ios fcm push--'.json_encode($fcmPush));
                        $is_success = $fcmPush['is_success'];
                        $exception_message = $fcmPush['exception_message'];
                    }
                }else{//For android hit fcm push notification
                    $fcmPush = $this->messagehubRepository->fcmPush($data,$unreadCount,$logID);
                    Log::info(json_encode($fcmPush));
                    $is_success = $fcmPush['is_success'];
                    $exception_message = $fcmPush['exception_message'];
                }
            }
            
            $update_log_data = array(
                    'is_success' => $is_success,
                    'status' => $is_success==1?'sent':'failed',
                    'exception_message' => $exception_message,
                    'updated_at'=>Carbon::now()
                    );

            $this->messagehubRepository->updateNotificationLog($logID, $update_log_data);
            if($is_success == 1){
                SendLogToElastic::dispatch([
                    "userId" => $data['employee_id'],
                    "screenName" => "MessageHub",
                    "eventName" => "Delivered",
                    "eventType" => "Notification",
                    "notificationId" => $logID
                ])->onQueue('elastic_queue');
            }else{
                SendLogToElastic::dispatch([
                    "userId" => $data['employee_id'],
                    "screenName" => "MessageHub",
                    "eventName" => "Failed",
                    "eventType" => "Notification",
                    "notificationId" => $logID
                ])->onQueue('elastic_queue');
                Log::error('--exception_message--'.$exception_message);
                throw new Exception($exception_message);
            }
        }catch(Exception $e){
            Log::error($e);
        }
    }

    /**
     * getNotificationLogCount.
     * @param user ID
     * @return Collection notificationDetails
     */
    public function getNotificationLogCount($id, $notificationType)
    {   
        $countData = [];
        if(in_array($notificationType, [config('messagehub.notification.type.INAPP'), config('messagehub.notification.type.INAPPTEXT')])){
            $countData['push-notification'] = (array) $this->messagehubRepository->getPushNotificationLogCount($id);
        }
        if(in_array($notificationType, [config('messagehub.notification.type.TEXT'), config('messagehub.notification.type.INAPPTEXT')])){
            $countData['text-notification'] = (array) $this->messagehubRepository->getTextNotificationLogCount($id);
        }
        return $countData;
    }

    /*
     * Generate Invoice  
     * @param date StartDate
     * @param date EndDate
     * @return 
     */
    public function generateRemainingInvoice($startDate, $endDate)
    {
        $notificationLists =  $this->messagehubRepository->getNotGeneratedInvoice($startDate, $endDate)
                            ->whereIn('notification_type',[config('messagehub.notification.type.TEXT'),config('messagehub.notification.type.INAPPTEXT')])->get();

        $lastId = $this->messagehubRepository->getLastId();

        foreach ($notificationLists as $key => $notification) {
            $messageIds = explode(',',$notification->messageids);
            
            //Get Total Message Count for given messageIds
            $message_count =  $this->messagehubRepository->getSentSmsDetails($messageIds)->where('status','!=','failed')->get()->count();
        

            //Calculate Total Cost which needs to be charged to user based on used credit
            $amount = $this->messagehubRepository->getTxtAmount($message_count);

            //generate Invoice no
            $invoiceNo = 'INV-'.date('Y').'-'.str_pad(++$lastId, 6, '0', STR_PAD_LEFT);

            $invoiceToCreate = [
                'user_id' => $notification->created_as,
                'invoice_no' => $invoiceNo,
                'message_count' => $message_count,
                'amount' => $amount,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'type' => 'quaterly',
                'status' => 'pending',
            ];
            
            $invoice =  $this->messagehubRepository->insertInvoice($invoiceToCreate);

            $this->messagehubRepository->updateRecordByIds($messageIds, ['invoice_id' => $invoice->id]);

            event(new \Strivebenifits\Messagehub\Events\InvoiceCreatedBroadcastEvent($invoice->user, $invoice));
        }
    }

    /*
     * Get Text count by given time period
     * @param startDate
     * @param endDate
     * @return int totalCount of Message
     */
    public function getTotalSentTxt($startDate, $endDate)
    {
        $notifications =  $this->messagehubRepository->getNotificationsByText($startDate, $endDate)->get();
        $message_all = 0;
        $message_failed = 0;
        $message_delivered = 0;
        foreach ($notifications as $key => $remainingNotifications) {
            $messageIds = explode(',',$remainingNotifications->messageids);
            $q1 = $this->messagehubRepository->getSentSmsDetails($messageIds);
            $q2 = clone $q1;
            $q3 = clone $q1;
            //Get Total Message Count for given messageIds
            $message_all +=  $q1->get()->count();
            $message_failed +=  $q2->where('status','failed')->get()->count();
            $message_delivered +=  $q3->where('status','!=','failed')->get()->count();
        }
        return ['message_all' => $message_all, 'message_failed' => $message_failed, 'message_delivered' => $message_delivered];
    }

    /*
     * Get Text count by given invoice
     * @param invoiceId
     * @return int totalCount of Message
     */
    public function getTotalSentTxtByInvoice($invoiceId)
    {
        $messageids =  $this->messagehubRepository->getNotificationsById($invoiceId)->pluck('id')->toArray();
        
        $message_all = 0;
        $message_failed = 0;
        $message_delivered = 0;
        
        $q1 = $this->messagehubRepository->getSentSmsDetails($messageids);
        $q2 = clone $q1;
        $q3 = clone $q1;
        //Get Total Message Count for given messageIds
        $message_all +=  $q1->get()->count();
        $message_failed +=  $q2->where('status','failed')->get()->count();
        $message_delivered +=  $q3->where('status','!=','failed')->get()->count();
        
        return ['message_all' => $message_all, 'message_failed' => $message_failed, 'message_delivered' => $message_delivered];
    }

    /*
     * Get ALl Text message Details by given time period
     * @param startDate
     * @param endDate
     * @return collection
     */
    public function getAllSentTextDetails($startDate, $endDate)
    {
        return $this->messagehubRepository->getAllSentTextDetails($startDate, $endDate);
    }

    public function getInvoices()
    {
        return $this->messagehubRepository->getInvoices()->orderBy('notification_invoices.created_at','desc');
    }

    /*
     * Return Broker Id and Employer Id of given employer id from admin/broker  or loggedin user
     *
     */
    public function getBrokerAndEmployerId($role)
    {
        return $this->messagehubRepository->getBrokerAndEmployerId($role);
    }

    public function getBrokerAndEmployerById($employer_id)
    {
        return $this->messagehubRepository->getBrokerAndEmployerById($employer_id);
    }

    public function getEmployeeList($type, $employers, $selectedEmployees=array(), $emails = array(), $filterTemplate = '')
    {
        return $this->messagehubRepository->getEmployeeList($type, $employers, $selectedEmployees, $emails, $filterTemplate);
    }

    public function getEmployeeCount($type, $employers, $filterTemplate = '')
    {
        return $this->messagehubRepository->getEmployeeCount($type, $employers, $filterTemplate);
    }

    public function getEmployerList($brokerList, $selectedEmployers = array(), $sms_enabled = false)
    {
        $this->messagehubRepository->setSmsEnabled($sms_enabled);
        return $this->messagehubRepository->getEmployerList($brokerList, $selectedEmployers);
    }

    public function getBrokerList($role)
    {
        return $this->messagehubRepository->getBrokerList($role);
    }

    /** 
     * processScheduledNotifications (Single Record)
     * Add notification to queue for scheduled ones
     */
    public function processScheduledNotifications($notifications)
    {
        try {
            $this->setNotificationType($notifications->notification_type);
            $this->setNotificationData($notifications->toArray());
            $this->generateTransactionId();
           
            if($notifications->sent_type == 'choose-app' && !empty($notifications->apps)){// If schedule by admin app wise
                $this->processNotificationsByApp($notifications->apps);
            }else{
                extract($this->messagehubRepository->getBrokerAndEmployerById($notifications->employers[0]));
                extract($this->processNotifications($notifications->employers, $brokerId));
            }

            //Remove record from scheduled list
            //$notifications->delete();
            $notifications->status = 'Completed';
            $notifications->save();
            //ActivityLog::getInstance()->createLog($activityMessage);
            Log::info('Launch was scheduled and deleted successfully');
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    /**
     * Process each selected app and send data to prcoess for text/push notifications
     * @param Array apps
     * @return
     */
    public function processNotificationsByApp($apps)
    {
        foreach ($notifications->apps as $key => $appId) {
            $brokerIds = $this->getAppBrokers([$appId]);
            if(empty($brokerIds)){
                continue;
            }
            $employerIds = array_column($this->getEmployerList($brokerIds,[], true), 'id');

            if(empty($employerIds)){
                continue;
            }

            extract($this->processNotifications($employerIds, $brokerIds[0]));
        }
    }

    /*
     * Get All Scheduled Notifications
     * @param $role
     * @return collection data
     */
    public function getScheduledNotifications($role)
    {
        return $this->messagehubRepository->getScheduledNotifications($role);
    }

    /*
     * Delete Scheduled Notifications
     * @param schedule Id
     * @return
     */
    public function removeScheduledNotifications($id)
    {
        $this->messagehubRepository->removeScheduledNotifications($id);
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
        $this->messagehubRepository->updateInvoiceStatus($invoiceId, $invoiceStatus, $note);
    }

    /*
     * get Chargable Amount from toal message
     * @param int messageCount
     * @return Amount
     */
    public function getAmount($messageCount)
    {
        return $this->messagehubRepository->getTxtAmount($messageCount);
    }

    /**
     * @param Int Invoiceid
     * @return Invoice Details
     */
    public function getInvoiceById($id)
    {
        return $this->messagehubRepository->getInvoiceById($id)->first();
    }

    /**
     * Get App list
     * @return Array App List
     */
    public function getAppList()
    {
        return $this->messagehubRepository->getAppList()->get()->toArray();
    }

    /**
     * @param array ids of app
     * @return broker by app
     */
    public function getAppBrokers($ids)
    {
        return  $this->messagehubRepository->getAppBrokers($ids);
    }

    /**
     * @param array ids of app
     * @return broker and employer by app
     */
    public function getAppEmployers($ids)
    {
        $brokerList =  $this->messagehubRepository->getAppBrokers($ids);
        $employerList =  $this->messagehubRepository->getEmployerList($brokerList);
        return ['brokerList' => $brokerList, 'employerList' => $employerList];
    }

    /**
     * unreadNotificationMessages
     * @param int userId
     * @param timestamp
     * @return list
     */
    public function unreadNotificationMessages($userId,$timestamp)
    {
        return $this->messagehubRepository->unreadNotificationMessages($userId, $timestamp);
    }

    /**
     * unreadOldNotificationMessages
     * @param int userId
     * @param timestamp
     * @return list
     */
    public function unreadOldNotificationMessages($userId,$timestamp)
    {
        return $this->messagehubRepository->unreadOldNotificationMessages($userId, $timestamp);
    }


    /**
     * @param int userId
     * @param timestamp
     * @return list
     */
    public function getMessagesByUser($userId,$timestamp)
    {

        $unreadCount = $this->unreadNotificationMessages($userId, 0);
        $pushMessage =  $this->messagehubRepository->getMessagesByUser($userId,$timestamp)->get()->toArray();
        return [
            'status_code'   => count($pushMessage) > 0 ? 200 : 400,
            "unread_count"  => $unreadCount,
            'notifications' => $pushMessage,
            'message'       => count($pushMessage) == 0 ? "No more new messages" : ""
        ];
    }

    /**
     * @param Update push notification status
     * @param int employee_id
     * @param object requestData
     * @return list
     */
    public function updateNotificationLog($employee_id, $requestData)
    {
        $notification_id = $requestData->notification_id;

        if(isset($requestData->read_status)){
            $update_data['read_status'] = $requestData->read_status;
        }
        if(isset($requestData->open_status)){
            $update_data['open_status'] = $requestData->open_status;
        }
        if(isset($requestData->delivered_status)){
            $update_data['delivered_status'] = $requestData->delivered_status;
        }
        if(isset($requestData->status)){
            $update_data['status'] = $requestData->status;
        }

        $update_data['updated_at'] = Carbon::now();

        $where= ['employee_id'=>$employee_id, 'id'=> $notification_id];

        $this->messagehubRepository->updateNotificationLogByParam($where, $update_data);
    }

    /*
     * Delete Notifications
     * @param schedule Id
     * @return
     */
    public function removeNotifications($id)
    {
        $this->messagehubRepository->deleteByParams(['id'=>$id]);
        $where= ['message_id'=> $id];
        $update_data['updated_at'] = Carbon::now();
        $this->messagehubRepository->updateNotificationLogByParam($where, $update_data);
    }
}
