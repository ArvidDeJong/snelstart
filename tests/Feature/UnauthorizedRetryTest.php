<?php

use Darvis\Snelstart\Services\SnelstartAPI;
use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A token endpoint that hands out token-1, token-2, ... and an API that only accepts $accepted.
 * $accepted is a reference, so a test can revoke a token halfway.
 */
function fakeRotatingTokens(?string &$accepted): void
{
    $issued = 0;

    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$issued) {
            return Http::response(['access_token' => 'token-'.(++$issued), 'expires_in' => 3600]);
        },
        'b2bapi.snelstart.nl/*' => function (Request $request) use (&$accepted) {
            return $request->hasHeader('Authorization', 'Bearer '.$accepted)
                ? Http::response(['id' => 'abc'])
                : Http::response(['message' => 'Unauthorized'], 401);
        },
    ]);
}

/**
 * @return array<int, string>
 */
function requestTrail(): array
{
    return Http::recorded()->map(fn (array $pair) => str_contains($pair[0]->url(), 'auth.')
        ? 'token'
        : $pair[0]->method().' '.($pair[0]->header('Authorization')[0] ?? ''))->all();
}

it('fetches a new token and repeats the call once when a held token is refused', function () {
    $accepted = 'token-1';
    fakeRotatingTokens($accepted);

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();

    // SnelStart revokes token-1; from now on only token-2 works.
    $accepted = 'token-2';

    expect($api->createRelatie(['naam' => 'Example B.V.']))->toBe(['id' => 'abc']);

    expect(requestTrail())->toBe([
        'token',
        'GET Bearer token-1',
        'POST Bearer token-1',
        'token',
        'POST Bearer token-2',
    ]);

    $repeat = Http::recorded()->last()[0];

    expect($repeat->url())->toBe('https://b2bapi.snelstart.nl/v2/relaties')
        ->and($repeat->data())->toBe(['naam' => 'Example B.V.']);
});

it('does the same for a token that came from the cache, and replaces the cached one', function () {
    $accepted = 'token-1';
    fakeRotatingTokens($accepted);
    app(SnelstartAPI::class)->getCompanyInfo();

    $accepted = 'token-2';
    app()->forgetInstance(SnelstartAPI::class);

    expect(app(SnelstartAPI::class)->getCompanyInfo())->toBe(['id' => 'abc']);

    expect(requestTrail())->toBe(['token', 'GET Bearer token-1', 'GET Bearer token-1', 'token', 'GET Bearer token-2']);

    app()->forgetInstance(SnelstartAPI::class);
    app(SnelstartAPI::class)->getCompanyInfo();

    expect(array_slice(requestTrail(), 5))->toBe(['GET Bearer token-2']);
});

it('reports a second 401 as before, and tries only once', function () {
    $accepted = 'token-1';
    fakeRotatingTokens($accepted);

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();

    $accepted = null;

    expect(fn () => $api->getCompanyInfo())->toThrow(
        RuntimeException::class,
        'Snelstart API call failed. HTTP status: 401. Response: {"message":"Unauthorized"}',
    );

    expect(requestTrail())->toBe(['token', 'GET Bearer token-1', 'GET Bearer token-1', 'token', 'GET Bearer token-2']);
});

it('does not repeat a call that was refused with a token it fetched a moment ago', function () {
    $accepted = null;
    fakeRotatingTokens($accepted);

    expect(fn () => app(SnelstartAPI::class)->getCompanyInfo())->toThrow(RuntimeException::class, 'HTTP status: 401');

    expect(requestTrail())->toBe(['token', 'GET Bearer token-1']);
});

it('never retries a 401 of the token endpoint', function () {
    Http::fake(['auth.snelstart.nl/*' => Http::response(['error' => 'invalid_grant'], 401)]);

    expect(fn () => app(SnelstartAPI::class)->getCompanyInfo())->toThrow(RuntimeException::class, 'Failed to retrieve access_token');

    Http::assertSentCount(1);
});

it('does not retry a 403, 429 or 5xx on a held token', function (int $status) {
    $calls = 0;
    $this->fakeSnelstart(function () use (&$calls, $status) {
        return ++$calls === 1 ? Http::response([]) : Http::response(['message' => 'No'], $status);
    });

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();

    expect(fn () => $api->getCompanyInfo())->toThrow(RuntimeException::class, 'HTTP status: '.$status);

    Http::assertSentCount(3);
    expect(Cache::has(TestCase::tokenCacheKey()))->toBeTrue();
})->with([403, 429, 500, 503]);

it('removes the old and the new token from the message of the second 401', function () {
    $accepted = 'token-1';
    $issued = 0;

    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$issued) {
            return Http::response(['access_token' => 'token-'.(++$issued), 'expires_in' => 3600]);
        },
        'b2bapi.snelstart.nl/*' => function (Request $request) use (&$accepted) {
            return $accepted === null
                ? Http::response(['sent' => $request->header('Authorization')[0]], 401)
                : Http::response([]);
        },
    ]);

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();
    $accepted = null;

    try {
        $api->getCompanyInfo();
        $this->fail('Expected an exception.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Bearer [redacted]')
            ->not->toContain('token-1')
            ->not->toContain('token-2');
    }
});
