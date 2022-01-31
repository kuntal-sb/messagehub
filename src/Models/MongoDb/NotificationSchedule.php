<?php

namespace Strivebenifits\Messagehub\Models\MongoDb;

use Jenssegers\Mongodb\Eloquent\Model;
use Carbon\Carbon;

class NotificationSchedule extends Model
{
    protected $connection = 'mongodb';
    
    protected $collection = 'notificationSchedule';

    protected $guarded = [];

    public function getScheduledDateTimeAttribute()
    {
        if(isset($this->schedule_datetime)){
            return Carbon::createFromFormat('m/d/Y h:i A',$this->schedule_datetime)->toDayDateTimeString();
        }else{
            return Carbon::createFromFormat('Y-m-d h:i A',$this->schedule_date . " " . $this->schedule_time)->setTimezone(getEmployerTimezone())->toDayDateTimeString();
        }
    }

    public function getScheduledEndDateTimeAttribute()
    {
        if(isset($this->schedule_end_datetime)){
            return Carbon::createFromFormat('m/d/Y h:i A',$this->schedule_end_datetime)->toDayDateTimeString();
        }else{
            return '';
        }
    }
}
