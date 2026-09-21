<?php

use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('reports a working connection and prints the company info', function () {
    $this->fakeSnelstart(['naam' => 'Example B.V.', 'administratieIdentifier' => 'example-id']);

    $exit = Artisan::call('snelstart:test');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Testing Snelstart API connection...')
        ->toContain('Connection successful!')
        ->toContain('Company info retrieved.')
        ->toContain('"naam": "Example B.V."');
});

it('does not print the company info line when the API returns nothing', function () {
    $this->fakeSnelstart([]);

    $exit = Artisan::call('snelstart:test');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Connection successful!')->not->toContain('Company info retrieved.');
});

it('never prints a key or the token on success', function () {
    $this->fakeSnelstart(['naam' => 'Example B.V.']);

    Artisan::call('snelstart:test', ['-vvv' => true]);

    expect(Artisan::output())
        ->not->toContain(TestCase::CLIENT_KEY)
        ->not->toContain(TestCase::SUBSCRIPTION_KEY)
        ->not->toContain(TestCase::ACCESS_TOKEN);
});

it('fails with the status when the API refuses the call', function () {
    $this->fakeSnelstart(fn () => Http::response(['message' => 'Access denied'], 401));

    $exit = Artisan::call('snelstart:test');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Connection failed: Snelstart API call failed. HTTP status: 401.');
});

it('does not print the client key when the token endpoint echoes it', function () {
    Http::fake([
        'auth.snelstart.nl/*' => Http::response(['error_description' => 'Unknown clientkey '.TestCase::CLIENT_KEY], 400),
    ]);

    $exit = Artisan::call('snelstart:test');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Connection failed')
        ->toContain('[redacted]')
        ->not->toContain('base64');
});

it('fails cleanly on a timeout', function () {
    $this->fakeSnelstart(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect(Artisan::call('snelstart:test'))->toBe(1)
        ->and(Artisan::output())->toContain('Connection failed: cURL error 28');
});

it('fails cleanly instead of crashing when the client key is missing', function () {
    config(['snelstart.client_key' => null]);

    $exit = Artisan::call('snelstart:test');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Connection failed: Snelstart API config is incomplete (token_url, client_key).');
});
