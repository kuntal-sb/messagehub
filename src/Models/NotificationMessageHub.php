<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Carbon\Carbon;

class NotificationMessageHub extends Model
{
    use SoftDeletes;

    protected $table = 'notifications_message_hub';

    protected $primaryKey = 'id';

    protected $fillable = [
        'created_by',
        'created_as',
        'transaction_id',
        'resend_count',
        'title',
        'summary',
        'message',
        'action_url',
        'thumbnail',
        'send_type',
        'filter_value',
        'valid_from',
        'expiry_date',
        'notification_type',
        'mapped_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];


    /**
     * Get the Push Notifications Logs
     */
    public function pushNotifications()
    {
        return $this->hasMany(NotificationMessageHubPushLog::class,'message_id','id');
    }


    /**
     * Get the Text Notifications Logs
     */
    public function textNotifications()
    {
        return $this->hasMany(NotificationMessageHubTextLog::class,'message_id','id');
    }

    /**
     * Get the Email Logs
     */
    public function emailNotifications()
    {
        return $this->hasMany(NotificationMessageHubEmailLog::class,'message_id','id');
    }

    /**
     * Insert Record into Notification table
     * @return Return Id of inseted record
     */
    public function insertNotificationData($type, $transactionId, $requestData, $thumbnailPath='')
    {
        if($requestData['notification_type'] == 'email'){
            $requestData['message'] = $requestData['email_body'];
            $requestData['title'] = $requestData['email_subject'];
        }
        $message_details = array(
                    'created_by'    => !empty($requestData['created_by'])?$requestData['created_by']:auth()->user()->id,
                    'created_as'    => !empty($requestData['created_as'])?$requestData['created_as']:getEmployerId(),
                    'transaction_id'=> $transactionId,
                    'message'       => html_entity_decode($requestData['message']),
                    'title'         => !empty($requestData['title'])?$requestData['title']:'',
                    'summary'       => !empty($requestData['summary'])?$requestData['summary']:'',
                    'notification_type' => $type,
                    'action_url'    => !empty($requestData['url'])?$requestData['url']:'',
                    'thumbnail'     => $thumbnailPath,
                    'send_type'     => $requestData['send_to'],
                    'filter_value'  => $requestData['send_to'] == 'send_to_filter_list'?$requestData['filterTemplate']:'',
                    'valid_from'    => !empty($requestData['valid_from'])?date('Y-m-d',strtotime($requestData['valid_from'])):Carbon::now(),
                    'expiry_date'   => !empty($requestData['expiry_date'])?date('Y-m-d',strtotime($requestData['expiry_date'])):'',
                    'target_screen'    => isset($requestData['target_screen'])?$requestData['target_screen']:'oehub',
                    'target_screen_param'    => isset($requestData['target_screen_param'])?$requestData['target_screen']:'',
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now()
                   );
        return $this->insertGetId($message_details);
    }

    /**
     * parseMessage Remove special character and html tag
     * @param string Message
     * @return simple string: message
     */
    public function parseMessage($message)
    {
        return htmlspecialchars(trim(strip_tags($message)));
    }
}
