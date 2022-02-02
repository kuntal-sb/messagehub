<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationMessageHubTextLog extends Model
{
    protected $table = 'notifications_message_hub_text_log';
    protected $primaryKey = 'id';
    protected $fillable = array(
        'employee_id',
        'employer_id',
        'message_id',
        'date_created',
        'date_sent',
        'date_updated',
        'messaging_service_sid',
        'mobile_number',
        'status',
        'sid',
        'sms_type',
        'exception_message',
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
        return $this->belongsTo(\App\Models\User::class,'employee_id','id')->select('users.id','users.company_name','users.username','users.first_name','users.last_name','users.email');
    }

    /*
    * @return BelongsTo
    */
    public function employer()
    {
        return $this->belongsTo(\App\Models\User::class,'employer_id','id');
    }
}
