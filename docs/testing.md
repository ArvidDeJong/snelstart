---
title: Testing
nav_order: 7
description: "Fake the SnelStart token endpoint and the API with Http::fake() in the tests of your Laravel application, including the failure paths."
---

# Testing

Never call SnelStart from a test: it is somebody's bookkeeping.

## Fake both hosts

The Laravel client makes two kinds of requests, to two hosts. Fake both, and block everything else.

```php
use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    config(['snelstart.client_key' => 'test-client-key']);
});

it('imports the relations', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
        'b2bapi.snelstart.nl/v2/relaties*' => Http::response([
            ['id' => 'r1', 'naam' => 'Example B.V.'],
        ]),
    ]);

    $relations = app(SnelstartAPI::class)->getRelaties();

    expect($relations)->toHaveCount(1);
});
```

- The client throws when it is built without a client key, so set `snelstart.client_key` before you resolve it.
- The client is a singleton that reads the config once. After changing the config, call `app()->forgetInstance(SnelstartAPI::class)`.

## Assert on the request

```php
Http::assertSent(function ($request) {
    return $request->method() === 'POST'
        && $request->url() === 'https://b2bapi.snelstart.nl/v2/relaties'
        && $request['naam'] === 'Example B.V.'
        && $request->hasHeader('Authorization', 'Bearer test-token');
});
```

## The failure paths

```php
use Illuminate\Http\Client\ConnectionException;

Http::fake([
    'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token']),
    'b2bapi.snelstart.nl/*' => Http::response(['message' => 'Too many requests'], 429),
]);

expect(fn () => app(SnelstartAPI::class)->getRelaties())
    ->toThrow(RuntimeException::class, 'HTTP status: 429');
```

For a timeout, throw from the fake:

```php
Http::fake([
    'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token']),
    'b2bapi.snelstart.nl/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
]);
```

## An expired token

The Laravel client uses the application clock, so travel in time:

```php
$api = app(SnelstartAPI::class);
$api->getCompanyInfo();

$this->travel(1)->hours();
$api->getCompanyInfo();

Http::assertSentCount(4);   // two token requests, two API calls
```

## The standalone client

It uses cURL directly, so `Http::fake()` does not see it and `Http::preventStrayRequests()` does not stop it. In a Laravel application, use the Laravel client. The package tests the standalone client against a small server on `127.0.0.1`, see `tests/Fixtures/standalone-server.php` in the repository.
