<?php

use Darvis\Snelstart\Services\EchoService;
use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @return array<int, Request>
 */
function echoRequests(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), '/echo/resource'))
        ->values()
        ->all();
}

it('sends a GET to the echo endpoint with the sample parameter', function () {
    $this->fakeSnelstart(['echo' => 'ok']);

    $result = app(EchoService::class)->getEchoResource();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Echo resource GET successful')
        ->and($result['query_params'])->toBe(['param1' => 'sample'])
        ->and($result['response'])->toBe(['echo' => 'ok'])
        ->and($result['response_time_ms'])->toBeFloat()
        ->and($result['timestamp'])->toBeString();

    expect(echoRequests()[0]->method())->toBe('GET')
        ->and(echoRequests()[0]->url())->toBe('https://b2bapi.snelstart.nl/v2/echo/resource?param1=sample');
});

it('sends your own parameters instead of the sample', function () {
    $this->fakeSnelstart();

    $result = app(EchoService::class)->getEchoResource(['param1' => 'mine']);

    expect($result['query_params'])->toBe(['param1' => 'mine'])
        ->and(echoRequests()[0]->url())->toEndWith('/echo/resource?param1=mine');
});

it('sends a HEAD to the echo endpoint', function () {
    $this->fakeSnelstart(fn () => Http::response('', 200));

    $result = app(EchoService::class)->headEchoResource();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Echo resource HEAD successful')
        ->and($result['query_params'])->toBe(['param1' => 'sample'])
        ->and($result['response'])->toBe([])
        ->and(echoRequests()[0]->method())->toBe('HEAD');
});

it('posts the sample vehicle, or your own data', function () {
    $this->fakeSnelstart();

    $sample = app(EchoService::class)->postEchoResource();
    $own = app(EchoService::class)->postEchoResource(['key' => 'value']);

    expect($sample['success'])->toBeTrue()
        ->and($sample['message'])->toBe('Echo resource POST successful')
        ->and($sample['request_data'])->toBe(['vehicleType' => 'train', 'maxSpeed' => 125, 'avgSpeed' => 90, 'speedUnit' => 'mph'])
        ->and($own['request_data'])->toBe(['key' => 'value']);

    expect(echoRequests()[0]->method())->toBe('POST')
        ->and(echoRequests()[0]->data())->toBe($sample['request_data'])
        ->and(echoRequests()[1]->data())->toBe(['key' => 'value']);
});

it('never throws: a failed call is logged and returned as a result', function (string $method, string $verb) {
    $this->fakeSnelstart(fn () => Http::response(['message' => 'Too many requests'], 429));

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = [$event->level, $event->message];
    });

    $result = app(EchoService::class)->{$method}();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe("Echo resource {$verb} failed")
        ->and($result['error'])->toContain('HTTP status: 429')
        ->and($result['error_code'])->toBe(429)
        ->and($result)->toHaveKey('timestamp')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0][0])->toBe('error')
        ->and($lines[0][1])->toStartWith("Snelstart Echo Resource {$verb} failed: Snelstart API call failed. HTTP status: 429.");
})->with([
    ['getEchoResource', 'GET'],
    ['headEchoResource', 'HEAD'],
    ['postEchoResource', 'POST'],
]);

it('catches a connection error too', function () {
    $this->fakeSnelstart(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $result = app(EchoService::class)->getEchoResource();

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('cURL error 28');
});

it('keeps the keys and the token out of the log and the result when the API echoes them', function () {
    $this->fakeSnelstart(fn (Request $request) => Http::response([
        'authorization' => $request->header('Authorization')[0],
        'subscription' => $request->header('Ocp-Apim-Subscription-Key')[0],
    ], 500));

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = $event->message;
    });

    $result = app(EchoService::class)->getEchoResource();

    expect($lines)->toHaveCount(1);

    foreach ([$lines[0], $result['error']] as $text) {
        expect($text)
            ->toContain('[redacted]')
            ->not->toContain(TestCase::SUBSCRIPTION_KEY)
            ->not->toContain(TestCase::ACCESS_TOKEN);
    }
});
