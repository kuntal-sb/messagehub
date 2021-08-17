<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;

class PushNotificationLog extends Model
{
    protected $table = 'push_notification_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'employee_id',
        'message_id',
        'is_success',
        'exception_message',
        'read_status',
        'status',
        'created_at',
        'updated_at',
    ];

    /*
    * @return BelongsTo
    */
    public function user()
    {
        return $this->belongsTo(App\Models\User::class,'employee_id','id');
    }
}
