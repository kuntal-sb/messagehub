<?php

namespace Strivebenifits\Messagehub\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;
use Mail;
use Exception;
use Strivebenifits\Messagehub\MessagehubManager;

class sendNotifications implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $data;

    public $timeout = 0;

    public function __construct($data)
    {
        $this->onQueue('push_notification_queue');
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        config(['logging.default' => 'pushNotification']);
        try{
            $messagehubManager = app()->make(MessagehubManager::class);
            $messagehubManager->sendOutNotifications($this->data);
        }catch(Exception $e){
            Log::error($e);
        }
    }

    //Send notification if the queue failure occur
    public function failed($exception)
    {
        Log::error($exception);
        Mail::raw('Text', function ($message){
            $message->to('kuntal@strivebenefits.com');
        });
    }
}
