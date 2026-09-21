<?php

use Darvis\Snelstart\Services\SnelstartAPI;
use Darvis\Snelstart\Tests\Fixtures\BrokenLockStore;
use Darvis\Snelstart\Tests\Fixtures\NoLockStore;
use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function tokenLockKey(): string
{
    return TestCase::tokenCacheKey().'.lock';
}

/**
 * True when nobody holds the token lock. Taking it is the only way to ask; it is given back at once.
 */
function tokenLockIsFree(?string $store = null): bool
{
    $lock = Cache::store($store)->lock(tokenLockKey(), 10);

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

function tokenRequests(): int
{
    return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'auth.snelstart.nl'))->count();
}

/**
 * Fake both hosts, and note during the token request whether the lock was held.
 */
function fakeAndWatchTheLock(?bool &$heldDuringTokenRequest, int $tokenStatus = 200): void
{
    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$heldDuringTokenRequest, $tokenStatus) {
            $heldDuringTokenRequest = ! tokenLockIsFree();

            return Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 3600], $tokenStatus);
        },
        'b2bapi.snelstart.nl/*' => Http::response([]),
    ]);
}

/**
 * A client that does not wait for a lock somebody else holds, so a test does not sleep five seconds.
 */
function impatientClient(): SnelstartAPI
{
    return new class extends SnelstartAPI
    {
        protected const TOKEN_LOCK_WAIT_SECONDS = 0;
    };
}

it('holds a lock while it fetches the token, and gives it back afterwards', function () {
    fakeAndWatchTheLock($held);

    app(SnelstartAPI::class)->getCompanyInfo();

    expect($held)->toBeTrue()
        ->and(tokenLockIsFree())->toBeTrue()
        ->and(tokenLockKey())->not->toContain(TestCase::CLIENT_KEY);
});

it('looks in the cache again once it has the lock, so a cold cache gives one token request', function () {
    fakeAndWatchTheLock($held);

    // What a second web request sees: nothing in the cache when it looks, and by the time it gets
    // the lock the first request has put its token there.
    $second = new class extends SnelstartAPI
    {
        protected function acquireTokenLock(): ?Lock
        {
            Cache::put($this->tokenCacheKey(), Crypt::encryptString((string) json_encode([
                'access_token' => 'token-of-the-first-request',
                'expires_at' => now()->addHour()->getTimestamp(),
            ])), 3600);

            return parent::acquireTokenLock();
        }
    };

    $second->getCompanyInfo();

    expect(tokenRequests())->toBe(0)
        ->and(tokenLockIsFree())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-of-the-first-request'));
});

it('gives the lock back when the token request fails', function (int $status) {
    fakeAndWatchTheLock($held, $status);

    expect(fn () => app(SnelstartAPI::class)->getCompanyInfo())->toThrow(RuntimeException::class, 'Failed to retrieve access_token');

    expect($held)->toBeTrue()
        ->and(tokenLockIsFree())->toBeTrue();
})->with([401, 500]);

it('gives the lock back when the token endpoint cannot be reached', function () {
    Http::fake(['auth.snelstart.nl/*' => function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    }]);

    expect(fn () => app(SnelstartAPI::class)->getCompanyInfo())->toThrow(ConnectionException::class);

    expect(tokenLockIsFree())->toBeTrue();
});

it('fetches without the lock when somebody else keeps holding it, and leaves their lock alone', function () {
    fakeAndWatchTheLock($held);

    $theirs = Cache::lock(tokenLockKey(), 60);
    expect($theirs->get())->toBeTrue();

    $start = microtime(true);

    expect(impatientClient()->getCompanyInfo())->toBe([])
        ->and(tokenRequests())->toBe(1)
        ->and(microtime(true) - $start)->toBeLessThan(1.0)
        ->and(tokenLockIsFree())->toBeFalse();

    $theirs->release();
});

