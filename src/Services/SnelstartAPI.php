<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
        $this->baseUrl = rtrim((string) config('snelstart.base_url'), '/');
        $this->tokenUrl = (string) config('snelstart.token_url');
        $this->clientKey = (string) config('snelstart.client_key');
        $this->subscriptionKey = config('snelstart.subscription_key');

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

    public function getCompanyInfo(): array
    {
        return $this->get('/companyInfo');
    }

    public function getRelaties(array $query = []): array
    {
        return $this->get('/relaties', $query);
    }

    public function createRelatie(array $data): array
    {
        return $this->post('/relaties', $data);
    }

    public function getArtikelen(array $query = []): array
    {
        return $this->get('/artikelen', $query);
    }

    public function createVerkooporder(array $data): array
    {
        return $this->post('/verkooporders', $data);
    }

    /* -----------------------------------------------------------------
     |  HTTP helpers
     | -----------------------------------------------------------------
     */

    public function get(string $uri, array $query = []): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->request('GET', $uri, $options);
    }

    public function post(string $uri, array $data = []): array
    {
        $options = [];
        if ($data !== []) {
            $options['json'] = $data;
        }

        return $this->request('POST', $uri, $options);
    }

    public function put(string $uri, array $data = []): array
    {
        $options = [];
        if ($data !== []) {
            $options['json'] = $data;
        }

        return $this->request('PUT', $uri, $options);
    }

    public function delete(string $uri, array $query = []): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->request('DELETE', $uri, $options);
    }

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

        return $response->json() ?? [];
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

        throw new \RuntimeException($message);
    }
}
