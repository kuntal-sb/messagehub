<?php

namespace Strivebenifits\Messagehub\Commands;

use Illuminate\Console\Command;

class MessagehubCommand extends Command
{
    public $signature = 'messagehub';

    public $description = 'My command';

    public function handle()
    {
        $this->comment('All done');
    }
}
