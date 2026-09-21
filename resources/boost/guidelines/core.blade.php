## darvis/snelstart

A client for the SnelStart B2B API v2 (Dutch accounting software). It exchanges the client key for an access token and sends every call with that token and the subscription key. The keys give access to a real bookkeeping.

- Resolve `Darvis\Snelstart\Services\SnelstartAPI` from the container (a singleton, alias `snelstart`). There is no facade. Don't call the SnelStart API with `Http::` yourself and don't fetch a token yourself.
- Convenience methods: `getCompanyInfo()`, `getRelaties(array $query = [])`, `createRelatie(array $data)`, `getArtikelen(array $query = [])`, `createVerkooporder(array $data)`. Every other endpoint: `get($uri, $query)`, `post($uri, $data)`, `put($uri, $data)`, `delete($uri, $query)`, `head($uri, $query)`, with `$uri` relative to the base URL. All return an array.
- **A failed call throws** a plain `RuntimeException` (`Snelstart API call failed. HTTP status: 429. Response: ...`), with exception code `0`; the HTTP status is only in the message. A timeout or connection error is an `Illuminate\Http\Client\ConnectionException`, which is NOT a `RuntimeException`: catch both.
- **Nothing is retried**, not a 401, 429 or 5xx. Write to SnelStart from a queued job with a backoff. A write that timed out may have been processed; check before sending it again.
- The token is kept in memory on the singleton until sixty seconds before it expires, not in the cache. A 401 does not fetch a new token; in a long running process call `app()->forgetInstance(SnelstartAPI::class)`.
- The client throws `Snelstart API config is incomplete (token_url, client_key).` when it is built without `SNELSTART_CLIENT_KEY`. It is built on first use, not at boot.
- A 2xx with an empty body, or with a body that is not a JSON object or list, returns `[]`.
- `Darvis\Snelstart\Services\EchoService` (`getEchoResource()`, `headEchoResource()`, `postEchoResource()`) calls `/echo/resource` and never throws: check `$result['success']`. `php artisan snelstart:test` fetches `/companyInfo` and exits with 0 or 1.
- `Darvis\Snelstart\Standalone\SnelstartAPI` is the same client on cURL for projects WITHOUT Laravel. Don't use it inside a Laravel app: `Http::fake()` does not see it, and it has no timeout.
- Never put a key or the token in a URL, a log line or output. The package replaces them with `[redacted]` in its own exception messages; the rest of a response body can still hold data of the administration.
- Read settings through `Darvis\Snelstart\Support\SnelstartConfig` (`baseUrl()`, `clientKey()`, `subscriptionKey()`, `tokenUrl()`), not with `config()`.
- In tests, never call SnelStart: `Http::preventStrayRequests()`, fake `auth.snelstart.nl/*` (return an `access_token`) and `b2bapi.snelstart.nl/*`, and set `snelstart.client_key` to any value before resolving the client.

@verbatim
<code-snippet name="Create a relation in SnelStart from a queued job" lang="php">
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
    } catch (\RuntimeException $e) {
        // 4xx or 5xx. The status and the response body are in the message, the keys are redacted.
        throw $e;
    }

    $this->customer->update(['snelstart_id' => $relation['id'] ?? null]);
}
</code-snippet>
@endverbatim
