<?php

namespace Strivebenifits\Messagehub\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceCreatedBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $message;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($user,$invoice)
    {
        $this->user = $user;
        $this->message = 'Your invoice for the period '.date('F Y',strtotime($invoice->start_date).' To .' date('F Y',strtotime($invoice->end_date).' of amount $'.number_format($invoice->amount + $invoice->tax - $invoice->discount,2) }}.' generated. Please check payment History Page';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('invoice-created-channel.'.$this->user->id);
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'InvoiceCreatedEvent';
    }
}
