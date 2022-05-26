<?php

namespace Strivebenifits\Messagehub;

use Strivebenifits\Messagehub\Entities\TwilioResponseEntity;
use Strivebenifits\Messagehub\Facades\TwilioClient;
use Strivebenifits\Messagehub\Repositories\TwilioRepository;
use Exception;
use Log;

/**
 * Class TwilioManager
 * @package App\Manager
 */
class TwilioManager
{
    /**
     * @var TwilioRepository
     */
    private $twilioRepository;

    /**
     * TwilioManager constructor.
     * @param TwilioRepository $twilioRepository
     */
    public function __construct(
        TwilioRepository $twilioRepository
    )
    {
        $this->twilioRepository = $twilioRepository;
    }

    /**
     * @param $number
     * @param $message
     * @param $employer_id
     * @return false
     * @throws Exception
     */
    public function sendMessageHubSMS($message,$messageId,$employerId, $employeeData, $appNotDownloaded = true)
    {
        $number = $employeeData['phone_number'];
        $employeeId = $employeeData['id'];
        $status = '';
        $response = '';
        try {
            if (
                TwilioClient::isEnabled()
                && !empty($employerId)
                && !empty($number)
            ) {
                $number = $this->checkPhoneFormat(trim($number));
                Log::info('Sending SMS to : '.$number);

                $response = TwilioClient::send($number, $message);
                $exceptionMessage = '';

                if(gettype($response) == 'array' && !empty($response['error'])){
                    $status = 'failed';
                    $exceptionMessage = $response['error'];
                }
                else if ($response) {
                    $status = 'success';
                }else{
                    $status = 'failed';
                    $exceptionMessage = json_encode($response);
                }

                Log::info('Twilio '.$status.' : '.json_encode($response));

                if(!$appNotDownloaded) {
                    $this->twilioRepository->createLog(new TwilioResponseEntity($response, $employeeId, $employerId, $status, $number,$messageId,'message-hub', $exceptionMessage));
                }
            }

        } catch (Exception $e) {
            $status = 'failed';
            Log::info($e->getMessage());
        }

        return false;
    }

    /**
     * @param $callBackFields
     * @throws Exception
     */
    public function logCallBack($callBackFields)
    {
        try {

            if (TwilioClient::isEnabled() && !empty($callBackFields)) {
                return $this->twilioRepository
                    ->updateLog($callBackFields);
            }

        } catch (Exception $e) {
            Log::info($e->getMessage());
            throw $e;
        }
    }

    /**
     * @param $employerId
     * @return mixed|string
     */
    public function checkSMSEnabledForEmployer($employerId)
    {
        try {

            return $this->twilioRepository
                ->checkEmployerEnabled($employerId);

        } catch (Exception $e) {
            Log::error($e);
        }

        return false;
    }

    /**
     * @param $phone
     * @return mixed|string
     */
    public function checkPhoneFormat($phone)
    {
        // If number has the + symbol as prefix, no change to make
        if(preg_match('/^\+[0-9]*$/', $phone)){
            return $phone;
        }
        // If number has the (415)555-2671 no change ( its US based number accepted by Twilio )
        if(preg_match('/^\([0-9]{3}\)[0-9]*-[0-9]*$/', $phone)){
            return '+1'.$phone;
        }
        // If number has the 02071838750 no change ( UK based number accepted by Twilio )
        if(preg_match('/^0[0-9]*$/', $phone)){
            return $phone;
        }
        $phone = str_replace(['(',')','-',' '], '', $phone);

        if(strlen($phone) <= 10){
            return '+1'.$phone;
        }
        return '+'.$phone;
    }

    /**
     * @param $phone
     * @return bool
     */
    public function isValidPhoneFormat($phone): bool
    {
        if (preg_match('/^\+[0-9]*$/', $phone)
            || preg_match('/^\([0-9]{3}\)[0-9]*-[0-9]*$/', $phone)
            || preg_match('/^0[0-9]*$/', $phone)
            || preg_match('/^[0-9]{9,14}$/', $phone)
        ) {
            return true;
        }

        return false;
    }
}