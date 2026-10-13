<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Laravel;

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Http\Transport;
use Darvis\Snelstart\Laravel\Console\TestConnectionCommand;
use Darvis\Snelstart\Token\MemoryTokenStore;
use Darvis\Snelstart\Token\TokenStore;
use Illuminate\Support\ServiceProvider;

class SnelstartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/snelstart.php', 'snelstart');

        // Bound by interface, so a host app can swap either one.
        $this->app->singletonIf(Transport::class, HttpTransport::class);

        $this->app->singletonIf(TokenStore::class, static fn (): TokenStore => SnelstartConfig::tokenCacheEnabled()
            ? new CacheTokenStore(SnelstartConfig::tokenCacheStore())
            : new MemoryTokenStore);

        // Built on first use: an application without keys boots, and fails where it calls SnelStart.
        $this->app->singleton(Client::class, static fn ($app): Client => new Client(
            SnelstartConfig::connection(),
            $app->make(Transport::class),
            $app->make(TokenStore::class),
        ));

        $this->app->alias(Client::class, 'snelstart');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/snelstart.php' => config_path('snelstart.php'),
            ], 'snelstart-config');

            $this->commands([TestConnectionCommand::class]);
        }
    }
}
