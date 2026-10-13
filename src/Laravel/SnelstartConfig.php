<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Laravel;

use Darvis\Snelstart\Config;

/**
 * The one place that reads the package config. The defaults themselves live in
 * Darvis\Snelstart\Config, so Laravel and plain PHP cannot disagree about them.
 */
final class SnelstartConfig
{
    /**
     * The connection settings: URLs, keys and timeouts.
     */
    public static function connection(): Config
    {
        $values = config('snelstart');

        return Config::fromArray(is_array($values) ? $values : []);
    }

    /**
     * Whether the access token is kept in the cache. Off means in memory on the client only.
     */
    public static function tokenCacheEnabled(): bool
    {
        $enabled = config('snelstart.token_cache.enabled', true);

        // An empty SNELSTART_TOKEN_CACHE= line is "not set", and anything that is not a yes or a no
        // keeps the default.
        if ($enabled === null || $enabled === '') {
            return true;
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * The cache store for the access token, or null for the default store of the application.
     */
    public static function tokenCacheStore(): ?string
    {
        $store = config('snelstart.token_cache.store');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
