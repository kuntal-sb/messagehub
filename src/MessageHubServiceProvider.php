<?php
namespace Strivebenifits\Messagehub;

use Illuminate\Support\ServiceProvider;

class MessageHubServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // $this->loadRoutesFrom(__DIR__.'/routes.php');
        // $this->loadMigrationsFrom(__DIR__.'/migrations');
        // $this->loadViewsFrom(__DIR__.'/views', 'todolist');
        // $this->publishes([
        //     __DIR__.'/views' => base_path('resources/views/strivebenifits/messagehub'),
        // ]);

        $this->publishes([
	        __DIR__.'/../config/messagehub.php' => config_path('messagehub.php'),
	    ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //$this->app->make('strivebenifits\messagehub\MessageHubController');
    }
}