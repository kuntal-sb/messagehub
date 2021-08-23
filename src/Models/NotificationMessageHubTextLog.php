<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationMessageHubTextLog extends Model
{
    protected $table = 'notifications_message_hub_text_log';
    protected $primaryKey = 'id';
    protected $fillable = array(
        'employee_id',
        'employer_id',14
        'message_id',
        'date_created',
        'date_sent',
        'date_updated',
        'messaging_service_sid',
        'mobile_number',
        'status',
        'sid',
        'sms_type',
        'created_by'
    );

    public function creator() {
        return $this->belongsTo(\App\Models\User::class,'created_by','id');
    }
    
    /*
    * @return BelongsTo
    */
    public function employee()
    {
        return $this->belongsTo(\App\Models\User::class,'employee_id','id');
    }

    /*
    * @return BelongsTo
    */
    public function employer()
    {
        return $this->belongsTo(\App\Models\User::class,'employer_id','id');
    }
}