it('waits five seconds for the lock, and keeps it for the timeout of a token request plus a margin', function () {
    $this->travelTo(now()->startOfSecond());
    config(['snelstart.timeout' => 12]);

    $expiresAt = null;

    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$expiresAt) {
            $expiresAt = Cache::getStore()->locks[tokenLockKey()]['expiresAt'] ?? null;

            return Http::response(['access_token' => TestCase::ACCESS_TOKEN]);
        },
        'b2bapi.snelstart.nl/*' => Http::response([]),
    ]);

    $client = new class extends SnelstartAPI
    {
        /** @return array<int, int> */
        public function lockNumbers(): array
        {
            return [self::TOKEN_LOCK_WAIT_SECONDS, self::TOKEN_LOCK_MAX_SECONDS];
        }
    };

    $client->getCompanyInfo();

    expect($client->lockNumbers())->toBe([5, 120])
        ->and($expiresAt?->getTimestamp())->toBe(now()->addSeconds(17)->getTimestamp());
});

it('never keeps the lock for more than two minutes', function () {
    $this->travelTo(now()->startOfSecond());
    config(['snelstart.timeout' => 900]);

    $expiresAt = null;

    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$expiresAt) {
            $expiresAt = Cache::getStore()->locks[tokenLockKey()]['expiresAt'] ?? null;

            return Http::response(['access_token' => TestCase::ACCESS_TOKEN]);
        },
        'b2bapi.snelstart.nl/*' => Http::response([]),
    ]);

    app(SnelstartAPI::class)->getCompanyInfo();

    expect($expiresAt?->getTimestamp())->toBe(now()->addSeconds(120)->getTimestamp());
});

it('fetches and caches the token on a store without lock support, without a log line', function () {
    Cache::extend('no-lock', fn () => Cache::repository(new NoLockStore));
    config(['cache.stores.no-lock' => ['driver' => 'no-lock'], 'snelstart.token_cache.store' => 'no-lock']);
    $this->fakeSnelstart();

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = $event->message;
    });

    app(SnelstartAPI::class)->getCompanyInfo();
    app()->forgetInstance(SnelstartAPI::class);
    app(SnelstartAPI::class)->getCompanyInfo();

    expect(tokenRequests())->toBe(1)
        ->and(Cache::store('no-lock')->has(TestCase::tokenCacheKey()))->toBeTrue()
        ->and($lines)->toBe([]);
});

it('fetches without the lock, with the one warning, when the lock throws', function () {
    Cache::extend('broken-lock', fn () => Cache::repository(new BrokenLockStore));
    config(['cache.stores.broken-lock' => ['driver' => 'broken-lock'], 'snelstart.token_cache.store' => 'broken-lock']);
    $this->fakeSnelstart();

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = [$event->level, $event->message];
    });

    $api = app(SnelstartAPI::class);

    expect($api->getCompanyInfo())->toBe([]);

    $api->forgetToken();
    $api->getCompanyInfo();

    expect(tokenRequests())->toBe(2)
        ->and($lines)->toBe([['warning', 'Snelstart token cache is not available, the token is kept in memory only: The lock backend is gone.']]);
});

it('takes no lock when the token cache is off', function () {
    config(['snelstart.token_cache.enabled' => false]);
    fakeAndWatchTheLock($held);

    app(SnelstartAPI::class)->getCompanyInfo();

    expect($held)->toBeFalse();
});

it('takes no lock when the token comes from memory or from the cache', function () {
    fakeAndWatchTheLock($held);
    app(SnelstartAPI::class)->getCompanyInfo();

    $theirs = Cache::lock(tokenLockKey(), 60);
    $theirs->get();

    $start = microtime(true);

    app(SnelstartAPI::class)->getCompanyInfo();
    app()->forgetInstance(SnelstartAPI::class);
    app(SnelstartAPI::class)->getCompanyInfo();

    expect(tokenRequests())->toBe(1)
        ->and(microtime(true) - $start)->toBeLessThan(1.0);

    $theirs->release();
});

it('takes the lock again for the new token after a refused one', function () {
    $held = [];
    $accepted = 'token-1';
    $issued = 0;

    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$held, &$issued) {
            $held[] = ! tokenLockIsFree();

            return Http::response(['access_token' => 'token-'.(++$issued), 'expires_in' => 3600]);
        },
        'b2bapi.snelstart.nl/*' => function ($request) use (&$accepted) {
            return $request->hasHeader('Authorization', 'Bearer '.$accepted) ? Http::response([]) : Http::response([], 401);
        },
    ]);

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();
    $accepted = 'token-2';
    $api->getCompanyInfo();

    expect($held)->toBe([true, true])
        ->and(tokenLockIsFree())->toBeTrue();
});
