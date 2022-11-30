<?php

namespace Strivebenifits\Messagehub;

use Strivebenifits\Messagehub\Entities\TwilioResponseEntity;
use Strivebenifits\Messagehub\Facades\TwilioClient;
use Strivebenifits\Messagehub\Repositories\TwilioRepository;
use App\Http\Repositories\TwilioWebhookDetailRepository;
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
        try {
            if (
                TwilioClient::isEnabled()
                && !empty($employerId)
                && !empty($number)
            ) {
                $data = $this->sendOtp($number, $message);

                if(!$appNotDownloaded && !empty($data)) {
                    $this->twilioRepository->createLog(new TwilioResponseEntity($data['response'], $employeeId, $employerId, $data['status'], $number,$messageId,'message-hub', $data['exception']));
                }
            }

        } catch (Exception $e) {
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
                if ($this->twilioRepository->findLogBySid($callBackFields['SmsSid'])) {
                    return $this->twilioRepository
                        ->updateLog($callBackFields);
                } else {
                    $twilioWebhookDetailRepository = app()->make(TwilioWebhookDetailRepository::class);
                    return $twilioWebhookDetailRepository
                        ->updateLog($callBackFields);
                }
            }

        } catch (Exception $e) {
            Log::info($e->getMessage());
            throw $e;
        }
    }

    /**
     * @param $number
     * @param $message
     * @param $employer_id
     * @param $smsType
     * @return false
     * @throws Exception
     */
    public function sendMfaOtp($number, $message, $employerId, $smsType)
    {
        try {
            $twilioWebhookDetailRepository = app()->make(TwilioWebhookDetailRepository::class);
            if (
                TwilioClient::isEnabled()
                && !empty($employerId)
                && !empty($number)
            ) {
                $data = $this->sendOtp($number, $message);

                if (!empty($data)) {
                    $twilioWebhookDetailRepository
                        ->createLog(new TwilioResponseEntity($data['response'], 0, $employerId, $data['status'], $number, null, $smsType, $data['exception']));
                }
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
            throw $e;
        }

        return false;
    }

    /**
     * @param $number
     * @param $message
     * @return array
     */
    public function sendOtp($number, $message)
    {
        $number = $this->checkPhoneFormat(trim($number));
        Log::info('Sending SMS to : ' . $number);
        $response = TwilioClient::send($number, $message);
        $exceptionMessage = '';

        if (gettype($response) == 'array' && !empty($response['error'])) {
            $status = 'failed';
            $exceptionMessage = $response['error'];
        } else if ($response) {
            $status = 'success';
        } else {
            $status = 'failed';
            $exceptionMessage = json_encode($response);
        }
        Log::info('Twilio ' . $status . ' : ' . json_encode($response));

        return [
            'status' => $status,
            'exception' => $exceptionMessage,
            'response' => $response
        ];
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