<?php

namespace Strivebenifits\Messagehub\Models\MongoDb;

use Jenssegers\Mongodb\Eloquent\Model;

class NotificationSchedule extends Model
{
    protected $connection = 'mongodb';
    
    protected $collection = 'notificationSchedule';

    protected $guarded = [];
}
