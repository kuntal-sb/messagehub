<?php

namespace Strivebenifits\Messagehub\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'paid_by',
        'invoice_no',
        'message_count',
        'amount',
        'tax',
        'discount',
        'start_date',
        'end_date',
        'extra_details',
        'note',
        'payment_mode',
        'type',
        'satus',
        'updated_at',
    ];
}
