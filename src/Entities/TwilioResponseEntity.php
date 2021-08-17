<?php

namespace Strivebenifits\Messagehub\Entities;

use Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Class TwilioResponseEntity
 * @package App\Entities
 */
class TwilioResponseEntity
{

    /**
     * @var mixed
     */
    private $messageId;

    /**
     * @var mixed
     */
    private $dateCreated;

    /**
     * @var mixed
     */
    private $dateUpdated;

    /**
     * @var mixed
     */
    private $dateSent;

    /**
     * @var mixed
     */
    private $messageSID;

    /**
     * @var mixed
     */
    private $sid;

    /**
     * @var
     */
    private $smsType;

    /**
     * @var
     */
    private $employeeId;

    /**
     * @var mixed
     */
    private $employerId;

    /**
     * @var mixed
     */
    private $status;

    
    /**
     * @var
     */
    private $mobileNumber;

    private $createdBy;

    const SMS_TYPE = 'welcome';

    /**
     * TwilioResponseEntity constructor.
     * @param $dataObj
     * @param $employeeId
     * @param $employerId
     */
    public function __construct($dataObj, $employeeId, $employerId, $status = null, $mobileNumber = null, $messageId = null, $smsType = null)
    {
        $this->messageId = !empty($messageId) ? $messageId : null;
        $this->dateCreated = !empty($dataObj->dateCreated) ? $dataObj->dateCreated->format('Y-m-d H:i:s') : null;
        $this->dateSent = !empty($dataObj->dateSent) ? $dataObj->dateSent->format('Y-m-d H:i:s') : null;
        $this->dateUpdated = !empty($dataObj->dateUpdated) ? $dataObj->dateUpdated->format('Y-m-d H:i:s') : null;
        $this->messageSID = !empty($dataObj->messagingServiceSid) ? $dataObj->messagingServiceSid : null;
        $this->sid = !empty($dataObj->sid) ? $dataObj->sid : null;
        $this->employerId = !empty($employerId) ? $employerId : null;
        $this->employeeId = !empty($employeeId) ? $employeeId : null;
        $this->status = $status ?? (!empty($dataObj->status) ? $dataObj->status : null);
        $this->mobileNumber = $mobileNumber ?? (!empty($mobileNumber) ? $mobileNumber : null);
        $this->createdBy = !empty($employerId) ? $employerId : null;
        $this->smsType = !empty($smsType) ? $smsType:self::SMS_TYPE;
    }

    /**
     * @return string
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * @param $createdBy
     * @return $this
     */
    public function setCreatedBy($createdBy)
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * @return mixed|string
     */
    public function getEmployerId()
    {
        return $this->employerId;
    }

    /**
     * @param $employerId
     * @return $this
     */
    public function setEmployerId($employerId): TwilioResponseEntity
    {
        $this->employerId = $employerId;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getEmployeeId()
    {
        return $this->employerId;
    }

    /**
     * @param $employeeId
     * @return $this
     */
    public function setEmployeeId($employeeId): TwilioResponseEntity
    {
        $this->employeeId = $employeeId;
        return $this;
    }
    /**
     * @param $sid
     * @return $this
     */
    public function setSid($sid): TwilioResponseEntity
    {
        $this->sid = $sid;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSid()
    {
        return $this->sid;
    }

    /**
     * @param $messageSID
     * @return $this
     */
    public function setMessageSid($messageSID): TwilioResponseEntity
    {
        $this->messageSID = $messageSID;
        return $this;
    }

    /**
     * @return false|string|null
     */
    public function getMessageSid()
    {
        return $this->messageSID;
    }

    /**
     * @param $dateCreated
     * @return $this
     */
    public function setDateCreated($dateCreated): TwilioResponseEntity
    {
        $this->dateCreated = $dateCreated;
        return $this;
    }

    /**
     * @return false|string|null
     */
    public function getDateCreated()
    {
        return $this->dateCreated;
    }

    /**
     * @param $dateSent
     * @return $this
     */
    public function setDateSent($dateSent): TwilioResponseEntity
    {
        $this->dateSent = $dateSent;
        return $this;
    }

    /**
     * @return false|string|null
     */
    public function getDateSent()
    {
        return $this->dateSent;
    }
    /**
     * @param $dateUpdated
     * @return $this
     */
    public function setDateUpdated($dateUpdated): TwilioResponseEntity
    {
        $this->dateUpdated = $dateUpdated;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDateUpdated(){

        return $this->dateUpdated;
    }

    /**
     * @param $status
     * @return $this
     */
    public function setStatus($status): TwilioResponseEntity
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStatus(){

        return $this->status;
    }

    /**
     * @return array
     */
    public function fieldsToArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'employer_id' => $this->employerId,
            'employee_id' => $this->employeeId,
            'date_created' => $this->dateCreated,
            'date_sent' => $this->dateSent,
            'date_updated' => $this->dateUpdated,
            'status' => $this->status,
            'sms_type' => $this->smsType,
            'messaging_service_sid' => $this->messageSID,
            'mobile_number' => $this->mobileNumber,
            'sid' => $this->sid,
            'created_by' => $this->createdBy
        ];
    }
}