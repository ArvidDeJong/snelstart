<?php

declare(strict_types=1);

namespace Darvis\Snelstart;

/**
 * The settings of one connection to SnelStart. Immutable; every default is written here once.
 */
final class Config
{
    public const BASE_URL = 'https://b2bapi.snelstart.nl/v2';

    public const ECHO_URL = 'https://b2bapi.snelstart.nl/echo';

    public const TOKEN_URL = 'https://auth.snelstart.nl/b2b/token';

    public const TIMEOUT = 30.0;

    public const CONNECT_TIMEOUT = 10.0;

    public readonly string $baseUrl;

    public readonly string $echoUrl;

    public readonly string $tokenUrl;

    public readonly float $timeout;

    public readonly float $connectTimeout;

    /**
     * @param  string  $clientKey  the key of one administration ("koppelsleutel"), exchanged for an access token
     * @param  string  $subscriptionKey  the key of the B2B developer portal, sent with every call
     * @param  float  $timeout  seconds for a whole request, the token request included
     * @param  float  $connectTimeout  seconds to wait for the connection
     */
    public function __construct(
        #[\SensitiveParameter] public readonly string $clientKey = '',
        #[\SensitiveParameter] public readonly string $subscriptionKey = '',
        string $baseUrl = self::BASE_URL,
        string $echoUrl = self::ECHO_URL,
        string $tokenUrl = self::TOKEN_URL,
        float $timeout = self::TIMEOUT,
        float $connectTimeout = self::CONNECT_TIMEOUT,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->echoUrl = rtrim($echoUrl, '/');
        $this->tokenUrl = $tokenUrl;
        $this->timeout = $timeout > 0 ? $timeout : self::TIMEOUT;
        $this->connectTimeout = $connectTimeout > 0 ? $connectTimeout : self::CONNECT_TIMEOUT;
    }

    /**
     * Build a config from loose values, such as a config file or the environment. A value that
     * is missing, empty or not usable gives the default.
     *
     * @param  array<string, mixed>  $values  base_url, client_key, connect_timeout, echo_url,
     *                                        subscription_key, timeout, token_url
     */
    public static function fromArray(#[\SensitiveParameter] array $values): self
    {
        $string = static fn (string $key, string $default = ''): string => is_scalar($values[$key] ?? null) && trim((string) $values[$key]) !== ''
            ? trim((string) $values[$key])
            : $default;

        $seconds = static fn (string $key, float $default): float => is_numeric($values[$key] ?? null) && (float) $values[$key] > 0
            ? (float) $values[$key]
            : $default;

        return new self(
            clientKey: $string('client_key'),
            subscriptionKey: $string('subscription_key'),
            baseUrl: $string('base_url', self::BASE_URL),
            echoUrl: $string('echo_url', self::ECHO_URL),
            tokenUrl: $string('token_url', self::TOKEN_URL),
            timeout: $seconds('timeout', self::TIMEOUT),
            connectTimeout: $seconds('connect_timeout', self::CONNECT_TIMEOUT),
        );
    }

    /**
     * Build a config from the environment: SNELSTART_CLIENT_KEY, SNELSTART_SUBSCRIPTION_KEY and,
     * optionally, SNELSTART_BASE_URL, SNELSTART_ECHO_URL, SNELSTART_TOKEN_URL, SNELSTART_TIMEOUT
     * and SNELSTART_CONNECT_TIMEOUT.
     */
    public static function fromEnv(): self
    {
        $values = [];

        foreach (['base_url', 'client_key', 'connect_timeout', 'echo_url', 'subscription_key', 'timeout', 'token_url'] as $key) {
            $name = 'SNELSTART_'.strtoupper($key);
            $values[$key] = $_ENV[$name] ?? (getenv($name) !== false ? getenv($name) : null);
        }

        return self::fromArray($values);
    }

    /**
     * The same connection settings for another administration.
     */
    public function withClientKey(#[\SensitiveParameter] string $clientKey): self
    {
        return new self($clientKey, $this->subscriptionKey, $this->baseUrl, $this->echoUrl, $this->tokenUrl, $this->timeout, $this->connectTimeout);
    }
}
