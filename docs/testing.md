---
title: "Testing"
nav_order: 7
description: "Test code that uses darvis/snelstart without calling SnelStart: Http::fake() in Laravel, FakeTransport in plain PHP, and how to fake a failure with codes."
---

# Testing

Never call SnelStart from a test: the keys open a real bookkeeping. There are two ways to keep a test away from it.

## In Laravel: Http::fake()

The package sends its requests with Laravel's HTTP client, so `Http::fake()` sees them. Fake two hosts: the token endpoint and the API.

`tests/Feature/SnelstartCustomersTest.php`, for the command of the [Quick start](quickstart.md):

```php
<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // A request you forgot to fake fails the test instead of reaching SnelStart.
    Http::preventStrayRequests();

    config([
        'snelstart.client_key' => 'test-client-key',
        'snelstart.subscription_key' => 'test-subscription-key',
        'cache.default' => 'array',
    ]);
});

function fakeSnelstart(mixed $api): void
{
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
        'b2bapi.snelstart.nl/*' => $api,
    ]);
}

test('it lists the customers', function () {
    fakeSnelstart(Http::response([
        ['relatiecode' => 1001, 'naam' => 'Example B.V.'],
    ]));

    $this->artisan('snelstart:customers')
        ->expectsOutput('1001  Example B.V.')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://b2bapi.snelstart.nl/v2/relaties?')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('it creates a customer', function () {
    fakeSnelstart(Http::response(['id' => 'abc', 'naam' => 'Example B.V.'], 201));

    $this->artisan('snelstart:customers', ['--add' => 'Example B.V.'])->assertExitCode(0);

    // The URL first: the token request is a POST too, and it has no 'naam'.
    Http::assertSent(fn (Request $request) => $request->url() === 'https://b2bapi.snelstart.nl/v2/relaties'
        && $request->method() === 'POST'
        && $request['naam'] === 'Example B.V.');
});

test('it explains what SnelStart refused', function () {
    fakeSnelstart(Http::response([['errorCode' => 'REL-0007']], 400));

    $this->artisan('snelstart:customers', ['--add' => 'x'])
        ->expectsOutput('REL-0007: Naam moet gevuld zijn')
        ->assertExitCode(2);
});

test('it survives SnelStart being down', function () {
    fakeSnelstart(Http::response('Service Unavailable', 503));

    $this->artisan('snelstart:customers')
        ->expectsOutput('SnelStart is not available right now.')
        ->assertExitCode(1);
});
```

Things to know:

- **Set a client key.** Without one the client throws before it sends anything.
- **Keep the test cache on the `array` store**, or set `snelstart.token_cache.enabled` to `false`. Otherwise a token cached by one test travels to the next, and your count of token requests is off.
- **The error body above is an example.** The package finds SnelStart error codes anywhere in a response, so the shape of the body does not matter for your test.
- **A timeout** is faked with a closure that throws: `'b2bapi.snelstart.nl/*' => fn () => throw new ConnectionException('timed out')`. The client turns it into a `SnelstartException` with status `0`.
- **Paging**: `all()` asks for a next page after a full one. A fake that always returns the same 500 rows ends in `Snelstart paging does not advance`; return fewer rows than the page size, or use `Http::sequence()`.

## Without Laravel: FakeTransport

`Darvis\Snelstart\Testing\FakeTransport` is a transport that answers from a list and records what was sent. It works in any test framework, and in Laravel too.

```php
use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;
use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Testing\FakeTransport;

$transport = (new FakeTransport)
    ->respond('relaties', [['relatiecode' => 1001, 'naam' => 'Example B.V.']])
    ->respond('POST https://b2bapi.snelstart.nl/v2/relaties', new Response(400, '[{"errorCode":"REL-0007"}]'));

$snelstart = new Client(new Config('test-client-key', 'test-subscription-key'), $transport);

$snelstart->relaties()->list();          // [['relatiecode' => 1001, 'naam' => 'Example B.V.']]
$snelstart->relaties()->create([]);      // throws a SnelstartException with REL-0007

$transport->sent('relaties');            // the requests: method, url, headers and body
```

- `respond($needle, ...$answers)` answers every request whose `METHOD url` contains `$needle`. The rule added last is tried first.
- An array is a 200 with that array as JSON. A `Response` is that status and body. A `Throwable` is thrown, as a transport that got no response.
- Several answers are given in turn, and the last one repeats: `->respond('relaties', $pageOne, $pageTwo, [])`.
- The token endpoint answers by itself with `FakeTransport::ACCESS_TOKEN`, unless you add a rule for `/token`.
- A request without an answer throws, which the client reports as `Snelstart API call failed. No response: FakeTransport has no answer for ...`.

In Laravel, bind it to use it for the whole application:

```php
$this->app->instance(\Darvis\Snelstart\Http\Transport::class, $transport);
```

## Faking the client itself

For a unit test of your own class you can also mock `Darvis\Snelstart\Client`, or `Snelstart::shouldReceive('companyInfo')` on the facade. `Resource` is final: when your code calls `relaties()->all()`, fake the HTTP layer instead, as above.
