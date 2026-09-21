<?php

use Darvis\Snelstart\Services\EchoService;
use Darvis\Snelstart\Services\SnelstartAPI;
use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function tokenRequestCount(): int
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'auth.snelstart.nl'))
        ->count();
}

/**
 * What the next web request or the next process does: build a new client.
 */
function nextRequest(): SnelstartAPI
{
    app()->forgetInstance(SnelstartAPI::class);

    return app(SnelstartAPI::class);
}

it('keeps the token in the cache, so the next web request does not fetch its own', function () {
    $this->fakeSnelstart();

    app(SnelstartAPI::class)->getCompanyInfo();
    nextRequest()->getCompanyInfo();
    nextRequest()->getRelaties();

    expect(tokenRequestCount())->toBe(1);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/relaties')
        && $request->hasHeader('Authorization', 'Bearer '.TestCase::ACCESS_TOKEN));
});

it('encrypts the cached token and keeps the client key out of the cache key', function () {
    $this->fakeSnelstart();

    app(SnelstartAPI::class)->getCompanyInfo();

    $cached = Cache::get(TestCase::tokenCacheKey());

    expect($cached)->toBeString()
        ->and($cached)->not->toContain(TestCase::ACCESS_TOKEN)
        ->and(Crypt::decryptString($cached))->toContain(TestCase::ACCESS_TOKEN)
        ->and(TestCase::tokenCacheKey())->not->toContain(TestCase::CLIENT_KEY)
        ->and(TestCase::tokenCacheKey())->not->toContain('base64');
});

it('caches the token until sixty seconds before it expires', function () {
    // Stop the clock on a whole second, so the 3540 seconds are exactly that.
    $this->travelTo(now()->startOfSecond());
    $this->fakeSnelstart(expiresIn: 3600);

    app(SnelstartAPI::class)->getCompanyInfo();

    $this->travel(3539)->seconds();
    nextRequest()->getCompanyInfo();
    expect(tokenRequestCount())->toBe(1);

    $this->travel(2)->seconds();
    nextRequest()->getCompanyInfo();
    expect(tokenRequestCount())->toBe(2);
});

it('does not cache a token that lives sixty seconds or less', function () {
    $this->fakeSnelstart(expiresIn: 60);

    app(SnelstartAPI::class)->getCompanyInfo();

    expect(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();
});

it('does not read the cache for every call in one process', function () {
    $this->fakeSnelstart();

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();

    Cache::forget(TestCase::tokenCacheKey());
    $api->getCompanyInfo();

    expect(tokenRequestCount())->toBe(1);
});

it('gives every client key and every token URL its own cached token', function () {
    Http::fake([
        '*/b2b/token' => fn (Request $request) => Http::response([
            'access_token' => 'token-for-'.$request['clientkey'],
            'expires_in' => 3600,
        ]),
        'b2bapi.snelstart.nl/*' => Http::response([]),
    ]);

    config(['snelstart.client_key' => 'administration-one']);
    nextRequest()->getCompanyInfo();

    config(['snelstart.client_key' => 'administration-two']);
    nextRequest()->getCompanyInfo();

    config(['snelstart.token_url' => 'https://auth.example.test/b2b/token']);
    nextRequest()->getCompanyInfo();

    expect(tokenRequestCount())->toBe(2)
        ->and(Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'auth.example.test'))->count())->toBe(1);

    $bearers = Http::recorded()
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'b2bapi'))
        ->map(fn (array $pair) => $pair[0]->header('Authorization')[0])
        ->values()
        ->all();

    expect($bearers)->toBe(['Bearer token-for-administration-one', 'Bearer token-for-administration-two', 'Bearer token-for-administration-two']);
});

