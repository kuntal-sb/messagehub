<?php

namespace Strivebenifits\Messagehub;

use Strivebenifits\Messagehub\Repositories\NotificationMessageRepository;
use Exception;
use Log;
use Validator;
use App\Http\Services\S3Service;
use Carbon\Carbon;

class MessagehubManager
{
	/**
     * @var NotificationMessageRepository
     */
    private $notificationMessageRepository;
	
	/**
     * NotificationMessageManager constructor.
     * @param NotificationMessageRepository $notificationMessageRepository
     */
    public function __construct(
        NotificationMessageRepository $notificationMessageRepository
    )
    {
        $this->notificationMessageRepository = $notificationMessageRepository;
    }

    /**
     * getAllNotifications.
     * @param 
     * @return  json data
     */
    public function getAllNotifications($requestData)
    {
        try {
            return $this->notificationMessageRepository->getAllNotifications();
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * getNotificationById.
     * @param user ID
     * @return Collection notificationDetails
     */
    public function getNotificationById($id)
    {
        return $this->notificationMessageRepository->getNotificationById($id);
    }

    /**
     * evalTxtNotifications.
     * @param Request $request
     * @param int $employerId
     * @param int $brokerId
     * @return array [status code, message]
     */
    public function evalTxtNotifications($request, $employerId, $brokerId)
    {
        try{
            $transactionId = $this->notificationMessageRepository->generateTransactionId($request->notification_type);
            if($request->input('schedule_type') == 'schedule'){
                extract($this->notificationMessageRepository->scheduleNotification($employerId,$brokerId,$request, 'text', ''));
            }else{
                extract($this->notificationMessageRepository->processTxtNotifications($request, $transactionId));
            }
            
        }catch (Exception $e){
            Log::error($e);
            $status_code = 400;
            $message = $e->getMessage();
        }
        return ['status_code' => $status_code, 'message' => $message];
    }

    /**
     * evalPushNotifications.
     * @param Request $request
     * @param int $employerId
     * @param int $brokerId
     * @return array [status code, message]
     */
    public function evalPushNotifications($request, $employerId, $brokerId)
    {
        try {
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
                    return ['status_code' => 400, 'message' => 'Thumbnail should not exceed the mentioned dimensions(W: 300, H: 200)'];
                }
                if( $v->passes() ) {
                    $name = $input->getClientOriginalName();
                    $filePath = 'carriers/' . $name;
                    $s3Service = new S3Service;
                    $response = $s3Service->uploadFile($filePath, 's3', $input);
                    if($response['status_code'] == 200){
                        $thumbnail_path = $response['file_url'];
                    }
                }else{
                    return ['status_code' => 400, 'message' => 'Please upload valid thumbnail image'];
                }
            }else{
                $thumbnail_path = '';
            }

            $transactionId = $this->notificationMessageRepository->generateTransactionId($request->notification_type);

            //Check if the schedule option is selected. If schedule is selected, then store the schedule
            if($request->input('schedule_type') == 'schedule'){
                extract($this->notificationMessageRepository->scheduleNotification($employerId,$brokerId,$request, 'in-app', $thumbnail_path));
            }else{
                if(!is_array($employerId)){
                    $employerId = array($employerId);
                }
                extract($this->notificationMessageRepository->processPushNotification($employerId,$brokerId,$request,$thumbnail_path, $transactionId));
            }
        } catch (Exception $e) {
            Log::error($e);
            $status_code = 400;
            $message = $e->getMessage();
        }
        return ['status_code' => $status_code, 'message' => $message];
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
            $notification = $this->notificationMessageRepository->getNotificationById($request->id);
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

            //Get badge count
            $unreadCount = $this->notificationMessageRepository->unreadNotificationMessages($data['employee_id'],date('Y-m-d', 0));
            $badgeCount = $unreadCount + 1; // Add one for the new message
            
            $logID = $this->notificationMessageRepository->insertNotificationLog($data, $message_id);

            //If the device is ios, first hit APNS.
            if($data['device_type'] == 'appNameIOS'){
                try{
                    $url = env('APNS_URL').$data['device_token'];

                    $iosPayload = array('badge' => $badgeCount,'custom' => array('customData' => array('notification_id' => $logID)));
                    $app_store_target = $data['app_store_target'];
                    extract($this->notificationMessageRepository->sendApns($url,$app_store_target,$badgeCount,$logID,$pushMessage,$data['ios_certificate_file']));
                    if($status == 200){
                        $is_success = 1;
                        $exception_message = '';
                    }else{
                        $is_success = 0;
                        $exception_message = $message;
                    }
                }
                catch(Exception $e){
                    //If exception occurred, then hit FCM for the old live apps.
                    $fcmPush = $this->notificationMessageRepository->fcmPush($data,$badgeCount,$logID);
                    Log::info(json_encode($fcmPush));
                    $is_success = $fcmPush['is_success'];
                    $exception_message = $fcmPush['exception_message'];
                }
            }else{//For android hit fcm push notification
                $fcmPush = $this->notificationMessageRepository->fcmPush($data,$badgeCount,$logID);
                Log::info(json_encode($fcmPush));
                $is_success = $fcmPush['is_success'];
                $exception_message = $fcmPush['exception_message'];
            }
            
            $update_log_data = array(
                    'is_success' => $is_success,
                    'status' => $is_success==1?'sent':'failed',
                    'exception_message' => $exception_message,
                    'updated_at'=>Carbon::now()
                    );

            $this->notificationMessageRepository->updateNotificationLog($logID, $update_log_data);
            if($is_success == 0){
                Log::info($exception_message);
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
        if($notificationType == 'in-app'){
            return (array) $this->notificationMessageRepository->getPushNotificationLogCount($id);
        }else{
            return (array) $this->notificationMessageRepository->getTextNotificationLogCount($id);
        }
    }

    public function generateRemainingInvoice($startDate, $endDate)
    {
        $notifications =  $this->notificationMessageRepository->getRemainingInvoice($startDate, $endDate)
                            ->whereIn('notification_type',['text','in-app-text'])
                            ->get();

        $lastId = $this->notificationMessageRepository->getLastId();
        foreach ($notifications as $key => $remainingNotifications) {
            $messageIds = explode(',',$remainingNotifications->messageids);
            
            //Get Total Message Count for given messageIds
            $message_count =  $this->notificationMessageRepository->getSmsSent($messageIds)->count();
        

            //Calculate Total Cost which needs to be charged to user based on used credit
            $amount = $this->notificationMessageRepository->getTxtAmount($message_count);

            //generate Invoice no
            $invoiceNo = 'INV-'.date('Y').'-'.str_pad(++$lastId, 6, '0', STR_PAD_LEFT);

            $invoiceToCreate = [
                'user_id' => $remainingNotifications->employer_id,
                'invoice_no' => $invoiceNo,
                'message_count' => $message_count,
                'amount' => $amount,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'type' => 'quaterly',
                'status' => 'pending',
            ];
            
            $invoiceId =  $this->notificationMessageRepository->insertInvoice($invoiceToCreate);

            $this->notificationMessageRepository->updateRecordByIds($messageIds, ['invoice_id' => $invoiceId]);            
        }
    }

    public function getUsedCredit($startDate, $endDate)
    {
        $notifications =  $this->notificationMessageRepository->getUsedCredit($startDate, $endDate)->get();
        $message_count = 0;
        foreach ($notifications as $key => $remainingNotifications) {
            $messageIds = explode(',',$remainingNotifications->messageids);
            //Get Total Message Count for given messageIds
            $message_count +=  $this->notificationMessageRepository->getSmsSent($messageIds)->count();
        }
        return $message_count;
    }

    public function getInvoices()
    {
        return $this->notificationMessageRepository->getInvoices()->get();
    }

    public function getRoleNotification($employer_id=null)
    {
        return $this->notificationMessageRepository->getRoleNotification($employer_id);
    }

    public function getEmployeeByReferer($type, $employers, $selectedEmployees=array(), $emails = array())
    {
        return $this->notificationMessageRepository->getEmployeeByReferer($type, $employers, $selectedEmployees, $emails);
    }
}
