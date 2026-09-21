<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Services;

use Carbon\Carbon;
use Darvis\Snelstart\Support\SnelstartConfig;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the SnelStart B2B API inside a Laravel application. It reads its settings from the
 * package config and sends its requests with Laravel's HTTP client, so host apps fake it with
 * Http::fake(). Projects without Laravel use Darvis\Snelstart\Standalone\SnelstartAPI.
 */
class SnelstartAPI
{
    protected string $baseUrl;

    protected string $tokenUrl;

    protected string $clientKey;

    protected ?string $subscriptionKey;

    protected ?string $accessToken = null;

    protected ?Carbon $tokenExpiresAt = null;

    /**
     * True when the token in memory was fetched from the token endpoint during the current call,
     * false when it was held from an earlier call or came from the cache.
     */
    protected bool $tokenIsFresh = false;

    protected float $timeout;

    protected float $connectTimeout;

    protected bool $tokenCacheEnabled;

    protected ?string $tokenCacheStore;

    protected bool $tokenCacheWarningLogged = false;

    public function __construct()
    {
        $this->baseUrl = SnelstartConfig::baseUrl();
        $this->tokenUrl = SnelstartConfig::tokenUrl();
        $this->clientKey = SnelstartConfig::clientKey();
        $this->subscriptionKey = SnelstartConfig::subscriptionKey();
        $this->timeout = SnelstartConfig::timeout();
        $this->connectTimeout = SnelstartConfig::connectTimeout();
        $this->tokenCacheEnabled = SnelstartConfig::tokenCacheEnabled();
        $this->tokenCacheStore = SnelstartConfig::tokenCacheStore();

        if (! $this->tokenUrl || ! $this->clientKey) {
            throw new \RuntimeException(
                'Snelstart API config is incomplete (token_url, client_key).'
            );
        }
    }

    /* -----------------------------------------------------------------
     |  Public convenience methods
     | -----------------------------------------------------------------
     */

    /**
     * GET /companyInfo: the administration the keys belong to.
     *
     * @return array<mixed>
     */
    public function getCompanyInfo(): array
    {
        return $this->get('/companyInfo');
    }

    /**
     * GET /relaties: the relations (customers and suppliers).
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function getRelaties(array $query = []): array
    {
        return $this->get('/relaties', $query);
    }

    /**
     * POST /relaties: create a relation.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function createRelatie(array $data): array
    {
        return $this->post('/relaties', $data);
    }

    /**
     * GET /artikelen: the articles.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function getArtikelen(array $query = []): array
    {
        return $this->get('/artikelen', $query);
    }

    /**
     * POST /verkooporders: create a sales order.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function createVerkooporder(array $data): array
    {
        return $this->post('/verkooporders', $data);
    }

    /* -----------------------------------------------------------------
     |  HTTP helpers
     | -----------------------------------------------------------------
     */

