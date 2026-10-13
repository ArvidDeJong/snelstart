<?php

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;
use Darvis\Snelstart\Http\Transport;
use Darvis\Snelstart\Laravel\CacheTokenStore;
use Darvis\Snelstart\Laravel\Facades\Snelstart;
use Darvis\Snelstart\Laravel\HttpTransport;
use Darvis\Snelstart\Laravel\SnelstartConfig;
use Darvis\Snelstart\Laravel\SnelstartServiceProvider;
use Darvis\Snelstart\Testing\FakeTransport;
use Darvis\Snelstart\Tests\TestCase;
use Darvis\Snelstart\Token\MemoryTokenStore;
use Darvis\Snelstart\Token\TokenStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

test('the client is a singleton with the alias snelstart, built from the config', function () {
    config(['snelstart.timeout' => '12', 'snelstart.base_url' => 'https://example.test/v2/']);

    $client = app(Client::class);

    expect(app('snelstart'))->toBe($client)
        ->and(app(Transport::class))->toBeInstanceOf(HttpTransport::class)
        ->and(app(TokenStore::class))->toBeInstanceOf(CacheTokenStore::class)
        ->and($client->config()->clientKey)->toBe(TestCase::CLIENT_KEY)
        ->and($client->config()->subscriptionKey)->toBe(TestCase::SUBSCRIPTION_KEY)
        ->and($client->config()->timeout)->toBe(12.0)
        ->and($client->config()->baseUrl)->toBe('https://example.test/v2');
});

test('the defaults of the config file are the defaults of the plain PHP config', function () {
    config(['snelstart.client_key' => null, 'snelstart.subscription_key' => null]);

    expect(SnelstartConfig::connection())->toEqual(new Config);
});

test('the facade reaches the client', function () {
    $this->fakeSnelstart([['naam' => 'Jansen']]);

    expect(Snelstart::relaties()->list())->toBe([['naam' => 'Jansen']])
        ->and(Snelstart::getFacadeRoot())->toBe(app(Client::class))
        ->and(Snelstart::withClientKey('other')->config()->clientKey)->toBe('other');
});

test('an application without keys boots, and fails where it calls SnelStart', function () {
    config(['snelstart.client_key' => null]);
    Http::fake();

    $client = app(Client::class);

    expect(fn () => $client->companyInfo())->toThrow('Snelstart config is incomplete: the client key is empty.');
    Http::assertNothingSent();
});

test('the token cache can be turned off, and anything unclear keeps it on', function (mixed $value, string $store) {
    config(['snelstart.token_cache.enabled' => $value]);

    expect(app(TokenStore::class))->toBeInstanceOf($store);
})->with([
    [false, MemoryTokenStore::class],
    ['false', MemoryTokenStore::class],
    ['0', MemoryTokenStore::class],
    ['off', MemoryTokenStore::class],
    [true, CacheTokenStore::class],
    ['', CacheTokenStore::class],
    [null, CacheTokenStore::class],
    ['maybe', CacheTokenStore::class],
]);

test('a host app can bind its own transport and token store', function () {
    $transport = (new FakeTransport)->respond('companyInfo', ['administratieNaam' => 'Fake']);

    $this->app->instance(Transport::class, $transport);
    $this->app->instance(TokenStore::class, new MemoryTokenStore);

    expect(app(Client::class)->companyInfo())->toBe(['administratieNaam' => 'Fake'])
        ->and($transport->sent('/token'))->toHaveCount(1);
});

test('the config file is publishable under its tag, and nothing else is', function () {
    $paths = ServiceProvider::pathsToPublish(SnelstartServiceProvider::class, 'snelstart-config');

    expect($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath(__DIR__.'/../../config/snelstart.php'))
        ->and(array_values($paths)[0])->toBe(config_path('snelstart.php'))
        ->and(ServiceProvider::pathsToPublish(SnelstartServiceProvider::class))->toHaveCount(1);
});
