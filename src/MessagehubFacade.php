<?php

namespace Strivebenifits\Messagehub;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Strivebenifits\Messagehub\Messagehub
 */
class MessagehubFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'messagehub';
    }
}
