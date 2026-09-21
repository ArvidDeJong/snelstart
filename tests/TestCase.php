<?php

namespace Darvis\Snelstart\Tests;

use Darvis\Snelstart\SnelstartServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const CLIENT_KEY = 'test-client-key/with+base64==';

    public const SUBSCRIPTION_KEY = 'test-subscription-key';

    public const ACCESS_TOKEN = 'test-access-token';

    protected function setUp(): void
    {
        parent::setUp();

        // A forgotten fake fails the test instead of calling SnelStart.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SnelstartServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The package defaults have no keys, and without a client key the client cannot be built.
        $app['config']->set('snelstart.client_key', self::CLIENT_KEY);
        $app['config']->set('snelstart.subscription_key', self::SUBSCRIPTION_KEY);
    }

    /**
     * Fake the token endpoint and the API. The token endpoint always answers with the test token.
     *
     * @param  array<string, mixed>|callable  $api
     */
    protected function fakeSnelstart(array|callable $api = [], int $expiresIn = 3600): void
    {
        Http::fake([
            'auth.snelstart.nl/*' => Http::response([
                'access_token' => self::ACCESS_TOKEN,
                'token_type' => 'bearer',
                'expires_in' => $expiresIn,
            ]),
            'b2bapi.snelstart.nl/*' => is_callable($api) ? $api : Http::response($api),
        ]);
    }
}
