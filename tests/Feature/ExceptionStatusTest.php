<?php

use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Services\EchoService;
use Darvis\Snelstart\Services\SnelstartAPI;
use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function caught(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new LogicException('Expected an exception.');
}

it('throws a SnelstartException with the status of the API response, and the message it always had', function (int $status) {
    $this->fakeSnelstart(fn () => Http::response(['message' => 'Something went wrong'], $status));

    $e = caught(fn () => app(SnelstartAPI::class)->getRelaties());

    expect($e)->toBeInstanceOf(SnelstartException::class)
        ->toBeInstanceOf(RuntimeException::class)
        ->and($e->getCode())->toBe($status)
        ->and($e->status())->toBe($status)
        ->and($e->getMessage())->toBe('Snelstart API call failed. HTTP status: '.$status.'. Response: {"message":"Something went wrong"}');
})->with([400, 401, 403, 404, 429, 500, 503]);

it('carries the status of the token endpoint when that refuses the key', function (int $status) {
    Http::fake(['auth.snelstart.nl/*' => Http::response(['error' => 'invalid_grant'], $status)]);

    $e = caught(fn () => app(SnelstartAPI::class)->getCompanyInfo());

    expect($e)->toBeInstanceOf(SnelstartException::class)
        ->and($e->status())->toBe($status)
        ->and($e->getMessage())->toBe('Failed to retrieve access_token from Snelstart. HTTP status: '.$status.'. Response: {"error":"invalid_grant"}');
})->with([400, 401, 503]);

it('carries the status of a token response without an access_token', function (int $status) {
    Http::fake(['auth.snelstart.nl/*' => Http::response(['token_type' => 'bearer'], $status)]);

    $e = caught(fn () => app(SnelstartAPI::class)->getCompanyInfo());

    expect($e)->toBeInstanceOf(SnelstartException::class)
        ->and($e->status())->toBe($status)
        ->and($e->getMessage())->toBe('Snelstart token response does not contain access_token.');
})->with([200, 201]);

it('has status 0 for an incomplete config, because there was no response', function () {
    config(['snelstart.client_key' => null]);

    $e = caught(fn () => app(SnelstartAPI::class));

    expect($e)->toBeInstanceOf(SnelstartException::class)
        ->and($e->status())->toBe(0)
        ->and($e->getMessage())->toBe('Snelstart API config is incomplete (token_url, client_key).');
});

it('keeps the status on the second 401, after the new token and the repeat', function () {
    $accepted = 'token-1';
    $issued = 0;

    Http::fake([
        'auth.snelstart.nl/*' => function () use (&$issued) {
            return Http::response(['access_token' => 'token-'.(++$issued), 'expires_in' => 3600]);
        },
        'b2bapi.snelstart.nl/*' => function (Request $request) use (&$accepted) {
            return $request->hasHeader('Authorization', 'Bearer '.$accepted)
                ? Http::response([])
                : Http::response(['sent' => $request->header('Authorization')[0]], 401);
        },
    ]);

    $api = app(SnelstartAPI::class);
    $api->getCompanyInfo();
    $accepted = null;

    $e = caught(fn () => $api->getCompanyInfo());

    Http::assertSentCount(5);

    expect($e)->toBeInstanceOf(SnelstartException::class)
        ->and($e->status())->toBe(401)
        ->and($e->getMessage())->toContain('Bearer [redacted]')->not->toContain('token-2');
});

it('still redacts the keys in the message', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['error_description' => 'Unknown clientkey '.TestCase::CLIENT_KEY], 400),
    ]);

    $e = caught(fn () => app(SnelstartAPI::class)->getCompanyInfo());

    expect($e->status())->toBe(400)
        ->and($e->getMessage())->toContain('Unknown clientkey [redacted]')->not->toContain('base64');
});

it('leaves a connection error of the Laravel client as the ConnectionException it was', function () {
    $this->fakeSnelstart(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $e = caught(fn () => app(SnelstartAPI::class)->getRelaties());

    expect($e)->toBeInstanceOf(ConnectionException::class)
        ->not->toBeInstanceOf(SnelstartException::class);
});

it('gives the status as error_code in the echo service', function (string $method, int $status) {
    $this->fakeSnelstart(fn () => Http::response(['message' => 'No'], $status));

    $result = app(EchoService::class)->{$method}();

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe($status)
        ->and($result['error'])->toContain('HTTP status: '.$status);
})->with([
    ['getEchoResource', 401],
    ['headEchoResource', 429],
    ['postEchoResource', 503],
]);

it('gives error_code 0 in the echo service when there was no response', function () {
    $this->fakeSnelstart(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect(app(EchoService::class)->getEchoResource()['error_code'])->toBe(0);
});

it('does not change the output of snelstart:test', function () {
    $this->fakeSnelstart(fn () => Http::response(['message' => 'Access denied'], 403));

    expect(Artisan::call('snelstart:test'))->toBe(1)
        ->and(trim(Artisan::output()))->toBe("Testing Snelstart API connection...\n✗ Connection failed: Snelstart API call failed. HTTP status: 403. Response: {\"message\":\"Access denied\"}");
});
