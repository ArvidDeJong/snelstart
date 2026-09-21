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
     * The subscription key of the B2B developer portal, or null when it is not set or empty.
     */
    public static function subscriptionKey(): ?string
    {
        $key = config('snelstart.subscription_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * URL of the token endpoint.
     */
    public static function tokenUrl(): string
    {
        return (string) config('snelstart.token_url', 'https://auth.snelstart.nl/b2b/token');
    }
}
