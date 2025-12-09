<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Standalone;

use DateTime;

/**
 * Standalone Snelstart API client without Laravel dependencies.
 * Uses native PHP cURL for HTTP requests.
 */
class SnelstartAPI
{
    protected string $baseUrl;

    protected string $tokenUrl;

    protected string $clientKey;

    protected ?string $subscriptionKey;

    protected ?string $accessToken = null;

    protected ?DateTime $tokenExpiresAt = null;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://b2bapi.snelstart.nl/v2', '/');
        $this->tokenUrl = $config['token_url'] ?? 'https://auth.snelstart.nl/b2b/token';
        $this->clientKey = $config['client_key'] ?? '';
        $this->subscriptionKey = $config['subscription_key'] ?? null;

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
        ]);
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
        return $this->request('GET', $uri, ['query' => $query]);
    }

    public function post(string $uri, array $data = []): array
    {
        return $this->request('POST', $uri, ['json' => $data]);
    }

    public function put(string $uri, array $data = []): array
    {
        return $this->request('PUT', $uri, ['json' => $data]);
    }

    public function delete(string $uri, array $query = []): array
    {
        return $this->request('DELETE', $uri, ['query' => $query]);
    }

    public function head(string $uri, array $query = []): array
    {
        return $this->request('HEAD', $uri, ['query' => $query]);
    }

    /**
     * Central request method: automatically adds Bearer token + subscription key.
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl . '/' . ltrim($uri, '/');

        // Add query parameters to URL
        if (!empty($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if (!empty($this->subscriptionKey)) {
            $headers[] = 'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (in_array($method, ['POST', 'PUT']) && !empty($options['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json']));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            $this->handleError($httpCode, $response);
        }

        if (empty($response)) {
            return [];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
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
            $this->tokenExpiresAt > new DateTime()
        ) {
            return $this->accessToken;
        }

        // SnelStart-specific authentication flow:
        // grant_type=clientkey & clientkey=<custom-key>
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Failed to retrieve access_token: cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException(
                'Failed to retrieve access_token from Snelstart. HTTP status: ' . $httpCode . '. Response: ' . $response
            );
        }

        $data = json_decode($response, true);

        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Snelstart token response does not contain access_token.');
        }

        $this->accessToken = (string) $data['access_token'];

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->tokenExpiresAt = (new DateTime())->modify('+' . ($expiresIn - 60) . ' seconds');

        return $this->accessToken;
    }

    /* -----------------------------------------------------------------
     |  Error handling
     | -----------------------------------------------------------------
     */

    protected function handleError(int $httpCode, string $response): void
    {
        $message = 'Snelstart API call failed. HTTP status: ' . $httpCode . '.';

        if (!empty($response)) {
            $message .= ' Response: ' . $response;
        }

        throw new \RuntimeException($message);
    }
}
