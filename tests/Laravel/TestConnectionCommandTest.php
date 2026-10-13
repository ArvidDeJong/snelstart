<?php

use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function fakeForCommand(int $echoStatus = 200, int $tokenStatus = 200, int $apiStatus = 200): void
{
    // Every answer echoes what was sent, as a server can; a failed one is an error, not data.
    $sent = ['key' => TestCase::SUBSCRIPTION_KEY, 'clientkey' => TestCase::CLIENT_KEY];

    Http::fake([
        'b2bapi.snelstart.nl/echo/*' => Http::response($sent, $echoStatus),
        'auth.snelstart.nl/*' => $tokenStatus === 200
            ? Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 3600])
            : Http::response($sent + ['error' => 'invalid_grant'], $tokenStatus),
        'b2bapi.snelstart.nl/v2/*' => $apiStatus === 200
            ? Http::response(['administratieNaam' => 'Demo BV', 'iban' => 'NL00TEST0123456789'])
            : Http::response($sent + ['authorization' => 'Bearer '.TestCase::ACCESS_TOKEN], $apiStatus),
    ]);
}

test('it checks both keys and names the administration', function () {
    fakeForCommand();

    $this->artisan('snelstart:test')
        ->expectsOutput('Testing Snelstart API connection...')
        ->expectsOutput('✓ Subscription key accepted.')
        ->expectsOutput('✓ Client key accepted.')
        ->expectsOutput('Connection successful! Administration: Demo BV')
        ->assertExitCode(0);
});

test('a refused subscription key stops before the client key is used', function () {
    fakeForCommand(echoStatus: 401);

    $this->artisan('snelstart:test')
        ->expectsOutputToContain('✗ Subscription key check failed: Snelstart API call failed. HTTP status: 401.')
        ->assertExitCode(1);

    Http::assertSentCount(1);
});

test('a refused client key is reported as such', function () {
    fakeForCommand(tokenStatus: 400);

    $this->artisan('snelstart:test')
        ->expectsOutput('✓ Subscription key accepted.')
        ->expectsOutputToContain('✗ Client key check failed: Failed to retrieve access_token from Snelstart. HTTP status: 400.')
        ->assertExitCode(1);
});

test('a missing client key is reported without a stack trace', function () {
    config(['snelstart.client_key' => null]);
    fakeForCommand();

    $this->artisan('snelstart:test')
        ->expectsOutput('✗ Client key check failed: Snelstart config is incomplete: the client key is empty.')
        ->assertExitCode(1);
});

test('the output never holds a key, the token or data of the bookkeeping', function (int $echo, int $token, int $api) {
    fakeForCommand($echo, $token, $api);

    Artisan::call('snelstart:test');

    expect(Artisan::output())
        ->not->toContain(TestCase::CLIENT_KEY)
        ->not->toContain(urlencode(TestCase::CLIENT_KEY))
        ->not->toContain(trim(json_encode(TestCase::CLIENT_KEY), '"'))
        ->not->toContain(TestCase::SUBSCRIPTION_KEY)
        ->not->toContain(TestCase::ACCESS_TOKEN)
        ->not->toContain('NL00TEST0123456789');
})->with([
    'success' => [200, 200, 200],
    'subscription key refused' => [401, 200, 200],
    'client key refused' => [200, 400, 200],
    'api refused' => [200, 200, 403],
]);
