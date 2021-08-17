<?php

namespace Strivebenifits\Messagehub\Repositories;
use App\Http\Repositories\BaseRepository;
use App\Entities\TwilioResponseEntity;
use App\Models\TwilioWebhooksDetails;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Connection;

/**
 * Class TwilioRepository
 * @package App\Repository
 */
class TwilioRepository extends BaseRepository
{
    /**
     * @var TwilioWebhooksDetails
     */
    private $twilioWebhooksDetails;

   /**
     * @var
     */
    private $twilioEmployerCreditLog;

    /**
     * @var User
     */
    private $user;


    /**
     * TwilioRepository constructor.
     * @param TwilioWebhooksDetails $twilioWebhooksDetails
     * @param User $user
     * @param Connection $eloquentORM
     */
    public function __construct(
        TwilioWebhooksDetails $twilioWebhooksDetails,
        User $user,
        Connection $eloquentORM
    )
    {
        parent::__construct($eloquentORM);
        $this->twilioWebhooksDetails = $twilioWebhooksDetails;
        $this->user = $user;
    }

    /**
     * @param TwilioResponseEntity $entity
     * @return TwilioWebhooksDetails|\Illuminate\Database\Eloquent\Model
     */
    public function createLog(TwilioResponseEntity $entity)
    {
        $fields = $entity->fieldsToArray();
        return $this->twilioWebhooksDetails
            ->create($fields);
    }

    /**
     * @param $sid
     * @return log details
     */
    public function findLogBySid($sid)
    {
        return $this->twilioWebhooksDetails
            ->where('sid', $sid)
            ->first();
    }

    /**
     * @param $fields
     * @return mixed
     */
    public function updateLog($fields)
    {
        $updateFields = [
            'status' => $fields['SmsStatus'],
            'date_updated' => Carbon::now()
        ];

        if ($smsLog = $this->findLogBySid($fields['SmsSid'])) {
            if (empty($smsLog->dateSent) && $fields['SmsStatus'] === 'sent') {
                $updateFields['date_sent'] = Carbon::now();
            }
        }

        return $this->twilioWebhooksDetails
            ->where('sid', $fields['SmsSid'])
            ->update(
                $updateFields
            );
    }
  
    /**
     * @param $employerId
     * @return TwilioEmployerCredit|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|null
     */
    public function checkEmployerEnabled($employerId)
    {
        return $this->twilioEmployerCredit
            ->where('employer_id', $employerId)
            ->where('is_active', true)
            ->count();
    }
}