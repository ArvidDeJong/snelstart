<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Standalone;

use DateTime;

/**
 * Standalone Snelstart API client for projects without Laravel. It takes its settings as an array
 * (or from the environment) and uses native PHP cURL for HTTP requests, so Http::fake() does not
 * see it. Inside a Laravel application use Darvis\Snelstart\Services\SnelstartAPI instead.
 */
class SnelstartAPI
{
    protected string $baseUrl;

    protected string $tokenUrl;

    protected string $clientKey;

    protected ?string $subscriptionKey;

    protected ?string $accessToken = null;

    protected ?DateTime $tokenExpiresAt = null;

    /**
     * True when the token in memory was fetched from the token endpoint during the current call,
     * false when it was held from an earlier call.
     */
    protected bool $tokenIsFresh = false;

    /**
     * Seconds for a whole request, the token request included. The default of the Laravel client.
     */
    protected float $timeout = 30.0;

    /**
     * Seconds to wait for the connection. The default of the Laravel client.
     */
    protected float $connectTimeout = 10.0;

    /**
     * @param  array<string, mixed>  $config  base_url, token_url, client_key, subscription_key,
     *                                        timeout and connect_timeout (seconds, default 30 and 10)
     */
    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://b2bapi.snelstart.nl/v2', '/');
        $this->tokenUrl = $config['token_url'] ?? 'https://auth.snelstart.nl/b2b/token';
        $this->clientKey = $config['client_key'] ?? '';
        $this->subscriptionKey = $config['subscription_key'] ?? null;
        $this->timeout = self::positiveNumber($config['timeout'] ?? null, 30.0);
        $this->connectTimeout = self::positiveNumber($config['connect_timeout'] ?? null, 10.0);

