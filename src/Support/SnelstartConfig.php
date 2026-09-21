<?php

namespace Darvis\Snelstart\Support;

/**
 * The one place that reads the package config. Callers ask this class, so a default is written
 * once and a caller cannot quietly disagree with config/snelstart.php about what it is.
 */
final class SnelstartConfig
{
    /**
     * Base URL of the SnelStart B2B API, without a trailing slash.
     */
    public static function baseUrl(): string
    {
        return rtrim((string) config('snelstart.base_url', 'https://b2bapi.snelstart.nl/v2'), '/');
    }

    /**
     * The client key that is exchanged for an access token, or an empty string when it is not set.
     */
    public static function clientKey(): string
    {
        return (string) config('snelstart.client_key');
    }

    /**
     * Seconds to wait for the connection. A value that is not a positive number gives the default.
     */
    public static function connectTimeout(): float
    {
        return self::positiveNumber(config('snelstart.connect_timeout'), 10.0);
    }

    /**
     * The subscription key of the B2B developer portal, or null when it is not set or empty.
     */
    public static function subscriptionKey(): ?string
    {
        $key = config('snelstart.subscription_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Seconds to wait for a whole request. A value that is not a positive number gives the default.
     */
    public static function timeout(): float
    {
        return self::positiveNumber(config('snelstart.timeout'), 30.0);
    }

    /**
     * Whether the access token is kept in the cache. Off means in memory on the instance only.
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

    /**
     * URL of the token endpoint.
     */
    public static function tokenUrl(): string
    {
        return (string) config('snelstart.token_url', 'https://auth.snelstart.nl/b2b/token');
    }

    private static function positiveNumber(mixed $value, float $default): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }
}
