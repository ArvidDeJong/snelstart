<?php

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('Http::fake sees the token request and the call, with the headers and the body', function () {
    $this->fakeSnelstart(['id' => 'abc']);

    expect(app(Client::class)->relaties()->create(['naam' => 'Jansen']))->toBe(['id' => 'abc']);

    Http::assertSentCount(2);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.snelstart.nl/b2b/token'
        && $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->body() === 'grant_type=clientkey&clientkey='.urlencode(TestCase::CLIENT_KEY));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://b2bapi.snelstart.nl/v2/relaties'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer '.TestCase::ACCESS_TOKEN)
        && $request->hasHeader('Ocp-Apim-Subscription-Key', TestCase::SUBSCRIPTION_KEY)
        && $request->hasHeader('Accept', 'application/json')
        && $request->header('Content-Type') === ['application/json']
        && $request->body() === '{"naam":"Jansen"}');
});

test('the query string arrives as the client built it', function () {
    $this->fakeSnelstart([]);

    app(Client::class)->relaties()->list(['$filter' => "naam eq 'Jansen & Zn'", '$top' => 5]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://b2bapi.snelstart.nl/v2/relaties?%24filter=naam%20eq%20%27Jansen%20%26%20Zn%27&%24top=5'
        || str_contains($request->url(), 'auth.snelstart.nl'));

    expect(Http::recorded()->last()[0]->url())->toBe('https://b2bapi.snelstart.nl/v2/relaties?%24filter=naam%20eq%20%27Jansen%20%26%20Zn%27&%24top=5');
});

test('every request carries the timeouts and follows no redirect', function () {
    config(['snelstart.timeout' => 7, 'snelstart.connect_timeout' => 0.5]);

    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        // A redirect would carry the keys to another host.
        expect($requestOptions['allow_redirects'])->toBeFalse();

        $options[] = [$requestOptions['timeout'], $requestOptions['connect_timeout']];

        return Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 3600]);
    });

    app(Client::class)->get('relaties');
    app(Client::class)->echo();

    expect($options)->toBe([[7.0, 0.5], [7.0, 0.5], [7.0, 0.5]]);
});

test('a 4xx is the exception of the package, never one of the HTTP client', function () {
    $this->fakeSnelstart(fn () => Http::response(['message' => 'Rate limit'], 429));

    expect(fn () => app(Client::class)->get('relaties'))->toThrow(function (SnelstartException $e) {
        expect($e->status())->toBe(429)
            ->and($e->getMessage())->toBe('Snelstart API call failed. HTTP status: 429. Response: {"message":"Rate limit"}');
    });
});

test('a connection error is a SnelstartException with status 0 and the cause as previous', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 3600]),
        'b2bapi.snelstart.nl/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    expect(fn () => app(Client::class)->get('relaties'))->toThrow(function (SnelstartException $e) {
        expect($e->status())->toBe(0)
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class)
            ->and($e->getMessage())->toBe('Snelstart API call failed. No response: cURL error 28: Operation timed out');
    });
});

test('a request that is not faked fails the test instead of reaching SnelStart', function () {
    Http::fake(['auth.snelstart.nl/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 3600])]);

    expect(fn () => app(Client::class)->get('relaties'))->toThrow(SnelstartException::class, 'No response');
});
