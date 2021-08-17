<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class NotificationMessage extends Model
{
    protected $table = 'notification_messages';

    protected $primaryKey = 'id';

    protected $fillable = [
        'employer_id',
        'created_by',
        'transaction_id',
        'is_delete',
        'resend_count',
        'message',
        'action_url',
        'thumbnail',
        'valid_from',
        'expiry_date',
        'created_at',
        'updated_at',
        'notification_type',
        'title',
        'summary'
    ];


    /**
     * Get the Push Notifications Logs
     */
    public function pushNotifications()
    {
        return $this->hasMany(PushNotificationLog::class,'message_id','id');
    }


    /**
     * Get the Text Notifications Logs
     */
    public function textNotifications()
    {
        return $this->hasMany(TwilioWebhooksDetails::class,'message_id','id');
    }

    /**
     * Insert Record into Notification table
     * @return Return Id of inseted record
     */
    public function insertNotificationData($type, $employerId, $transactionId, $message, $requestData, $thumbnailPath='')
    {
        $message_details = array('employer_id'  => $employerId,
                    'created_by'    => ($requestData->created_by)?$requestData->created_by:auth()->user()->id,
                    'transaction_id'=> $transactionId,
                    'message'       => $message,
                    'title'         => ($requestData->title)?$requestData->title:'',
                    'summary'       => ($requestData->summary)?$requestData->summary:$requestData->message,
                    'notification_type' => $type,
                    'action_url'    => ($requestData->url)?$requestData->url:'',
                    'thumbnail'     => $thumbnailPath,
                    'valid_from'    => ($requestData->valid_from)?date('Y-m-d',strtotime($requestData->valid_from)):Carbon::now(),
                    'expiry_date'   => ($requestData->expiry_date)?date('Y-m-d',strtotime($requestData->expiry_date)):'',
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