it('treats a cached value it cannot decrypt as no token, without throwing or logging the value', function (mixed $garbage) {
    $this->fakeSnelstart();
    Cache::put(TestCase::tokenCacheKey(), $garbage, 3600);

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = $event->message;
    });

    expect(app(SnelstartAPI::class)->getCompanyInfo())->toBe([])
        ->and(tokenRequestCount())->toBe(1)
        ->and($lines)->toBe([])
        ->and(Crypt::decryptString(Cache::get(TestCase::tokenCacheKey())))->toContain(TestCase::ACCESS_TOKEN);
})->with([
    'garbage' => ['not-an-encrypted-value'],
    'a value encrypted with another APP_KEY' => [fn () => (new Encrypter(str_repeat('k', 32), 'AES-256-CBC'))->encryptString('{"access_token":"old","expires_at":9999999999}')],
    'an array' => [['access_token' => 'plain']],
    'valid encryption, wrong content' => [fn () => Crypt::encryptString('"just a string"')],
    'an expired payload' => [fn () => Crypt::encryptString((string) json_encode(['access_token' => 'old', 'expires_at' => now()->subMinute()->getTimestamp()]))],
]);

it('can be turned off, and then the token lives on the instance only', function () {
    config(['snelstart.token_cache.enabled' => false]);
    $this->fakeSnelstart();

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();
    $api->getCompanyInfo();
    expect(tokenRequestCount())->toBe(1);

    nextRequest()->getCompanyInfo();

    expect(tokenRequestCount())->toBe(2)
        ->and(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();
});

it('uses the cache store from the config', function () {
    config([
        'cache.stores.snelstart-test' => ['driver' => 'array', 'serialize' => false],
        'snelstart.token_cache.store' => 'snelstart-test',
    ]);
    $this->fakeSnelstart();

    app(SnelstartAPI::class)->getCompanyInfo();

    expect(Cache::store('snelstart-test')->has(TestCase::tokenCacheKey()))->toBeTrue()
        ->and(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();
});

it('keeps working, with one warning, when the cache store is broken', function () {
    config(['snelstart.token_cache.store' => 'a-store-that-does-not-exist']);
    $this->fakeSnelstart();

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = [$event->level, $event->message];
    });

    $api = app(SnelstartAPI::class);

    expect($api->getCompanyInfo())->toBe([])
        ->and($api->getCompanyInfo())->toBe([])
        ->and($lines)->toHaveCount(1)
        ->and($lines[0][0])->toBe('warning')
        ->and($lines[0][1])->toStartWith('Snelstart token cache is not available, the token is kept in memory only:')
        ->and($lines[0][1])->not->toContain(TestCase::ACCESS_TOKEN);
});

it('keeps working from memory, with one warning, in an application without an APP_KEY', function () {
    config(['app.key' => null]);
    $this->fakeSnelstart();

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = $event->message;
    });

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();
    $api->getCompanyInfo();

    expect(tokenRequestCount())->toBe(1)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toStartWith('Snelstart token cache is not available')
        ->and(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();
});

it('forgets the token in memory and in the cache with forgetToken()', function () {
    $this->fakeSnelstart();

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();
    $api->forgetToken();

    expect(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();

    $api->getCompanyInfo();

    expect(tokenRequestCount())->toBe(2);
});

it('fetches a new token after php artisan cache:clear', function () {
    $this->fakeSnelstart();

    app(SnelstartAPI::class)->getCompanyInfo();
    Artisan::call('cache:clear');
    nextRequest()->getCompanyInfo();

    expect(tokenRequestCount())->toBe(2);
});

it('redacts a token that came from the cache', function () {
    $fail = false;
    $this->fakeSnelstart(function (Request $request) use (&$fail) {
        return $fail
            ? Http::response(['authorization' => $request->header('Authorization')[0]], 500)
            : Http::response([]);
    });

    app(SnelstartAPI::class)->getCompanyInfo();
    $fail = true;

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = $event->message;
    });

    nextRequest();
    $result = app(EchoService::class)->getEchoResource();
    $exit = Artisan::call('snelstart:test');

    expect($exit)->toBe(1)
        ->and(tokenRequestCount())->toBe(1);

    foreach ([$result['error'], $lines[0], Artisan::output()] as $text) {
        expect($text)->toContain('Bearer [redacted]')->not->toContain(TestCase::ACCESS_TOKEN);
    }
});
