## darvis/snelstart

A client for the SnelStart B2B API v2 (Dutch accounting software). It exchanges the client key for an access token and sends every call with that token and the subscription key. The keys give access to a real bookkeeping.

- Use the `Snelstart` facade (`Darvis\Snelstart\Laravel\Facades\Snelstart`) or inject `Darvis\Snelstart\Client` (a singleton, alias `snelstart`). Don't call the SnelStart API with `Http::` yourself and don't fetch a token yourself.
- Collections are methods on the client that return a `Darvis\Snelstart\Resource`: `relaties()`, `artikelen()`, `verkooporders()`, `verkoopfacturen()`, `offertes()`, `verkoopboekingen()`, `inkoopboekingen()`, `inkoopfacturen()`, `grootboeken()`, `grootboekmutaties()`, `kostenplaatsen()`, `landen()`, `dagboeken()`, `btwtarieven()` and more. A resource has `list(array $query = [])`, `all(array $query = [])`, `find(string $id)`, `create(array $data)`, `update(string $id, array $data)` and `delete(string $id)`.
- **`list()` is one call and SnelStart returns at most 500 rows for it. Use `all()` to get everything**: it is a generator that pages with `$skip` and `$top` while you loop. Filter with OData in the query: `['$filter' => "..."]`.
- Every other endpoint: `get($path, $query)`, `post($path, $data)`, `put($path, $data)`, `delete($path)`, with `$path` relative to the base URL, for example `get("relaties/{$id}/customFields")`. They return the decoded response: an array, `null` for an empty one. `companyInfo()` returns the administration.
- The arrays go to SnelStart unchanged and the response comes back unchanged. Field names are those of the SnelStart API, in Dutch (`naam`, `relatiesoort`, `factuurnummer`). A reference to another row is `['id' => '<guid>']`. `update()` needs the whole row, not only the changed fields. Don't invent field names: read them from a response or from the SnelStart documentation.
- **Every failure throws** `Darvis\Snelstart\Exceptions\SnelstartException` (extends `RuntimeException`), also a timeout or connection error. `$e->status()` is the HTTP status, `0` when there was no response. Never parse the message.
- **SnelStart error codes**: with a 400 SnelStart sends codes such as `REL-0007`. `$e->errorCodes()` lists them, `$e->hasErrorCode('REL-0008')` checks for one, `$e->errors()` gives `['REL-0007' => 'Naam moet gevuld zijn']` (Dutch texts from SnelStart). `Darvis\Snelstart\ErrorCodes::MESSAGES` is the full list. Decide on the code, not on the text.
- **A 429, a 5xx and a timeout are not retried.** Write to SnelStart from a queued job with a backoff. A write that timed out may have been processed; check before sending it again.
- A 401 on a token the client already had: the client fetches a new token and repeats the call once. Don't build your own retry for a 401; a 401 that reaches your code is about the subscription key or the rights of the client key.
- The access token is kept in memory and, encrypted with `APP_KEY`, in Laravel's cache until sixty seconds before it expires. `SNELSTART_TOKEN_CACHE=false` turns the cache off, `SNELSTART_TOKEN_CACHE_STORE` picks the store. Never cache, lock or store the token yourself. `forgetToken()` drops it on purpose.
- **More than one administration**: a client key belongs to one administration. `Snelstart::withClientKey($key)` returns a client for another one, with its own token. Store client keys encrypted.
- Every request, the token request included, gives up after 30 seconds and 10 seconds to connect: `SNELSTART_TIMEOUT` and `SNELSTART_CONNECT_TIMEOUT`.
- Without `SNELSTART_CLIENT_KEY` the application boots; the first call throws `Snelstart config is incomplete: the client key is empty.`
- `php artisan snelstart:test` checks the subscription key and the client key and exits with 0 or 1.
- Never put a key or the token in a URL, a log line or output. The package replaces them with `[redacted]` in its own exception messages; the rest of a response body can still hold data of the administration.
- In tests, never call SnelStart: `Http::preventStrayRequests()`, fake `auth.snelstart.nl/*` (return an `access_token`) and `b2bapi.snelstart.nl/*`, and set `snelstart.client_key` to any value. Keep the test cache on the `array` store. A fake for `all()` must return fewer than 500 rows, or the next page is the same one and `all()` throws `Snelstart paging does not advance`.

@verbatim
<code-snippet name="Create a relation in SnelStart from a queued job" lang="php">
use Darvis\Snelstart\Client;
use Darvis\Snelstart\Exceptions\SnelstartException;

public $tries = 3;

public $backoff = [60, 300];

public function handle(Client $snelstart): void
{
    try {
        $relatie = $snelstart->relaties()->create([
            'relatiesoort' => ['Klant'],
            'naam' => $this->customer->name,
        ]);
    } catch (SnelstartException $e) {
        if ($e->status() === 0 || $e->status() === 429 || $e->status() >= 500) {
            // No response, busy or down: let the queue try again after the backoff.
            // After a timeout SnelStart may have created it: look it up first in a real job.
            throw $e;
        }

        // A 400 has the reason in its error codes: ['REL-0007' => 'Naam moet gevuld zijn'].
        // Another 4xx will not get better by trying again either.
        $this->fail($e);

        return;
    }

    // $relatie is the decoded response of SnelStart, unchanged.
    $this->customer->update(['snelstart_id' => $relatie['id']]);
}
</code-snippet>

<code-snippet name="Read every open sales invoice" lang="php">
use Darvis\Snelstart\Laravel\Facades\Snelstart;

foreach (Snelstart::verkoopfacturen()->all() as $factuur) {
    if ($factuur['openstaandSaldo'] > 0) {
        // ...
    }
}
</code-snippet>
@endverbatim
