---
name: snelstart-development
description: Work with darvis/snelstart. Use it to read or write relations, articles, sales orders or any other endpoint of the SnelStart B2B API from a Laravel app, to handle a failed or refused call, a 401, a timeout, an expired or cached token, to choose between the Laravel client and the standalone client, and to test code that talks to SnelStart without calling it.
---

# darvis/snelstart development

## When to use this skill

Use this skill when code reads from or writes to SnelStart in an application that has `darvis/snelstart` installed, when a call throws or `php artisan snelstart:test` fails, when you have to decide where the keys go, or when you write tests around such code.

## How a call runs

1. `Darvis\Snelstart\Services\SnelstartAPI` is a singleton. It reads the config once, when it is first resolved, and throws `Snelstart API config is incomplete (token_url, client_key).` without a client key.
2. Before a call it looks for a token on the instance, then in Laravel's cache (encrypted with `APP_KEY`, key `snelstart.token.<sha256 of token URL and client key>`). Without one, or within sixty seconds of its expiry, it posts `grant_type=clientkey` and `clientkey=<key>` as a form to the token URL. Without `expires_in` it assumes 3600 seconds.
3. The call goes to `base_url` plus the path with `Authorization: Bearer <token>`, `Accept: application/json` and, when set, `Ocp-Apim-Subscription-Key`.
4. A 401 on a token the client already had (memory or cache): it forgets the token, fetches a new one and repeats the request once.
5. A 4xx or 5xx throws. Anything else is decoded and returned as an array. Every request has a timeout of 30 seconds, 10 to connect.

| Situation | Result | `$e->status()` |
| --- | --- | --- |
| 2xx, JSON object or list | the decoded array | |
| 2xx, empty body or `HEAD` | `[]` | |
| 2xx, body that is not a JSON object or list | `[]` | |
| 401 on a token the client already had | new token, the call is repeated once; a second 401 throws as below | `401` |
| 401 on a token fetched for this call | not repeated, throws as below | `401` |
| 4xx or 5xx (429 and 5xx are never retried) | `SnelstartException`: `Snelstart API call failed. HTTP status: <status>. Response: <body>` | that status |
| Token endpoint 4xx or 5xx (never retried) | `SnelstartException`: `Failed to retrieve access_token from Snelstart. HTTP status: <status>. Response: <body>` | that status |
| Token response without `access_token` | `SnelstartException`: `Snelstart token response does not contain access_token.` | the status of that response, `200` |
| No client key | `SnelstartException` when the client is built | `0` |
| Timeout, DNS, refused connection | `Illuminate\Http\Client\ConnectionException` after `snelstart.timeout` (30) or `snelstart.connect_timeout` (10) seconds; NOT a `SnelstartException` | none |
| Cache store down, unknown store, no `APP_KEY`, a lock that throws | one `Log::warning()` (`Snelstart token cache is not available, ...`), the token stays in memory, calls work | |
| Cached value that cannot be decrypted | treated as no token: removed, a new token is fetched, nothing throws | |
| Another request is fetching a token | waits at most five seconds for its lock, then uses its token or fetches one itself | |
| Cache store without lock support | no lock, no log line, calls work | |

`Darvis\Snelstart\Exceptions\SnelstartException` extends `RuntimeException`; `status()` and `getCode()` are the HTTP status, `0` without a response. The messages are the same as before the class existed.

```php
use Darvis\Snelstart\Exceptions\SnelstartException;
use Illuminate\Http\Client\ConnectionException;

try {
    $order = $snelstart->createVerkooporder($payload);
} catch (ConnectionException $e) {
    // No answer. The order may exist: look it up before sending it again.
} catch (SnelstartException $e) {
    match (true) {
        $e->status() === 404 => $this->markRelationAsMissing(),
        $e->status() === 429, $e->status() >= 500 => $this->release(300),
        default => $this->fail($e),
    };
}
```

## Scenarios

### Read a list

```php
$relations = app(SnelstartAPI::class)->getRelaties(['$top' => 50, '$skip' => 100]);
```

The query array is passed on as it is. The package does not page for you: one call is one request.

### Write from a job

```php
public function handle(SnelstartAPI $snelstart): void
{
    $order = $snelstart->createVerkooporder($this->payload);

    $this->order->update(['snelstart_id' => $order['id'] ?? null]);
}
```

Give the job `$tries` and `$backoff`; the package never retries. After a `ConnectionException` on a write, look the record up before you send it again, or you book it twice.

### An endpoint without a convenience method

```php
$relation = $snelstart->get('/relaties/'.$id);
$snelstart->put('/relaties/'.$id, $data);
$snelstart->delete('/relaties/'.$id);
```

### Check the connection

`php artisan snelstart:test` prints `Connection successful!` and the company info, or `Connection failed: <message>`, and exits with 0 or 1. In code, `app(EchoService::class)->getEchoResource()` returns `['success' => true|false, 'message' => ..., ...]` and never throws.

