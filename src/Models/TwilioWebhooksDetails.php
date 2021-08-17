<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Model;

class TwilioWebhooksDetails extends Model
{
    protected $table = 'twilio_webhooks_details';
    protected $primaryKey = 'id';
    protected $fillable = array(
        'message_id',
        'date_created',
        'date_sent',
        'date_updated',
        'messaging_service_sid',
        'mobile_number',
        'employer_id',
        'employee_id',
        'status',
        'sid',
        'sms_type',
        'created_by'
    );

    public function creator() {
        return $this->hasOne(\App\Models\User::class, 'id', 'created_by');
    }

    public function user() {
        return $this->hasOne(\App\Models\User::class, 'id', 'user_id');
    }
}
