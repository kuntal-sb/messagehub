<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
        'app_instance_ids',
        'valid_from',
        'expiry_date',
        'notification_type',
        'category_id',
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
     * Get filter template details
     */
    public function filterTemplate()
    {
        return $this->hasOne(\App\Models\FilterTemplate::class,'id','filter_value');
    }

    /**
     * Get details who created notification
     * 
     */
    public function createrDetails()
    {
        return $this->hasOne(\App\Models\User::class,'id','created_as');
    }

    /**
     * @return BelongsTo
     */
    public function postCategory(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Category::class, 'category_id');
    }

    /**
     * @return HasManyThrough
     */
    public function tags(): HasManyThrough
    {
        return $this
            ->hasManyThrough(
                \App\Models\Tag::class,
                \App\Models\NotificationTags::class,
                'notification_id',
                'id',
                'id',
                'tag_id'
            );
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
                    'app_instance_ids'  => $requestData['send_to'] == 'send_to_app_instances'?implode(',',$requestData['appInstance']):'',
                    'valid_from'    => !empty($requestData['valid_from'])?date('Y-m-d',strtotime($requestData['valid_from'])):Carbon::now(),
                    'expiry_date'   => !empty($requestData['expiry_date'])?date('Y-m-d H:i:s',strtotime($requestData['expiry_date'])):'',
                    'target_screen'    => isset($requestData['target_screen'])?$requestData['target_screen']:'oehub',
                    'target_screen_param'    => isset($requestData['target_screen_param'])?$requestData['target_screen_param']:'',
                    'allow_sharing' => isset($requestData['allow_sharing'])?$requestData['allow_sharing']:0,
                    'sent_from'     => !empty($requestData['sent_from'])?$requestData['sent_from']:'HR Team',
                    'logo'          => !empty($requestData['logo'])?$requestData['logo']:'',
                    'template_category_id'    => (isset($requestData['categoryId']))?$requestData['categoryId']:0,
                    'category_id'    => (isset($requestData['post_category_id']))?$requestData['post_category_id']:0,
                    'template_subcategory_id' => (isset($requestData['subCategoryId']))?$requestData['subCategoryId']:0,
                    'userpost_learn_more' => (isset($requestData['allow_learn_more']))?$requestData['allow_learn_more']:0,
                    'created_from' => (isset($requestData['created_from']))?$requestData['created_from']:'notification',
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
        $message = str_replace("&nbsp;"," ",$message);
        return trim(strip_tags($message,'<user-tag><user-reward>'));
    }
}