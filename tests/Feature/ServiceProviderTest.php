<?php

use Darvis\Snelstart\Console\Commands\TestSnelstartConnection;
use Darvis\Snelstart\Services\EchoService;
use Darvis\Snelstart\Services\SnelstartAPI;
use Darvis\Snelstart\SnelstartServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;

it('registers the API client and the echo service as singletons', function () {
    expect(app(SnelstartAPI::class))->toBe(app(SnelstartAPI::class))
        ->and(app(EchoService::class))->toBe(app(EchoService::class));
});

it('registers the snelstart and snelstart.echo aliases', function () {
    expect(app('snelstart'))->toBe(app(SnelstartAPI::class))
        ->and(app('snelstart.echo'))->toBe(app(EchoService::class));
});

it('merges the package config', function () {
    expect(config('snelstart.base_url'))->toBe('https://b2bapi.snelstart.nl/v2')
        ->and(config('snelstart.token_url'))->toBe('https://auth.snelstart.nl/b2b/token');
});

it('does not build the client, and so does not need the keys, until it is used', function () {
    config(['snelstart.client_key' => null]);

    (new SnelstartServiceProvider(app()))->register();

    expect(app()->resolved(SnelstartAPI::class))->toBeFalse();
});

it('registers the snelstart:test command', function () {
    expect(app(Kernel::class)->all())->toHaveKey('snelstart:test')
        ->and(app(Kernel::class)->all()['snelstart:test'])->toBeInstanceOf(TestSnelstartConnection::class);
});

it('offers the config file for publishing', function () {
    // Never run vendor:publish here: it writes into the Testbench app.
    $paths = ServiceProvider::pathsToPublish(SnelstartServiceProvider::class, 'snelstart-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/snelstart.php')
        ->and(array_values($paths)[0])->toBe(config_path('snelstart.php'));
});
