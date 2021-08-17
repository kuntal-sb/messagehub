<?php
namespace Strivebenifits\Messagehub\Facades;

use Illuminate\Support\Facades\Facade;

class TwilioClient extends Facade
{
    protected static function getFacadeAccessor() { return 'twilioservice'; }
}