<?php

namespace Strivebenifits\Messagehub\Services;

use Exception;
use Twilio\Rest\Client;
use Log;

/**
 * Class WellnessChallengeService
 * @package App\Services
 */
class TwilioService
{

    /**
     * @return bool
     */
    public function isEnabled()
    {
        if (
            config('twilio.enable_sms')
            && !empty(config('twilio.twilio_sid'))
            && !empty(config('twilio.twilio_auth_token'))
            && !empty(config('twilio.twilio_number'))
        ) {
            return true;
        }

        return false;
    } 

    /**
     * @param $to
     * @param $message
     * @throws \Twilio\Exceptions\ConfigurationException
     * @throws \Twilio\Exceptions\TwilioException
     */
    public function send($to, $message)
    {
        try {
            $requestPayload = [
                'body' => $message,
                'from' => config('twilio.twilio_number')
            ];

            $requestPayload['statusCallback'] = url()->to('/').'/twilio/callbackMessage';//'http://bc2afca26b6e.ngrok.io/twilio/callbackMessage';

            $client = $this->initRequest();

            $phoneData = $client->lookups->v1->phoneNumbers($to)
                ->fetch(["type" => ["carrier"]]);

            if ($phoneData->carrier['type'] === 'mobile' && empty($phoneData->carrier['error_code'])) {
                return $client->messages->create(
                    $to,
                    $requestPayload
                );
            }else{
                Log::info(json_encode($phoneData));    
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
        }
    }

    /**
     * @return Client
     * @throws \Twilio\Exceptions\ConfigurationException
     */
    private function initRequest()
    {
        return new Client(config('twilio.twilio_sid'), config('twilio.twilio_auth_token'));
    }
}