## Pitfalls

- **Catching only `SnelstartException` or `RuntimeException` misses timeouts.** `ConnectionException` extends neither, and the package leaves it as it is.
- **Don't parse the status out of the message.** `$e->status()` has it. `0` means there was no response (incomplete config), not "no error". `EchoService` returns the same number as `error_code`.
- **The response body is only in the message**, not a property, and the message can hold data of the administration. Log it, don't show it to end users.
- **Don't retry a 401 yourself.** The client already did, once, with a new token. A 401 that reaches your code is about the subscription key or the rights of the client key, and a loop around it only burns calls.
- **Don't cache the token yourself**, and never put it in the session, the database or a log. The client keeps it encrypted in the cache; `forgetToken()` drops it, `php artisan cache:clear` does too and is harmless.
- **The token cache needs a store that persists.** With the `array` or `null` store every request fetches its own token again. `SNELSTART_TOKEN_CACHE_STORE` picks another store.
- **A long call is cut off at 30 seconds.** Raise `SNELSTART_TIMEOUT` for an endpoint that really needs longer, don't wrap the call in a retry.
- **The config is read once.** Changing `snelstart.*` at runtime, or in a test after the client was resolved, has no effect until `forgetInstance()`.
- **An empty `$data` array sends no body**, not `{}` or `[]`.
- **`[]` does not mean "no records"** by itself: a 204 and a body that is not JSON give `[]` too.
- **Don't use `Standalone\SnelstartAPI` in a Laravel app.** `Http::fake()` and `Http::preventStrayRequests()` do not see it, so a test would call the real API, and its token is not cached.
- **Never put a key in a URL, a log line, an exception message or command output**, not even the first characters. The package redacts the keys and the token in its own messages; the rest of a response body can hold data of the administration, so don't show exception messages to end users.
- There is no facade and there are no models; `Snelstart::` does not exist.

## Settings

| Config key | Environment variable | Default |
| --- | --- | --- |
| `snelstart.base_url` | `SNELSTART_BASE_URL` | `https://b2bapi.snelstart.nl/v2` |
| `snelstart.client_key` | `SNELSTART_CLIENT_KEY` | none, required |
| `snelstart.connect_timeout` | `SNELSTART_CONNECT_TIMEOUT` | `10` seconds |
| `snelstart.subscription_key` | `SNELSTART_SUBSCRIPTION_KEY` | none, header left out |
| `snelstart.timeout` | `SNELSTART_TIMEOUT` | `30` seconds |
| `snelstart.token_cache.enabled` | `SNELSTART_TOKEN_CACHE` | `true` |
| `snelstart.token_cache.store` | `SNELSTART_TOKEN_CACHE_STORE` | none, the default cache store |
| `snelstart.token_url` | `SNELSTART_TOKEN_URL` | `https://auth.snelstart.nl/b2b/token` |

Read them through `Darvis\Snelstart\Support\SnelstartConfig`: `baseUrl()` (no trailing slash), `clientKey()` (empty string when not set), `subscriptionKey()` (null when empty), `tokenUrl()`, `timeout()` and `connectTimeout()` (floats, the default when the value is not a positive number), `tokenCacheEnabled()` and `tokenCacheStore()`. Publish the file with `php artisan vendor:publish --tag=snelstart-config`.

## Testing

Never call SnelStart from a test.

```php
use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();
config(['snelstart.client_key' => 'test-client-key']);

Http::fake([
    'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
    'b2bapi.snelstart.nl/v2/relaties*' => Http::response([['id' => 'r1', 'naam' => 'Example B.V.']]),
]);

expect(app(SnelstartAPI::class)->getRelaties())->toHaveCount(1);

Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
```

- Set the client key before the client is resolved, or call `app()->forgetInstance(SnelstartAPI::class)` after changing it.
- **The cached token.** On the `array` store (Laravel's `phpunit.xml` sets `CACHE_STORE=array`) every test starts without a token. On a store that persists, a token from one test is reused in the next: the token fake is not called and `Http::assertSentCount()` is one lower. Use `Cache::flush()` in `beforeEach`, set `SNELSTART_TOKEN_CACHE=false` in `phpunit.xml`, or call `app(SnelstartAPI::class)->forgetToken()`.
- **A 401 fake is called twice** when an earlier call in the same test succeeded (the held token is replaced and the call repeated), and once when it is the first call.
- A refused call: `Http::response(['message' => 'Too many requests'], 429)` and `->toThrow(SnelstartException::class, 'HTTP status: 429')`; to assert on the number, catch it and `expect($e->status())->toBe(429)`.
- A timeout: a fake that throws `new ConnectionException('cURL error 28')`.
- An expired token: `$this->travel(1)->hours()` between two calls, then `Http::assertSentCount(4)`.
