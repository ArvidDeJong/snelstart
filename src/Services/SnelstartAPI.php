<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Services;

use Carbon\Carbon;
use Darvis\Snelstart\Support\SnelstartConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

    public function __construct()
    {
        $this->baseUrl = SnelstartConfig::baseUrl();
        $this->tokenUrl = SnelstartConfig::tokenUrl();
        $this->clientKey = SnelstartConfig::clientKey();
        $this->subscriptionKey = SnelstartConfig::subscriptionKey();

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
        $token = $this->getAccessToken();

        $request = Http::withToken($token)
            ->baseUrl($this->baseUrl)
            ->acceptJson();

        if (! empty($this->subscriptionKey)) {
            $request = $request->withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
            ]);
        }

        $uri = '/'.ltrim($uri, '/');

        /** @var Response $response */
        $response = $request->send($method, $uri, $options);

        if ($response->failed()) {
            $this->handleError($response);
        }

        // A body that is valid JSON but not an object or a list ("ok", 42, true) is not an array.
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /* -----------------------------------------------------------------
     |  Token handling (grant_type = clientkey)
     | -----------------------------------------------------------------
     */

    protected function getAccessToken(): string
    {
        if (
            $this->accessToken !== null &&
            $this->tokenExpiresAt !== null &&
            $this->tokenExpiresAt->isFuture()
        ) {
            return $this->accessToken;
        }

        // SnelStart-specific authentication flow:
        // grant_type=clientkey & clientkey=<custom-key>
        $payload = [
            'grant_type' => 'clientkey',
            'clientkey' => $this->clientKey,
        ];

        $response = Http::asForm()
            ->acceptJson()
            ->post($this->tokenUrl, $payload);

        if ($response->failed()) {
            $this->handleError($response, 'Failed to retrieve access_token from Snelstart.');
        }

        $data = $response->json();

        if (! isset($data['access_token'])) {
            throw new \RuntimeException('Snelstart token response does not contain access_token.');
        }

        $this->accessToken = (string) $data['access_token'];

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->tokenExpiresAt = Carbon::now()->addSeconds($expiresIn - 60);

        return $this->accessToken;
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
