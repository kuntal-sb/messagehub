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
        // $this->loadViewsFrom(__DIR__.'/views', 'messagehub');
        // $this->publishes([
        //     __DIR__.'/views' => base_path('resources/views/strivebenifits/messagehub'),
        // ]);

        $this->publishes([
	        __DIR__.'/../config/messagehub.php' => config_path('messagehub.php'),
	    ],'messagehub-config');
        
        $this->publishes([
            __DIR__.'/../config/role.php' => config_path('role.php'),
        ],'role-config');

        $this->publishes([
            __DIR__.'/../config/invoice.php' => config_path('invoice.php'),
        ],'invoice-config');
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