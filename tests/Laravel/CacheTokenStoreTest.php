<?php

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Laravel\CacheTokenStore;
use Darvis\Snelstart\Tests\Fixtures\BrokenLockStore;
use Darvis\Snelstart\Tests\Fixtures\NoLockStore;
use Darvis\Snelstart\Tests\TestCase;
use Darvis\Snelstart\Token\TokenStore;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function tokenRequests(): int
{
    return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'auth.snelstart.nl'))->count();
}

/**
 * Collects the warnings that are logged from here on.
 *
 * @return ArrayObject<int, string>
 */
function loggedWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($warnings) {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    return $warnings;
}

test('the token goes into the cache encrypted, under a key without the client key', function () {
    $this->fakeSnelstart();

    app(Client::class)->get('relaties');

    $cached = Cache::get(TestCase::tokenKey());

    expect($cached)->toBeString()
        ->and($cached)->not->toContain(TestCase::ACCESS_TOKEN)
        ->and(json_decode(Crypt::decryptString($cached), true)['access_token'])->toBe(TestCase::ACCESS_TOKEN)
        ->and(TestCase::tokenKey())->not->toContain(TestCase::CLIENT_KEY);
});

test('a new client, as in the next web request, uses the cached token', function () {
    $this->fakeSnelstart();

    app(Client::class)->get('relaties');

    $this->app->forgetInstance(Client::class);
    $this->app->forgetInstance(TokenStore::class);

    app(Client::class)->get('relaties');

    expect(tokenRequests())->toBe(1);
});

test('the token is cached until sixty seconds before it expires', function () {
    $this->fakeSnelstart([], 600);

    app(Client::class)->get('relaties');

    $this->travel(539)->seconds();
    expect(Cache::has(TestCase::tokenKey()))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::has(TestCase::tokenKey()))->toBeFalse();
});

test('a store reads a token another process cached, and forgets it everywhere', function () {
    $first = new CacheTokenStore;
    $second = new CacheTokenStore;

    $first->put('key', 'the-token', 300);

    expect($second->get('key'))->toBe('the-token');

    $second->forget('key');

    expect(Cache::has('key'))->toBeFalse()
        ->and($second->get('key'))->toBeNull();
});

test('a value that is not a token this application encrypted is no token, and is removed', function (mixed $value) {
    Cache::put('key', is_callable($value) ? $value() : $value, 300);

    expect((new CacheTokenStore)->get('key'))->toBeNull()
        ->and(Cache::has('key'))->toBeFalse();
})->with([
    'plain text' => ['the-token'],
    'not a string' => [['access_token' => 'the-token']],
    'expired' => [fn () => Crypt::encryptString(json_encode(['access_token' => 'the-token', 'expires_at' => time() - 1]))],
    'no token' => [fn () => Crypt::encryptString(json_encode(['expires_at' => time() + 300]))],
    'not json' => [fn () => Crypt::encryptString('the-token')],
]);

test('another cache store can be chosen', function () {
    config(['cache.stores.tokens' => ['driver' => 'array'], 'snelstart.token_cache.store' => 'tokens']);
    $this->fakeSnelstart();

    app(Client::class)->get('relaties');

    expect(Cache::store('tokens')->has(TestCase::tokenKey()))->toBeTrue()
        ->and(Cache::has(TestCase::tokenKey()))->toBeFalse();
});

test('a cache that is down is one warning, and the client works from memory', function () {
    config(['snelstart.token_cache.store' => 'does-not-exist']);
    $this->fakeSnelstart(['ok' => true]);
    $warnings = loggedWarnings();

    $client = app(Client::class);

    expect($client->get('relaties'))->toBe(['ok' => true])
        ->and($client->get('relaties'))->toBe(['ok' => true])
        ->and(tokenRequests())->toBe(1)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toStartWith('Snelstart token cache is not available, the token is kept in memory only: ');
});

test('the token request runs under a lock, which is free again afterwards', function () {
    $held = null;

    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$held) {
            $held = ! Cache::lock(TestCase::tokenKey().'.lock', 10)->get();

            return Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 3600]);
        },
        'b2bapi.snelstart.nl/*' => Http::response([]),
    ]);

    app(Client::class)->get('relaties');

    expect($held)->toBeTrue()
        ->and(Cache::lock(TestCase::tokenKey().'.lock', 10)->get())->toBeTrue();
});

test('the lock is released when the token request fails', function () {
    Http::fake(['auth.snelstart.nl/*' => Http::response('no', 401)]);

    expect(fn () => app(Client::class)->get('relaties'))->toThrow('Failed to retrieve access_token from Snelstart.')
        ->and(Cache::lock(TestCase::tokenKey().'.lock', 10)->get())->toBeTrue();
});

test('a store without locks works without one, and without a warning', function () {
    Cache::extend('no-lock', fn () => Cache::repository(new NoLockStore));
    config(['cache.stores.no-lock' => ['driver' => 'no-lock'], 'snelstart.token_cache.store' => 'no-lock']);
    $this->fakeSnelstart(['ok' => true]);
    $warnings = loggedWarnings();

    expect(app(Client::class)->get('relaties'))->toBe(['ok' => true])
        ->and(Cache::store('no-lock')->has(TestCase::tokenKey()))->toBeTrue()
        ->and($warnings)->toHaveCount(0);
});

test('a lock that fails never fails the call: one warning, and the token is still cached', function () {
    Cache::extend('broken-lock', fn () => Cache::repository(new BrokenLockStore));
    config(['cache.stores.broken-lock' => ['driver' => 'broken-lock'], 'snelstart.token_cache.store' => 'broken-lock']);
    $this->fakeSnelstart(['ok' => true]);
    $warnings = loggedWarnings();

    expect(app(Client::class)->get('relaties'))->toBe(['ok' => true])
        ->and(Cache::store('broken-lock')->has(TestCase::tokenKey()))->toBeTrue()
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('The lock backend is gone.');
});

test('with the cache turned off nothing is written to it', function () {
    config(['snelstart.token_cache.enabled' => false]);
    $this->fakeSnelstart();

    $client = app(Client::class);
    $client->get('relaties');
    $client->get('relaties');

    expect(tokenRequests())->toBe(1)
        ->and(Cache::has(TestCase::tokenKey()))->toBeFalse();
});