    /**
     * GET any endpoint below the base URL.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->request('GET', $uri, $options);
    }

    /**
     * POST to any endpoint below the base URL. An empty array sends no body.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function post(string $uri, array $data = []): array
    {
        $options = [];
        if ($data !== []) {
            $options['json'] = $data;
        }

        return $this->request('POST', $uri, $options);
    }

    /**
     * PUT to any endpoint below the base URL. An empty array sends no body.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function put(string $uri, array $data = []): array
    {
        $options = [];
        if ($data !== []) {
            $options['json'] = $data;
        }

        return $this->request('PUT', $uri, $options);
    }

    /**
     * DELETE any endpoint below the base URL.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function delete(string $uri, array $query = []): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->request('DELETE', $uri, $options);
    }

    /**
     * HEAD any endpoint below the base URL. The result is always an empty array.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function head(string $uri, array $query = []): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->request('HEAD', $uri, $options);
    }

    /**
     * Central request method: automatically adds Bearer token + subscription key.
     *
     * @param  array<string, mixed>  $options  'query' and 'json'
     * @return array<mixed>
     *
     * @throws \RuntimeException when the API answers with a 4xx or 5xx status
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        $response = $this->sendRequest($method, $uri, $options);

        // A 401 on a token that was held or cached: SnelStart dropped it before it expired. Get a
        // new one and repeat the call, once. A 401 on a token of a moment ago is not about the token.
        if ($response->status() === 401 && ! $this->tokenIsFresh) {
            $this->forgetToken();

            $response = $this->sendRequest($method, $uri, $options);
        }

        if ($response->failed()) {
            $this->handleError($response);
        }

        // A body that is valid JSON but not an object or a list ("ok", 42, true) is not an array.
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Send one request with the current token, or with a new one when there is none.
     *
     * @param  array<string, mixed>  $options  'query' and 'json'
     */
    protected function sendRequest(string $method, string $uri, array $options = []): Response
    {
        $token = $this->getAccessToken();

        $request = $this->withTimeouts(Http::withToken($token))
            ->baseUrl($this->baseUrl)
            ->acceptJson();

        if (! empty($this->subscriptionKey)) {
            $request = $request->withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
            ]);
        }

        return $request->send($method, '/'.ltrim($uri, '/'), $options);
    }

    protected function withTimeouts(PendingRequest $request): PendingRequest
    {
        return $request->withOptions([
            'connect_timeout' => $this->connectTimeout,
            'timeout' => $this->timeout,
        ]);
    }

    /* -----------------------------------------------------------------
     |  Token handling (grant_type = clientkey)
     | -----------------------------------------------------------------
     */

    /**
     * Drop the access token, in memory and in the cache. The next call fetches a new one.
     */
    public function forgetToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = null;
        $this->tokenIsFresh = false;

        $this->tokenCache(fn (Repository $cache) => $cache->forget($this->tokenCacheKey()));
    }

    protected function getAccessToken(): string
    {
        if (
            $this->accessToken !== null &&
            $this->tokenExpiresAt !== null &&
            $this->tokenExpiresAt->isFuture()
        ) {
            $this->tokenIsFresh = false;

            return $this->accessToken;
        }

        $cached = $this->restoreTokenFromCache();

        if ($cached !== null) {
            $this->tokenIsFresh = false;

            return $cached;
        }

        // SnelStart-specific authentication flow:
        // grant_type=clientkey & clientkey=<custom-key>
        $payload = [
            'grant_type' => 'clientkey',
            'clientkey' => $this->clientKey,
        ];

        $response = $this->withTimeouts(Http::asForm())
            ->acceptJson()
            ->post($this->tokenUrl, $payload);

        if ($response->failed()) {
            $this->handleError($response, 'Failed to retrieve access_token from Snelstart.');
        }

        $data = $response->json();

        if (! isset($data['access_token'])) {
            throw new \RuntimeException('Snelstart token response does not contain access_token.');
        }

        $token = (string) $data['access_token'];

        $this->accessToken = $token;
        $this->tokenIsFresh = true;

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->tokenExpiresAt = Carbon::now()->addSeconds($expiresIn - 60);

        $this->storeTokenInCache();

        return $token;
    }

    /**
     * The key differs per token URL and per client key, so two administrations never share a
     * token. Both are hashed: a cache key is readable for whoever can list the store.
     */
    protected function tokenCacheKey(): string
    {
        return 'snelstart.token.'.hash('sha256', $this->tokenUrl.'|'.$this->clientKey);
    }

    /**
     * Take the token from the cache into memory and return it, or null. Anything that is not a token this application encrypted, and
     * that is still valid, counts as no token and is removed.
     */
    protected function restoreTokenFromCache(): ?string
    {
        $cached = $this->tokenCache(fn (Repository $cache) => $cache->get($this->tokenCacheKey()));

        if ($cached === null) {
            return null;
        }

        try {
            $data = is_string($cached) ? json_decode(Crypt::decryptString($cached), true) : null;
        } catch (\Throwable) {
            // Encrypted with another APP_KEY, or not encrypted at all.
            $data = null;
        }

        if (
            is_array($data) &&
            is_string($data['access_token'] ?? null) &&
            $data['access_token'] !== '' &&
            is_int($data['expires_at'] ?? null) &&
            $data['expires_at'] > Carbon::now()->getTimestamp()
        ) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = Carbon::createFromTimestamp($data['expires_at']);

            return $this->accessToken;
        }

        $this->tokenCache(fn (Repository $cache) => $cache->forget($this->tokenCacheKey()));

        return null;
    }

    /**
     * Put the token in the cache, encrypted, for as long as the instance itself would keep it.
     */
    protected function storeTokenInCache(): void
    {
        if ($this->accessToken === null || $this->tokenExpiresAt === null || ! $this->tokenExpiresAt->isFuture()) {
            return;
        }

        $token = $this->accessToken;
        $expiresAt = $this->tokenExpiresAt->getTimestamp();

        // Whole seconds, counted here: how a cache repository turns a date into a number of seconds
        // differs between Laravel and Carbon versions, and rounding down costs the last second.
        $seconds = $expiresAt - Carbon::now()->getTimestamp();

        if ($seconds < 1) {
            return;
        }

        $this->tokenCache(fn (Repository $cache) => $cache->put(
            $this->tokenCacheKey(),
            Crypt::encryptString((string) json_encode(['access_token' => $token, 'expires_at' => $expiresAt])),
            $seconds,
        ));
    }

    /**
     * Run something against the token cache. The cache only saves a token request: when it is
     * turned off, or the store is down or does not exist, the client works from memory.
     *
     * @param  callable(Repository): mixed  $callback
     */
    protected function tokenCache(callable $callback): mixed
    {
        if (! $this->tokenCacheEnabled) {
            return null;
        }

        try {
            return $callback(Cache::store($this->tokenCacheStore));
        } catch (\Throwable $e) {
            if (! $this->tokenCacheWarningLogged) {
                $this->tokenCacheWarningLogged = true;

                Log::warning('Snelstart token cache is not available, the token is kept in memory only: '.$this->redactSecrets($e->getMessage()));
            }

            return null;
        }
    }

    /* -----------------------------------------------------------------
     |  Error handling
     | -----------------------------------------------------------------
     */

    protected function handleError(Response $response, ?string $prefixMessage = null): void
    {
        $status = $response->status();
        $body = $response->json() ?? $response->body();

        $message = $prefixMessage ?: 'Snelstart API call failed.';
        $message .= " HTTP status: {$status}.";

        if (is_array($body)) {
            $message .= ' Response: '.json_encode($body);
        } elseif ($body !== '') {
            $message .= ' Response: '.$body;
        }

        throw new \RuntimeException($this->redactSecrets($message));
    }

    /**
     * Replace the client key, the subscription key and the access token in a message. An error
     * response can echo what was sent, and the message ends up in logs and in command output.
     */
    protected function redactSecrets(string $message): string
    {
        $search = [];

        foreach ([$this->clientKey, $this->subscriptionKey, $this->accessToken] as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            // As it is, as it looks inside a JSON string, and as it looks in a form body or a URL.
            $search[] = $secret;
            $search[] = trim((string) json_encode($secret), '"');
            $search[] = urlencode($secret);
            $search[] = rawurlencode($secret);
        }

        return str_replace(array_unique($search), '[redacted]', $message);
    }
}
