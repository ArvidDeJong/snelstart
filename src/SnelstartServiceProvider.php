<?php

namespace Darvis\Snelstart;

use Darvis\Snelstart\Console\Commands\TestSnelstartConnection;
use Darvis\Snelstart\Services\EchoService;
use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Support\ServiceProvider;

class SnelstartServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/snelstart.php', 'snelstart'
        );

        $this->app->singleton(SnelstartAPI::class, function ($app) {
            return new SnelstartAPI;
        });

        $this->app->singleton(EchoService::class, function ($app) {
            return new EchoService(
                $app->make(SnelstartAPI::class)
            );
        });

        $this->app->alias(SnelstartAPI::class, 'snelstart');
        $this->app->alias(EchoService::class, 'snelstart.echo');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/snelstart.php' => config_path('snelstart.php'),
            ], 'snelstart-config');

            $this->commands([
                TestSnelstartConnection::class,
            ]);
        }
    }
}
