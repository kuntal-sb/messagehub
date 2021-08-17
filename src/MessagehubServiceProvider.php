<?php

namespace Strivebenifits\Messagehub;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Strivebenifits\Messagehub\Commands\MessagehubCommand;

class MessagehubServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('messagehub')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_messagehub_table')
            ->hasCommand(MessagehubCommand::class);
    }
}
