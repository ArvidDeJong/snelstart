<?php

use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake both hosts and collect the Guzzle timeout options of every request.
 *
 * @param  array<int, array{0: string, 1: mixed, 2: mixed}>  $seen
 */
function fakeAndCollectTimeouts(array &$seen): void
{
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = [parse_url($request->url(), PHP_URL_HOST), $options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]);
    });
}

it('gives the token request and the API call thirty seconds, and ten to connect', function () {
    $seen = [];
    fakeAndCollectTimeouts($seen);

    app(SnelstartAPI::class)->getCompanyInfo();

    expect($seen)->toEqual([
        ['auth.snelstart.nl', 30, 10],
        ['b2bapi.snelstart.nl', 30, 10],
    ]);
});

it('takes both timeouts from the config', function () {
    config(['snelstart.timeout' => 5, 'snelstart.connect_timeout' => '2.5']);

    $seen = [];
    fakeAndCollectTimeouts($seen);

    app(SnelstartAPI::class)->getCompanyInfo();

    expect($seen)->toEqual([
        ['auth.snelstart.nl', 5, 2.5],
        ['b2bapi.snelstart.nl', 5, 2.5],
    ]);
});

it('falls back to the default for a timeout that is not a positive number', function (mixed $value) {
    config(['snelstart.timeout' => $value, 'snelstart.connect_timeout' => $value]);

    $seen = [];
    fakeAndCollectTimeouts($seen);

    app(SnelstartAPI::class)->getCompanyInfo();

    expect($seen[1])->toEqual(['b2bapi.snelstart.nl', 30, 10]);
})->with([0, -5, 'abc', '', null, [[]], true]);
