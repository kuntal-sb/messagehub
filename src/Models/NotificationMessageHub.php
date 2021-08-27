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
        'valid_from',
        'expiry_date',
        'notification_type',
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
     * Insert Record into Notification table
     * @return Return Id of inseted record
     */
    public function insertNotificationData($type, $transactionId, $requestData, $thumbnailPath='')
    {
        $message_details = array(
                    'created_by'    => !empty($requestData['created_by'])?$requestData['created_by']:auth()->user()->id,
                    'created_as'    => !empty($requestData['created_as'])?$requestData['created_as']:getEmployerId(),
                    'transaction_id'=> $transactionId,
                    'message'       => $requestData['message'],
                    'title'         => !empty($requestData['title'])?$requestData['title']:'',
                    'summary'       => !empty($requestData['summary'])?$requestData['summary']:'',
                    'notification_type' => $type,
                    'action_url'    => !empty($requestData['url'])?$requestData['url']:'',
                    'thumbnail'     => $thumbnailPath,
                    'valid_from'    => !empty($requestData['valid_from'])?date('Y-m-d',strtotime($requestData['valid_from'])):Carbon::now(),
                    'expiry_date'   => !empty($requestData['expiry_date'])?date('Y-m-d',strtotime($requestData['expiry_date'])):'',
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
