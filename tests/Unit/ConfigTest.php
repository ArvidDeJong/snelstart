<?php

declare(strict_types=1);

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;

test('the defaults are the SnelStart URLs, 30 seconds and 10 to connect', function () {
    $config = new Config;

    expect($config->baseUrl)->toBe('https://b2bapi.snelstart.nl/v2')
        ->and($config->echoUrl)->toBe('https://b2bapi.snelstart.nl/echo')
        ->and($config->tokenUrl)->toBe('https://auth.snelstart.nl/b2b/token')
        ->and($config->timeout)->toBe(30.0)
        ->and($config->connectTimeout)->toBe(10.0)
        ->and($config->clientKey)->toBe('')
        ->and($config->subscriptionKey)->toBe('');
});

test('fromArray takes what is usable and gives the default for the rest', function () {
    $config = Config::fromArray([
        'base_url' => 'https://example.test/v2/',
        'client_key' => '  key  ',
        'connect_timeout' => '0.5',
        'echo_url' => '',
        'subscription_key' => null,
        'timeout' => 'soon',
        'token_url' => ['not', 'a', 'string'],
        'unknown' => 'ignored',
    ]);

    expect($config->baseUrl)->toBe('https://example.test/v2')
        ->and($config->clientKey)->toBe('key')
        ->and($config->connectTimeout)->toBe(0.5)
        ->and($config->echoUrl)->toBe(Config::ECHO_URL)
        ->and($config->subscriptionKey)->toBe('')
        ->and($config->timeout)->toBe(30.0)
        ->and($config->tokenUrl)->toBe(Config::TOKEN_URL);
});

test('a timeout that is not positive gives the default', function () {
    $config = new Config(timeout: 0, connectTimeout: -1);

    expect($config->timeout)->toBe(30.0)->and($config->connectTimeout)->toBe(10.0);
});

test('fromEnv reads the SNELSTART variables', function () {
    putenv('SNELSTART_CLIENT_KEY=env-client-key');
    putenv('SNELSTART_SUBSCRIPTION_KEY=env-subscription-key');
    putenv('SNELSTART_TIMEOUT=5');

    try {
        $config = Client::fromEnv()->config();

        expect($config->clientKey)->toBe('env-client-key')
            ->and($config->subscriptionKey)->toBe('env-subscription-key')
            ->and($config->timeout)->toBe(5.0)
            ->and($config->baseUrl)->toBe(Config::BASE_URL);
    } finally {
        putenv('SNELSTART_CLIENT_KEY');
        putenv('SNELSTART_SUBSCRIPTION_KEY');
        putenv('SNELSTART_TIMEOUT');
    }
});

test('withClientKey changes the client key and nothing else', function () {
    $config = new Config('one', 'subscription', 'https://example.test/v2', 'https://example.test/echo', 'https://example.test/token', 3, 1);
    $other = $config->withClientKey('two');

    expect($other->clientKey)->toBe('two')
        ->and($config->clientKey)->toBe('one')
        ->and([$other->subscriptionKey, $other->baseUrl, $other->echoUrl, $other->tokenUrl, $other->timeout, $other->connectTimeout])
        ->toBe(['subscription', 'https://example.test/v2', 'https://example.test/echo', 'https://example.test/token', 3.0, 1.0]);
});