        if (empty($this->tokenUrl) || empty($this->clientKey)) {
            throw new \RuntimeException(
                'Snelstart API config is incomplete (token_url, client_key).'
            );
        }
    }

    /**
     * Create instance from environment variables.
     */
    public static function fromEnv(): self
    {
        return new self([
            'base_url' => getenv('SNELSTART_BASE_URL') ?: 'https://b2bapi.snelstart.nl/v2',
            'token_url' => getenv('SNELSTART_TOKEN_URL') ?: 'https://auth.snelstart.nl/b2b/token',
            'client_key' => getenv('SNELSTART_CLIENT_KEY') ?: '',
            'subscription_key' => getenv('SNELSTART_SUBSCRIPTION_KEY') ?: null,
            'timeout' => getenv('SNELSTART_TIMEOUT') ?: null,
            'connect_timeout' => getenv('SNELSTART_CONNECT_TIMEOUT') ?: null,
        ]);
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
        return $this->request('GET', $uri, ['query' => $query]);
    }

    /**
     * POST to any endpoint below the base URL. An empty array sends no body.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function post(string $uri, array $data = []): array
    {
        return $this->request('POST', $uri, ['json' => $data]);
    }

    /**
     * PUT to any endpoint below the base URL. An empty array sends no body.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function put(string $uri, array $data = []): array
    {
        return $this->request('PUT', $uri, ['json' => $data]);
    }

    /**
     * DELETE any endpoint below the base URL.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function delete(string $uri, array $query = []): array
    {
        return $this->request('DELETE', $uri, ['query' => $query]);
    }

    /**
     * HEAD any endpoint below the base URL. The result is always an empty array.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function head(string $uri, array $query = []): array
    {
        return $this->request('HEAD', $uri, ['query' => $query]);
    }

    /**
     * Central request method: automatically adds Bearer token + subscription key.
     *
     * @param  non-empty-string  $method
     * @param  array<string, mixed>  $options  'query' and 'json'
     * @return array<mixed>
     *
     * @throws \RuntimeException when the API answers with a 4xx or 5xx status
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        [$httpCode, $response] = $this->sendRequest($method, $uri, $options);

        // A 401 on a token that was held: SnelStart dropped it before it expired. Get a new one and
        // repeat the call, once. A 401 on a token of a moment ago is not about the token.
        if ($httpCode === 401 && ! $this->tokenIsFresh) {
            $this->forgetToken();

            [$httpCode, $response] = $this->sendRequest($method, $uri, $options);
        }

        if ($httpCode >= 400) {
            $this->handleError($httpCode, $response);
        }

        if ($response === '') {
            return [];
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Send one request with the current token, or with a new one when there is none.
     *
     * @param  non-empty-string  $method
     * @param  array<string, mixed>  $options  'query' and 'json'
     * @return array{0: int, 1: string} the HTTP status and the body
     *
     * @throws \RuntimeException when the server cannot be reached or does not answer in time
     */
    protected function sendRequest(string $method, string $uri, array $options = []): array
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl.'/'.ltrim($uri, '/');

        // Add query parameters to URL
        if (! empty($options['query'])) {
            $url .= '?'.http_build_query($options['query']);
        }

        $headers = [
            'Authorization: Bearer '.$token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if (! empty($this->subscriptionKey)) {
            $headers[] = 'Ocp-Apim-Subscription-Key: '.$this->subscriptionKey;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $this->applyTimeouts($ch);

        if ($method === 'HEAD') {
            // A HEAD response announces a Content-Length but has no body. Without this cURL waits
            // for that body until the server closes the connection, and then reports an error.
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        if (in_array($method, ['POST', 'PUT']) && ! empty($options['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($options['json']));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('cURL error: '.$error);
        }

        return [$httpCode, is_string($response) ? $response : ''];
    }

    /**
     * Without these cURL waits for as long as the server keeps the connection open. The
     * millisecond options are the same limits as CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT, and
     * allow half a second; NOSIGNAL is what libcurl needs for a limit below one second.
     */
    protected function applyTimeouts(\CurlHandle $ch): void
    {
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, max(1, (int) round($this->timeout * 1000)));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, max(1, (int) round($this->connectTimeout * 1000)));
    }

    /**
     * A timeout that is not a positive number is the default.
     */
    protected static function positiveNumber(mixed $value, float $default): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }

    /* -----------------------------------------------------------------
     |  Token handling (grant_type = clientkey)
     | -----------------------------------------------------------------
     */

    /**
     * Drop the access token. The next call fetches a new one.
     */
    public function forgetToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = null;
        $this->tokenIsFresh = false;
    }

    protected function getAccessToken(): string
    {
        if (
            $this->accessToken !== null &&
            $this->tokenExpiresAt !== null &&
            $this->tokenExpiresAt > new DateTime
        ) {
            $this->tokenIsFresh = false;

            return $this->accessToken;
        }

        // SnelStart-specific authentication flow:
        // grant_type=clientkey & clientkey=<custom-key>
        if ($this->tokenUrl === '') {
            throw new \RuntimeException('Snelstart API config is incomplete (token_url, client_key).');
        }

        $payload = http_build_query([
            'grant_type' => 'clientkey',
            'clientkey' => $this->clientKey,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        $this->applyTimeouts($ch);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Failed to retrieve access_token: cURL error: '.$error);
        }

        $response = is_string($response) ? $response : '';

        if ($httpCode >= 400) {
            throw new \RuntimeException($this->redactSecrets(
                'Failed to retrieve access_token from Snelstart. HTTP status: '.$httpCode.'. Response: '.$response
            ));
        }

        $data = json_decode($response, true);

        if (! isset($data['access_token'])) {
            throw new \RuntimeException('Snelstart token response does not contain access_token.');
        }

        $this->accessToken = (string) $data['access_token'];
        $this->tokenIsFresh = true;

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->tokenExpiresAt = (new DateTime)->modify('+'.($expiresIn - 60).' seconds');

        return $this->accessToken;
    }

    /* -----------------------------------------------------------------
     |  Error handling
     | -----------------------------------------------------------------
     */

    protected function handleError(int $httpCode, string $response): void
    {
        $message = 'Snelstart API call failed. HTTP status: '.$httpCode.'.';

        if (! empty($response)) {
            $message .= ' Response: '.$response;
        }

        throw new \RuntimeException($this->redactSecrets($message));
    }

    /**
     * Replace the client key, the subscription key and the access token in a message. An error
     * response can echo what was sent, and the message ends up in logs and on screens.
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
