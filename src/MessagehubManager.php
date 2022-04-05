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
use Strivebenifits\Messagehub\Jobs\sendNotifications;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

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
        // Do not change Order
        //Push Notification
        if(in_array($notificationType, [config('messagehub.notification.type.INAPP'), config('messagehub.notification.type.INAPPTEXT')])){
            Log::info('---Push Notification---');
            extract($this->messagehubRepository->processPushNotification($employerIds,$brokerId));
        }

        //TEXT Message
        if(in_array($notificationType, [config('messagehub.notification.type.TEXT'), config('messagehub.notification.type.INAPPTEXT')])){
            Log::info('---TXT Notification---');
            extract($this->messagehubRepository->processTxtNotifications($employerIds));
        }

        if(in_array($notificationType, [config('messagehub.notification.type.EMAIL')])){
            extract($this->messagehubRepository->processEmailNotifications($employerIds));
        }
        return ['status_code' => $status_code, 'message' => $message];
    }

    /**
     * resendNotifications.
     * @param collection $notification
     * @param request
     * @return array [status code, message]
     */
    public function resendNotifications($notification, $request)
    {
        $notificationDetails = [];
        switch ($request->action) {
            case 'failed':
                $notificationDetails = $notification->pushNotifications()->where(['status' => 'failed']);
                break;
            case 'not-opened':
                $notificationDetails = $notification->pushNotifications()->where('status', '!=','failed')->where('open_status', 0)->where('delivered_status', 0);
                break;
            case 'all':
                if($request->type == 'email'){
                    $notificationDetails = $notification->emailNotifications();
                }else{
                $notificationDetails = $notification->pushNotifications();
                }
                break;
            default:
                // code...
                break;
        }

        //Resend Notifications do not make new entry into table
        if(!empty($notification)){
            $this->setNotificationData($notification->toArray());
            $brokerList = [];

            if($notificationDetails->count() > 0){
                $employeeList = [];
                $notificationData = $notificationDetails->orderBy('employer_id')->get();
                $chunkEmployeeList = $notificationData->chunk(20);

                foreach($chunkEmployeeList as $employeeListData){
                    $pushBatchList = [];
                    foreach($employeeListData as $notificationDetail){
                        if(!isset($brokerList[$notificationDetail->employer_id])){
                            extract($this->getBrokerAndEmployerById($notificationDetail->employer_id));
                            $brokerList[$notificationDetail->employer_id] = $brokerId;
                            $this->messagehubRepository->setPushInfo($brokerId);
                        }
                        $this->messagehubRepository->setResendData($notificationDetail);
                        if($request->type != 'email'){
                            $pushNotificationData = $this->messagehubRepository->dispatchPushNotification($notificationDetail->employee_id, $notificationDetail->employer_id);
                            if(!empty($pushNotificationData)){
                                $pushBatchList[] = new sendNotifications($pushNotificationData);
                            }
                        }

                        $employeeList[] = ['id'=> $notificationDetail->employee_id, 'email'=> $notificationDetail->employee->email];
                    }

                    if(!empty($pushBatchList)){
                        Bus::batch([
                            $pushBatchList
                        ])->then(function (Batch $batch) {
                        })->onQueue('push_notification_queue')
                        ->name('push_notification_queue')->dispatch();
                    }
                }

                if($request->type == 'email'){
                    $this->messagehubRepository->dispatchEmailNotification($employeeList, $notificationDetail->employer_id);
                }
                $this->messagehubRepository->updateByParam(['id' => $notification->id],['resend_count' => $notification->resend_count + 1]);
            }
        }
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
    public function storeImage($request, $fieldName)
    {
        $status_code = 200;
        $thumbnail_path = '';
        $message = '';

        //Validate the image
        if($request->file($fieldName)){
            $rules  =   array(
                $fieldName => 'mimes:jpg,jpeg,png',
                );
            $v = Validator::make($request->all(), $rules);
            $input = $request->file($fieldName);
            $image_info = getimagesize($input);
            $image_width = $image_info[0];
            $image_height = $image_info[1];
            if($image_width > 2000){
                $status_code = 400;
                $message = 'Thumbnail should not exceed the mentioned dimensions(W: 2000)';
            }
            if($image_height > 2000){
                $status_code = 400;
                $message = 'Thumbnail should not exceed the mentioned dimensions(H: 2000)';
            }
            if( $v->passes() ) {
                $name = $input->getClientOriginalName();
                $filePath = 'carriers/' . time().preg_replace('/[^A-Za-z0-9\-.]/', '',$name);
                $s3Service = new S3Service;
                $response = $s3Service->uploadFile($filePath, 's3', $input);
                if($response['status_code'] == 200){
                    if($fieldName == "thumbnail"){
                    $this->messagehubRepository->setThumbnailPath($response['file_url']);
                    }
                    $thumbnail_path = $response['file_url'];
                }
            }else{
                $thumbnail_path = '';
                $status_code = 400;
                $message = 'Please upload valid thumbnail image';
            }
        }
        return ['status_code' => $status_code, 'message' => $message, $fieldName.'_path' => $thumbnail_path];
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
            $message_id = $data['message_id'];
            Log::info('Message Data : '.json_encode($data));
            //$fcm_key = $data['fcm_key'];

            //Get badge count // Add one for the new message
            $unreadCount = $this->unreadNotificationMessages($data['employee_id'],date('Y-m-d', 0)) + $this->unreadOldNotificationMessages($data['employee_id'],date('Y-m-d', 0));
            
            if(isset($data['is_resend']) && $data['is_resend'] || (isset($data['isCommentOrReply']) && $data['isCommentOrReply'])){
                $logID  = $data['push_message_id']; 
            }else{
                $logID = $this->messagehubRepository->insertNotificationLog($data, $message_id);
                $unreadCount = $unreadCount + 1;
            }

            $this->sendNotification($data, $logID, $message_id, $unreadCount);
        }catch(Exception $e){
            Log::error($e);
        }
    }

    /**
     * sendNotification to devices
     * @param array $data, variable $logID $unreadCount
     * @return 
     */
    public function sendNotification($data, $logID, $message_id, $unreadCount)
    {
        try {

            $comment_type = isset($data['comment_type']) ? $data['comment_type'] : '';
            
            $pushMessage = htmlspecialchars(trim(processPushNotificationText($data['message'])));

            //If the user is from flutter app
            if(isset($data['is_flutter']) && $data['is_flutter'] == 1){
                $fcmPush = $this->messagehubRepository->fcmPush($data,$unreadCount,$message_id);
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
                        extract($this->messagehubRepository->sendApns($url,$app_store_target,$unreadCount,$message_id,$pushMessage,$data['ios_certificate_file'], $data, $comment_type));

                        $is_success = $status==200?1:0;
                        $exception_message = $message;
                    }
                    catch(Exception $e){
                        Log::error(' apn error'.$e->getMessage());
                        //If exception occurred, then hit FCM for the old live apps.
                    $fcmPush = $this->messagehubRepository->fcmPush($data,$unreadCount,$message_id,$comment_type);
                        Log::info('--ios fcm push--'.json_encode($fcmPush));
                        $is_success = $fcmPush['is_success'];
                        $exception_message = $fcmPush['exception_message'];
                    }
                }else{//For android hit fcm push notification
                $fcmPush = $this->messagehubRepository->fcmPush($data,$unreadCount,$message_id,$comment_type);
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

            Log::error('--exception_message--'.$exception_message);

            if(!(isset($data['isCommentOrReply']) && $data['isCommentOrReply'])){
                $this->messagehubRepository->updateNotificationLog($logID, $update_log_data);
            }

            $elasticData = [
                    "userId" => $data['employee_id'],
                    "screenName" => "MessageHub",
                    "eventName" => "Delivered",
                    "eventType" => "Notification",
                    "resend" => isset($data['is_resend'])?isset($data['is_resend']):false,
                    "notificationId" => $logID
                ];
            if($is_success == 1){
                $elasticData['eventName'] = "Delivered";
            }else{
                $elasticData['eventName'] = "Failed";
            }
            SendLogToElastic::dispatch($elasticData)->onQueue('elastic_queue');

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
    public function getBrokerAndEmployerId($role, $userId = '', $refererId = '', $brokerId = '')
    {
        return $this->messagehubRepository->getBrokerAndEmployerId($role, $userId, $refererId, $brokerId);
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

    public function getEmployerList($brokerList, $selectedEmployers = array(), $sms_enabled = false, $excludeBlockedEmployer = False)
    {
        $this->messagehubRepository->setSmsEnabled($sms_enabled);
        return $this->messagehubRepository->getEmployerList($brokerList, $selectedEmployers, [], $excludeBlockedEmployer);
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
            $notifications->expiry_date = $this->calculateExpiryForScheduledNotification($notifications);
            $this->setNotificationData($notifications->toArray());
            $this->generateTransactionId();

            if (isset($notifications->thumbnail)) {
                $this->messagehubRepository->setThumbnailPath($notifications->thumbnail);
            }

            if($notifications->sent_type == 'choose-app' && !empty($notifications->apps)){// If schedule by admin app wise
                $this->processNotificationsByApp($notifications->apps);
            }else{
                extract($this->messagehubRepository->getBrokerAndEmployerById($notifications->employers[0]));
                extract($this->processNotifications($notifications->employers, $brokerId));
            }

            //Remove record from scheduled list
            //$notifications->delete();

            if($notifications->recurrence == 'does_not_repeat'){
                $notifications->status = 'Completed';
                $notifications->save();
            }else{
            $next_at = nextEventOccurance($notifications->toArray(), $notifications->next_scheduled_utc_time);
                $notifications->next_scheduled_utc_time = date('Y-m-d H:i', strtotime($next_at));

                //store into user timezone format
                $notifications->next_at = dateToTimezone($notifications->timezone, $notifications->next_scheduled_utc_time, 'm/d/Y h:i A');

                if(strtotime($next_at) > strtotime($notifications->schedule_end_datetime)){
                    $notifications->status = 'Completed';
                }else{
                    //change status back to scheduled for next recurring event
                    $notifications->status = 'Scheduled';
                }
                $notifications->save();
            }

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
        foreach ($apps as $key => $appId) {
            $brokerIds = $this->getAppBrokers([$appId]);
            if(empty($brokerIds)){
                continue;
            }
            //$excludeBlockedEmployer = True;
            $employerIds = array_column($this->getEmployerList($brokerIds,[], true, True), 'id');

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
    public function getAppEmployers($ids, $excludeBlockedEmployer = False)
    {
        $brokerList =  $this->messagehubRepository->getAppBrokers($ids);
        $employerList =  $this->messagehubRepository->getEmployerList($brokerList, [], [], $excludeBlockedEmployer);
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

    /**
     * Get the notification data
     *  @param $request 
     * @return collection
     */
    public function getScheduledNotificationFormData($request)
    {
        try{
            $notification = [];
            if (!empty($request->id)) {
                $notification = $this->messagehubRepository->getScheduledNotificationById($request->id);
            }
            return $notification;
        } catch (Exception $e) {
                Log::error($e);
        }
    }

    /**
     * updateScheduledNotification
     *
     * @param  array $requestData
     */
    public function updateScheduledNotification($requestData)
    {
        $where = ['_id' => $requestData['scheduleId']];
        unset($requestData['thumbnail']);

        if(!empty($requestData['logo'])) {
            if(isset($requestData['logo_stored_path'])){
                unset($requestData['logo']);
                $requestData['logo'] = $requestData['logo_stored_path'];
                unset($requestData['logo_stored_path']);
            }
        }else{
            $requestData['logo'] = '';
        }

        extract($this->messagehubRepository->updateScheduledNotification($where, $requestData));
        return ['status_code' => $status_code, 'message' => $message];
    }

    /**
     * calculateExpiryForScheduledNotification (Single Record)
     * Return expiry date for schedule notification
     */
    public function calculateExpiryForScheduledNotification($notifications){
        return calculateExpiry($notifications->toArray(), $notifications->next_scheduled_utc_time);
    }
}