<?php

use Darvis\Snelstart\Support\SnelstartConfig;

/**
 * SnelstartConfig is the one place that reads the package config. These tests guard the two things
 * that go wrong once a default is written down twice: an accessor that disagrees with the config
 * file, and a caller that reaches past the accessor and keeps its own stale fallback.
 */
function snelstartRoot(string $path = ''): string
{
    return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
}

test('the accessors return the values the config file ships', function () {
    $config = require snelstartRoot('config/snelstart.php');

    config(['snelstart' => $config]);

    expect(SnelstartConfig::baseUrl())->toBe($config['base_url'])
        ->and(SnelstartConfig::tokenUrl())->toBe($config['token_url'])
        ->and(SnelstartConfig::clientKey())->toBe('')
        ->and(SnelstartConfig::subscriptionKey())->toBeNull();
});

test('the accessors hold the defaults when the config is missing', function () {
    config(['snelstart' => []]);

    expect(SnelstartConfig::baseUrl())->toBe('https://b2bapi.snelstart.nl/v2')
        ->and(SnelstartConfig::tokenUrl())->toBe('https://auth.snelstart.nl/b2b/token');
});

test('the base URL loses its trailing slash', function () {
    config(['snelstart.base_url' => 'https://example.test/v2/']);

    expect(SnelstartConfig::baseUrl())->toBe('https://example.test/v2');
});

test('an empty subscription key counts as no subscription key', function () {
    config(['snelstart.subscription_key' => '']);
    expect(SnelstartConfig::subscriptionKey())->toBeNull();

    config(['snelstart.subscription_key' => 'test-subscription-key']);
    expect(SnelstartConfig::subscriptionKey())->toBe('test-subscription-key');
});

test('the config file keeps every key of 1.0 and 1.1', function () {
    $config = require snelstartRoot('config/snelstart.php');

    expect(array_keys($config))
        ->toBe(['base_url', 'client_key', 'connect_timeout', 'subscription_key', 'timeout', 'token_cache', 'token_url'])
        ->and(array_keys($config['token_cache']))->toBe(['enabled', 'store']);
});

test('the timeouts are thirty and ten seconds, and anything that is not a positive number is the default', function () {
    $config = require snelstartRoot('config/snelstart.php');

    expect($config['timeout'])->toBe(30)
        ->and($config['connect_timeout'])->toBe(10)
        ->and(SnelstartConfig::timeout())->toBe(30.0)
        ->and(SnelstartConfig::connectTimeout())->toBe(10.0);

    config(['snelstart.timeout' => '45', 'snelstart.connect_timeout' => 2.5]);
    expect(SnelstartConfig::timeout())->toBe(45.0)
        ->and(SnelstartConfig::connectTimeout())->toBe(2.5);

    foreach ([0, -1, 'abc', '', null, true, []] as $value) {
        config(['snelstart.timeout' => $value, 'snelstart.connect_timeout' => $value]);

        expect(SnelstartConfig::timeout())->toBe(30.0)
            ->and(SnelstartConfig::connectTimeout())->toBe(10.0);
    }
});

test('the token cache is on by default, and reads a yes or a no from the environment', function () {
    $config = require snelstartRoot('config/snelstart.php');

    expect($config['token_cache'])->toBe(['enabled' => true, 'store' => null])
        ->and(SnelstartConfig::tokenCacheEnabled())->toBeTrue()
        ->and(SnelstartConfig::tokenCacheStore())->toBeNull();

    foreach ([false, 'false', '0', 0, 'off', 'no'] as $value) {
        config(['snelstart.token_cache.enabled' => $value]);
        expect(SnelstartConfig::tokenCacheEnabled())->toBeFalse();
    }

    foreach ([true, 'true', '1', 1, 'on', null, '', 'maybe', []] as $value) {
        config(['snelstart.token_cache.enabled' => $value]);
        expect(SnelstartConfig::tokenCacheEnabled())->toBeTrue();
    }

    config(['snelstart' => []]);
    expect(SnelstartConfig::tokenCacheEnabled())->toBeTrue();
});

test('an empty cache store name is the default store', function () {
    config(['snelstart.token_cache.store' => '']);
    expect(SnelstartConfig::tokenCacheStore())->toBeNull();

    config(['snelstart.token_cache.store' => 'redis']);
    expect(SnelstartConfig::tokenCacheStore())->toBe('redis');
});

test('nothing outside the accessor reads the package config', function () {
    $offenders = [];

    foreach (['src', 'resources', 'routes'] as $directory) {
        $path = snelstartRoot($directory);

        if (! is_dir($path)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(snelstartRoot().'/', '', $file->getPathname());

            // The accessor is where the reading happens, and the Boost guideline quotes the call
            // it tells you not to write.
            if (str_ends_with($relative, 'SnelstartConfig.php') || str_starts_with($relative, 'resources/boost/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match("/(config\\(|Config::get\\()['\"]snelstart\\./", $contents)) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'these read the config directly instead of through SnelstartConfig');
});

test('the config keys are in alphabetical order, at every level', function () {
    $walk = function (array $config, string $trail) use (&$walk): void {
        $keys = array_keys($config);

        if ($keys !== array_filter($keys, 'is_string')) {
            return;
        }

        $sorted = $keys;
        sort($sorted);

        expect($keys)->toBe($sorted, "the keys in '{$trail}' are not in alphabetical order");

        foreach ($config as $key => $value) {
            if (is_array($value) && $value !== []) {
                $walk($value, $trail.'.'.$key);
            }
        }
    };

    $walk(require snelstartRoot('config/snelstart.php'), 'snelstart');
});
