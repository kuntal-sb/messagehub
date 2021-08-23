<?php

namespace Strivebenifits\Messagehub\Repositories;
use App\Http\Repositories\BaseRepository;
use Strivebenifits\Messagehub\Entities\TwilioResponseEntity;
use Strivebenifits\Messagehub\Models\NotificationMessageHubTextLog;
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
     * @var NotificationMessageHubTextLog
     */
    private $notificationMessageHubTextLog;

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
     * @param NotificationMessageHubTextLog $notificationMessageHubTextLog
     * @param User $user
     * @param Connection $eloquentORM
     */
    public function __construct(
        NotificationMessageHubTextLog $notificationMessageHubTextLog,
        User $user,
        Connection $eloquentORM
    )
    {
        parent::__construct($eloquentORM);
        $this->notificationMessageHubTextLog = $notificationMessageHubTextLog;
        $this->user = $user;
    }

    /**
     * @param TwilioResponseEntity $entity
     * @return NotificationMessageHubTextLog|\Illuminate\Database\Eloquent\Model
     */
    public function createLog(TwilioResponseEntity $entity)
    {
        $fields = $entity->fieldsToArray();
        return $this->notificationMessageHubTextLog->create($fields);
    }

    /**
     * @param $sid
     * @return log details
     */
    public function findLogBySid($sid)
    {
        return $this->notificationMessageHubTextLog
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

        return $this->notificationMessageHubTextLog
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