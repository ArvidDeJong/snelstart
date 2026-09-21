---
title: "Testing"
nav_order: 7
description: "Test Laravel code that uses darvis/snelstart without calling SnelStart: a complete Pest example with Http::fake(), the cached token and an expired token."
---

# Testing

Never call SnelStart from a test: it is somebody's bookkeeping. The Laravel client sends everything through Laravel's HTTP client, so `Http::fake()` (which answers requests without sending them) covers it.

## A complete test

This tests the controller of the [Quick start](quickstart.md). The client makes two kinds of requests, to two hosts: the token endpoint and the API. Fake both, and block everything else.

`tests/Feature/SnelstartCompanyTest.php`

```php
<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // A request that no fake answers fails the test instead of reaching SnelStart.
    Http::preventStrayRequests();

    // Without a client key the client throws the moment it is built.
    config(['snelstart.client_key' => 'test-client-key']);
});

it('shows the company info', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
        'b2bapi.snelstart.nl/v2/companyInfo' => Http::response(['naam' => 'Example B.V.']),
    ]);

    $this->get('/snelstart/company')
        ->assertOk()
        ->assertJson(['naam' => 'Example B.V.']);

    Http::assertSent(fn ($request) => $request->url() === 'https://b2bapi.snelstart.nl/v2/companyInfo'
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('says so when SnelStart refuses the call', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token']),
        'b2bapi.snelstart.nl/*' => Http::response(['message' => 'Too many requests'], 429),
    ]);

    $this->get('/snelstart/company')
        ->assertStatus(502)
        ->assertJson(['error' => 'Too many calls, try again later.']);
});

it('says so when SnelStart does not answer', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token']),
        'b2bapi.snelstart.nl/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    $this->get('/snelstart/company')->assertStatus(504);
});
```

The example is written for Pest. The first test proves the token was fetched and sent; the second and third prove what your user sees when SnelStart refuses or is silent. The response bodies in the fakes are made up for the test: the package does not care what is in them.

Two things to remember:

- Set `snelstart.client_key` before the client is used, as `beforeEach` does.
- The client is a singleton that reads the config once. After changing the config inside a test, call `app()->forgetInstance(\Darvis\Snelstart\Services\SnelstartAPI::class)`.

## The cached token

The client keeps its token in the cache. With the `array` cache store, which the `phpunit.xml` of a new Laravel application sets with `CACHE_STORE=array`, every test starts without a token and nothing changes for you.

When your tests run on a store that persists (file, Redis, database), the token of one test is still there in the next: the token endpoint is not called again, so an `Http::assertSentCount()` is one lower than you expect, and a fake that returns another token is ignored. Pick one:

```xml
<env name="SNELSTART_TOKEN_CACHE" value="false"/>
```

```php
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());
```

The first goes in `phpunit.xml`, inside `<php>`; the second in the test file or in `tests/Pest.php`. Inside a test, `app(SnelstartAPI::class)->forgetToken()` drops the token, in memory and in the cache.

## Assert on the request a write sends

```php
Http::assertSent(function ($request) {
    return $request->method() === 'POST'
        && $request->url() === 'https://b2bapi.snelstart.nl/v2/relaties'
        && $request['naam'] === 'Example B.V.'
        && $request->hasHeader('Authorization', 'Bearer test-token');
});
```

## Assert on the status of a failure

When you test a class of your own instead of a URL, assert on the exception and its status:

```php
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Support\Facades\Http;

it('gets a 429 as a SnelstartException', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token']),
        'b2bapi.snelstart.nl/*' => Http::response(['message' => 'Too many requests'], 429),
    ]);

    try {
        app(SnelstartAPI::class)->getRelaties();
        $this->fail('Expected a SnelstartException.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(429)
            ->and($e->getMessage())->toContain('HTTP status: 429');
    }
});
```

`SnelstartException` extends `RuntimeException`, so an assertion on `RuntimeException::class` you already have still passes.

## An expired token

The Laravel client uses the application clock, so travel in time. With the fakes of the first test in place:

```php
$api = app(SnelstartAPI::class);
$api->getCompanyInfo();

$this->travel(1)->hours();
$api->getCompanyInfo();

Http::assertSentCount(4);   // two token requests, two API calls
```

## A refused token

A 401 on a token the client already had gives one new token and one repeat. A fake that always answers 401 therefore sees the call twice when an earlier call in the same test succeeded, and once when it is the first call:

```php
$accepted = 'first-token';
$issued = 0;

Http::fake([
    'auth.snelstart.nl/*' => function () use (&$issued) {
        return Http::response(['access_token' => ++$issued === 1 ? 'first-token' : 'second-token']);
    },
    'b2bapi.snelstart.nl/*' => function ($request) use (&$accepted) {
        return $request->hasHeader('Authorization', 'Bearer '.$accepted)
            ? Http::response(['id' => 'r1'])
            : Http::response([], 401);
    },
]);

$api = app(SnelstartAPI::class);
$api->getCompanyInfo();

$accepted = 'second-token';   // from now on the first token is refused

expect($api->getCompanyInfo())->toBe(['id' => 'r1']);
```

## Why Http::fake() does not cover the standalone client

It uses cURL directly, so `Http::fake()` does not see it and `Http::preventStrayRequests()` does not stop it. In a Laravel application, use the Laravel client. The package tests the standalone client against a small server on `127.0.0.1`, see `tests/Fixtures/standalone-server.php` in the repository.
