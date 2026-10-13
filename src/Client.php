<?php

declare(strict_types=1);

namespace Darvis\Snelstart;

use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Http\CurlTransport;
use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Http\Transport;
use Darvis\Snelstart\Token\MemoryTokenStore;
use Darvis\Snelstart\Token\TokenStore;

/**
 * Client for the SnelStart B2B API v2. Plain PHP: it works in any project, and the Laravel layer
 * in Darvis\Snelstart\Laravel only hands it a transport, a token store and the config.
 *
 * It exchanges the client key for an access token, keeps that token in the token store, and sends
 * every call with the token and the subscription key. A failed call throws a SnelstartException.
 */
class Client
{
    /**
     * Seconds a token is dropped before SnelStart says it expires.
     */
    private const TOKEN_MARGIN_SECONDS = 60;

    private readonly Transport $transport;

    private readonly TokenStore $tokens;

    /**
     * The token of the last call, kept only to remove it from error messages.
     */
    private ?string $lastToken = null;

    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?TokenStore $tokens = null,
    ) {
        $this->transport = $transport ?? new CurlTransport;
        $this->tokens = $tokens ?? new MemoryTokenStore;
    }

    /**
     * A client with the keys from the environment, see Config::fromEnv().
     */
    public static function fromEnv(): self
    {
        return new self(Config::fromEnv());
    }

    /**
     * The same client for another administration. It shares the transport and the token store;
     * tokens are kept per client key, so the administrations never see each other's token.
     */
    public function withClientKey(#[\SensitiveParameter] string $clientKey): self
    {
        return new self($this->config->withClientKey($clientKey), $this->transport, $this->tokens);
    }

    public function config(): Config
    {
        return $this->config;
    }

    /* -----------------------------------------------------------------
     |  Any endpoint
     | -----------------------------------------------------------------
     */

    /**
     * GET an endpoint below the base URL. Returns the decoded response, null for an empty one.
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * POST JSON to an endpoint below the base URL. An empty array sends no body.
     *
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $query
     */
    public function post(string $path, array $data = [], array $query = []): mixed
    {
        return $this->request('POST', $path, $query, $data);
    }

    /**
     * PUT JSON to an endpoint below the base URL. An empty array sends no body.
     *
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $query
     */
    public function put(string $path, array $data = [], array $query = []): mixed
    {
        return $this->request('PUT', $path, $query, $data);
    }

    /**
     * DELETE an endpoint below the base URL.
     *
     * @param  array<string, mixed>  $query
     */
    public function delete(string $path, array $query = []): mixed
    {
        return $this->request('DELETE', $path, $query);
    }

    /* -----------------------------------------------------------------
     |  Collections
     | -----------------------------------------------------------------
     */

    /**
     * Any collection by its path, for one that has no method of its own below.
     *
     * @param  bool  $paged  whether the collection takes $skip and $top
     */
    public function resource(string $path, bool $paged = true): Resource
    {
        return new Resource($this, trim($path, '/'), $paged);
    }

    public function actieprijzen(): Resource
    {
        return $this->resource('actieprijzen');
    }

    public function artikelen(): Resource
    {
        return $this->resource('artikelen');
    }

    public function artikelomzetgroepen(): Resource
    {
        return $this->resource('artikelomzetgroepen', false);
    }

    public function bankboekingen(): Resource
    {
        return $this->resource('bankboekingen');
    }

    public function btwaangiftes(): Resource
    {
        return $this->resource('btwaangiftes');
    }

    public function btwtarieven(): Resource
    {
        return $this->resource('btwtarieven', false);
    }

    public function dagboeken(): Resource
    {
        return $this->resource('dagboeken', false);
    }

    /**
     * find(), update() and delete() only. A document is created per type:
     * post('documenten/Verkoopboekingen', $data).
     */
    public function documenten(): Resource
    {
        return $this->resource('documenten', false);
    }

    public function grootboeken(): Resource
    {
        return $this->resource('grootboeken');
    }

    public function grootboekmutaties(): Resource
    {
        return $this->resource('grootboekmutaties');
    }

    public function inkoopboekingen(): Resource
    {
        return $this->resource('inkoopboekingen', false);
    }

    public function inkoopfacturen(): Resource
    {
        return $this->resource('inkoopfacturen');
    }

    public function kasboekingen(): Resource
    {
        return $this->resource('kasboekingen');
    }

    public function kostenplaatsen(): Resource
    {
        return $this->resource('kostenplaatsen', false);
    }

    public function landen(): Resource
    {
        return $this->resource('landen', false);
    }

    public function memoriaalboekingen(): Resource
    {
        return $this->resource('memoriaalboekingen', false);
    }

    public function offertes(): Resource
    {
        return $this->resource('offertes');
    }

    /**
     * Needs a '$filter' on Artikel/Id.
     */
    public function prijsafspraken(): Resource
    {
        return $this->resource('prijsafspraken');
    }

    public function relaties(): Resource
    {
        return $this->resource('relaties');
    }

    public function vatratedefinitions(): Resource
    {
        return $this->resource('vatratedefinitions');
    }

    public function vatrates(): Resource
    {
        return $this->resource('vatrates');
    }

    public function verkoopboekingen(): Resource
    {
        return $this->resource('verkoopboekingen', false);
    }

    public function verkoopfacturen(): Resource
    {
        return $this->resource('verkoopfacturen');
    }

    public function verkooporders(): Resource
    {
        return $this->resource('verkooporders');
    }

    public function verkoopordersjablonen(): Resource
    {
        return $this->resource('verkoopordersjablonen', false);
    }

    /* -----------------------------------------------------------------
     |  Single calls
     | -----------------------------------------------------------------
     */

    /**
     * GET /companyInfo: the administration the client key belongs to.
     *
     * @return array<mixed>
     */
    public function companyInfo(): array
    {
        $info = $this->get('companyInfo');

        return is_array($info) ? $info : [];
    }

    /**
     * Call the Echo API, which only needs the subscription key: no token is fetched. Use it to
     * tell a wrong subscription key from a wrong client key. Returns what the Echo API answers.
     */
    public function echo(): mixed
    {
        return $this->decode($this->send('GET', $this->config->echoUrl.'/resource?param1=sample', $this->headers()));
    }

    /**
     * Drop the access token. The next call fetches a new one.
     */
    public function forgetToken(): void
    {
        $this->tokens->forget($this->tokenKey());
    }

    /* -----------------------------------------------------------------
     |  Requests
     | -----------------------------------------------------------------
     */

    /**
     * The one method that calls the API.
     *
     * @param  array<string, mixed>  $query
     * @param  array<mixed>  $data
     */
    private function request(string $method, string $path, array $query = [], array $data = []): mixed
    {
        $url = $this->config->baseUrl.'/'.ltrim($path, '/');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').$this->queryString($query);
        }

        try {
            $body = $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new SnelstartException('Snelstart request data cannot be sent as JSON: '.$e->getMessage(), 0, $e);
        }

        [$token, $fresh] = $this->token();
        $response = $this->send($method, $url, $this->headers($token, $body !== null), $body);

        // A 401 on a token that was kept: SnelStart dropped it before it expired. Fetch a new one
        // and repeat the call, once. A 401 on a token of a moment ago is not about the token, and
        // repeating that only hammers the token endpoint.
        if ($response->status === 401 && ! $fresh) {
            $this->forgetToken();

            [$token] = $this->token();
            $response = $this->send($method, $url, $this->headers($token, $body !== null), $body);
        }

        return $this->decode($response);
    }

    /**
     * One attempt. A transport that throws means there was no response.
     *
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body = null): Response
    {
        try {
            return $this->transport->send($method, $url, $headers, $body, $this->config->timeout, $this->config->connectTimeout);
        } catch (SnelstartException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new SnelstartException($this->redact('Snelstart API call failed. No response: '.$e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $token = null, bool $json = false): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->config->subscriptionKey !== '') {
            $headers['Ocp-Apim-Subscription-Key'] = $this->config->subscriptionKey;
        }

        return $headers;
    }

    /**
     * The decoded body of a successful response, or the exception for a failed one.
     */
    private function decode(Response $response, string $failure = 'Snelstart API call failed.'): mixed
    {
        if ($response->successful()) {
            return $response->decoded();
        }

        $message = $failure.' HTTP status: '.$response->status.'.';

        if (trim($response->body) !== '') {
            $message .= ' Response: '.trim($response->body);
        }

        throw new SnelstartException($this->redact($message), $response->status, null, ErrorCodes::find($response->body));
    }

    /**
     * RFC 3986 encoding, and true and false as words: the API does not read 1 and 0 as booleans.
     *
     * @param  array<string, mixed>  $query
     */
    private function queryString(array $query): string
    {
        array_walk_recursive($query, static function (mixed &$value): void {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
        });

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /* -----------------------------------------------------------------
     |  Token (grant_type = clientkey)
     | -----------------------------------------------------------------
     */

    /**
     * The access token, and whether it was fetched from the token endpoint just now.
     *
     * @return array{string, bool}
     */
    private function token(): array
    {
        $key = $this->tokenKey();
        $token = $this->tokens->get($key);
        $fresh = false;

        if ($token === null) {
            // One token request at a time: a lock that lasts as long as that request can take.
            $token = $this->tokens->synchronized($key, (int) ceil($this->config->timeout) + 5, function () use ($key, &$fresh): string {
                // Another process may have fetched one while this one waited.
                $token = $this->tokens->get($key);

                if ($token === null) {
                    $token = $this->fetchToken($key);
                    $fresh = true;
                }

                return $token;
            });
        }

        $this->lastToken = $token;

        return [$token, $fresh];
    }

    private function fetchToken(string $key): string
    {
        if ($this->config->clientKey === '') {
            throw new SnelstartException('Snelstart config is incomplete: the client key is empty.');
        }

        // The client key goes in the body, never in the URL: exception messages quote URLs.
        $response = $this->send('POST', $this->config->tokenUrl, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query(['grant_type' => 'clientkey', 'clientkey' => $this->config->clientKey]));

        $data = $this->decode($response, 'Failed to retrieve access_token from Snelstart.');
        $data = is_array($data) ? $data : [];
        $token = $data['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new SnelstartException('Snelstart token response does not contain access_token.', $response->status);
        }

        $this->lastToken = $token;

        $seconds = (is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600) - self::TOKEN_MARGIN_SECONDS;

        if ($seconds > 0) {
            $this->tokens->put($key, $token, $seconds);
        }

        return $token;
    }

    /**
     * Differs per token URL and client key, so two administrations never share a token. Hashed:
     * a key in a store is readable for whoever can list that store.
     */
    private function tokenKey(): string
    {
        return 'snelstart.token.'.hash('sha256', $this->config->tokenUrl.'|'.$this->config->clientKey);
    }

    /**
     * Replace the client key, the subscription key and the access token in a message. An error
     * response can echo what was sent, and the message ends up in logs and in command output.
     */
    private function redact(string $message): string
    {
        $search = [];

        foreach ([$this->config->clientKey, $this->config->subscriptionKey, $this->lastToken] as $secret) {
            if ($secret === null || $secret === '') {
                continue;
            }

            // As it is, and as a server can write it back: inside a JSON string (PHP and .NET
            // escape differently), in a form body or a URL, and in HTML.
            $search[] = $secret;
            $search[] = trim((string) json_encode($secret), '"');
            $search[] = trim((string) json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), '"');
            $search[] = str_replace('+', '\\u002B', trim((string) json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), '"'));
            $search[] = urlencode($secret);
            $search[] = rawurlencode($secret);
            $search[] = htmlspecialchars($secret, ENT_QUOTES | ENT_HTML5);
            $search[] = str_replace('+', '&#x2B;', htmlspecialchars($secret, ENT_QUOTES | ENT_HTML5));
            $search[] = str_replace('+', '&#43;', htmlspecialchars($secret, ENT_QUOTES | ENT_HTML5));
        }

        // Without regard to case: percent and unicode escapes come in both.
        return str_ireplace(array_unique($search), '[redacted]', $message);
    }
}
