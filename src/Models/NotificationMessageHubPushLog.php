<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationMessageHubPushLog extends Model
{
    protected $table = 'notifications_message_hub_push_log';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'employee_id',
        'employer_id',
        'message_id',
        'is_success',
        'exception_message',
        'read_status',
        'open_status',
        'completed_status',
        'delivered_status',
        'status',
        'created_at',
        'updated_at',
    ];

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

    /*
    * @return BelongsTo
    */
    public function message()
    {
        return $this->belongsTo(\App\Models\NotificationMessageHub::class,'message_id','id');
    }
}
