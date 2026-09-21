## darvis/snelstart

A client for the SnelStart B2B API v2 (Dutch accounting software). It exchanges the client key for an access token and sends every call with that token and the subscription key. The keys give access to a real bookkeeping.

- Resolve `Darvis\Snelstart\Services\SnelstartAPI` from the container (a singleton, alias `snelstart`). There is no facade. Don't call the SnelStart API with `Http::` yourself and don't fetch a token yourself.
- Convenience methods: `getCompanyInfo()`, `getRelaties(array $query = [])`, `createRelatie(array $data)`, `getArtikelen(array $query = [])`, `createVerkooporder(array $data)`. Every other endpoint: `get($uri, $query)`, `post($uri, $data)`, `put($uri, $data)`, `delete($uri, $query)`, `head($uri, $query)`, with `$uri` relative to the base URL. All return an array.
- **A failed call throws** `Darvis\Snelstart\Exceptions\SnelstartException`, which extends `RuntimeException` (`Snelstart API call failed. HTTP status: 429. Response: ...`). Read the HTTP status with `$e->status()` (the same as `$e->getCode()`), never by parsing the message; it is `0` when there was no response. The response body is not a property of the exception. A timeout or connection error is an `Illuminate\Http\Client\ConnectionException`, which is NOT a `SnelstartException` or a `RuntimeException`: catch both.
- **A 429 or a 5xx is not retried.** Write to SnelStart from a queued job with a backoff. A write that timed out may have been processed; check before sending it again.
- The access token is kept in memory and, encrypted with `APP_KEY`, in Laravel's cache until sixty seconds before it expires, so a web request does not fetch its own. `SNELSTART_TOKEN_CACHE=false` turns the cache off, `SNELSTART_TOKEN_CACHE_STORE` picks the store. `php artisan cache:clear` only makes the next call fetch a new token. A lock on the same store makes concurrent requests on a cold cache share one token request; it never fails a call. Never cache, lock or store the token yourself.
- A 401 on a token the client already had: the client forgets it, fetches a new one and repeats the call **once**. Don't build your own retry for a 401; a 401 that reaches your code is about the subscription key or the rights of the client key. `$snelstart->forgetToken()` drops the token on purpose.
- Every request, the token request included, gives up after 30 seconds and 10 seconds to connect: `SNELSTART_TIMEOUT` and `SNELSTART_CONNECT_TIMEOUT`.
- The client throws `Snelstart API config is incomplete (token_url, client_key).` when it is built without `SNELSTART_CLIENT_KEY`. It is built on first use, not at boot.
- A 2xx with an empty body, or with a body that is not a JSON object or list, returns `[]`.
- `Darvis\Snelstart\Services\EchoService` (`getEchoResource()`, `headEchoResource()`, `postEchoResource()`) calls `/echo/resource` and never throws: check `$result['success']`; `$result['error_code']` is the HTTP status, or `0` without a response. `php artisan snelstart:test` fetches `/companyInfo` and exits with 0 or 1.
- `Darvis\Snelstart\Standalone\SnelstartAPI` is the same client on cURL for projects WITHOUT Laravel. Don't use it inside a Laravel app: `Http::fake()` does not see it, and it has no token cache.
- Never put a key or the token in a URL, a log line or output. The package replaces them with `[redacted]` in its own exception messages; the rest of a response body can still hold data of the administration.
- Read settings through `Darvis\Snelstart\Support\SnelstartConfig` (`baseUrl()`, `clientKey()`, `connectTimeout()`, `subscriptionKey()`, `timeout()`, `tokenCacheEnabled()`, `tokenCacheStore()`, `tokenUrl()`), not with `config()`.
- In tests, never call SnelStart: `Http::preventStrayRequests()`, fake `auth.snelstart.nl/*` (return an `access_token`) and `b2bapi.snelstart.nl/*`, and set `snelstart.client_key` to any value before resolving the client. Keep the test cache on the `array` store (or set `SNELSTART_TOKEN_CACHE=false`), or a cached token travels from one test to the next.

@verbatim
<code-snippet name="Create a relation in SnelStart from a queued job" lang="php">
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Http\Client\ConnectionException;

public $tries = 3;

public $backoff = [60, 300];

public function handle(SnelstartAPI $snelstart): void
{
    try {
        $relation = $snelstart->createRelatie(['naam' => $this->customer->name]);
    } catch (ConnectionException $e) {
        // Timed out: SnelStart may have created it. Look it up before trying again.
        throw $e;
    } catch (SnelstartException $e) {
        if ($e->status() === 429 || $e->status() >= 500) {
            // Busy or down: let the queue try again after the backoff.
            throw $e;
        }

        // Another 4xx will not get better by trying again. The message has the body, keys redacted.
        $this->fail($e);

        return;
    }

    // $relation is the decoded response of SnelStart, unchanged. Store what you need from it.
    $this->customer->update(['snelstart_response' => $relation]);
}
</code-snippet>
@endverbatim
