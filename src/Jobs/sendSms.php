<?php

namespace Strivebenifits\Messagehub\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Strivebenifits\Messagehub\TwilioManager;
use Log;

class sendSms implements ShouldQueue
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
        $this->onQueue('txt_notification_queue');
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        config(['logging.default' => 'smsNotification']);
        try {
            Log::info("Send SMS JOB Started: ". json_encode($this->data));

            $app_not_downloaded = isset($this->data['app_not_downloaded'])?$this->data['app_not_downloaded']:false;
            $twilioManager = app()->make(TwilioManager::class);
            $twilioManager->sendMessageHubSMS($this->data['message'],$this->data['message_id'],$this->data['employer_id'], $this->data['employee'], $app_not_downloaded);

            Log::info("Send SMS JOB END");
        } catch (Exception $e) {
            Log::error($e);
        }
    }
}
