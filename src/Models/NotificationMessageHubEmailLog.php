<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationMessageHubEmailLog extends Model
{
    protected $table = 'notifications_message_hub_email_log';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'employee_id',
        'employer_id',
        'message_id',
        'created_at',
        'updated_at',
    ];

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

    /*
    * @return BelongsTo
    */
    public function message()
    {
        return $this->belongsTo(\App\Models\NotificationMessageHub::class,'message_id','id');
    }
}
