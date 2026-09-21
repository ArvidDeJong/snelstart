---
name: snelstart-development
description: Work with darvis/snelstart. Use it to read or write relations, articles, sales orders or any other endpoint of the SnelStart B2B API from a Laravel app, to handle a failed or refused call, a 401 or an expired token, to choose between the Laravel client and the standalone client, and to test code that talks to SnelStart without calling it.
---

# darvis/snelstart development

## When to use this skill

Use this skill when code reads from or writes to SnelStart in an application that has `darvis/snelstart` installed, when a call throws or `php artisan snelstart:test` fails, when you have to decide where the keys go, or when you write tests around such code.

## How a call runs

1. `Darvis\Snelstart\Services\SnelstartAPI` is a singleton. It reads the config once, when it is first resolved, and throws `Snelstart API config is incomplete (token_url, client_key).` without a client key.
2. Before a call it looks for a token on the instance. Without one, or within sixty seconds of its expiry, it posts `grant_type=clientkey` and `clientkey=<key>` as a form to the token URL. Without `expires_in` it assumes 3600 seconds.
3. The call goes to `base_url` plus the path with `Authorization: Bearer <token>`, `Accept: application/json` and, when set, `Ocp-Apim-Subscription-Key`.
4. A 4xx or 5xx throws. Anything else is decoded and returned as an array.

| Situation | Result |
| --- | --- |
| 2xx, JSON object or list | the decoded array |
| 2xx, empty body or `HEAD` | `[]` |
| 2xx, body that is not a JSON object or list | `[]` |
| 4xx or 5xx | `RuntimeException`: `Snelstart API call failed. HTTP status: <status>. Response: <body>` |
| Token endpoint 4xx or 5xx | `RuntimeException`: `Failed to retrieve access_token from Snelstart. HTTP status: <status>. Response: <body>` |
| Token response without `access_token` | `RuntimeException`: `Snelstart token response does not contain access_token.` |
| Timeout, DNS, refused connection | `Illuminate\Http\Client\ConnectionException` (30 seconds, 10 to connect) |
| No client key | `RuntimeException` when the client is built |

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

- **Catching only `RuntimeException` misses timeouts.** `ConnectionException` does not extend it.
- **`$e->getCode()` is always `0`.** The HTTP status is only in the message. `EchoService` returns that same `0` as `error_code`.
- **A 401 does not refresh the token.** In a queue worker the singleton, and its token, outlive the job. After a 401 on a call that used to work, `app()->forgetInstance(SnelstartAPI::class)` gives a new client and a new token.
- **Every web request fetches its own token**, because the token is not in the cache. Don't add a call "just to check" on every page.
- **The config is read once.** Changing `snelstart.*` at runtime, or in a test after the client was resolved, has no effect until `forgetInstance()`.
- **An empty `$data` array sends no body**, not `{}` or `[]`.
- **`[]` does not mean "no records"** by itself: a 204 and a body that is not JSON give `[]` too.
- **Don't use `Standalone\SnelstartAPI` in a Laravel app.** `Http::fake()` and `Http::preventStrayRequests()` do not see it, so a test would call the real API, and it has no timeout.
- **Never put a key in a URL, a log line, an exception message or command output**, not even the first characters. The package redacts the keys and the token in its own messages; the rest of a response body can hold data of the administration, so don't show exception messages to end users.
- There is no facade and there are no models; `Snelstart::` does not exist.

## Settings

| Config key | Environment variable | Default |
| --- | --- | --- |
| `snelstart.base_url` | `SNELSTART_BASE_URL` | `https://b2bapi.snelstart.nl/v2` |
| `snelstart.client_key` | `SNELSTART_CLIENT_KEY` | none, required |
| `snelstart.subscription_key` | `SNELSTART_SUBSCRIPTION_KEY` | none, header left out |
| `snelstart.token_url` | `SNELSTART_TOKEN_URL` | `https://auth.snelstart.nl/b2b/token` |

Read them through `Darvis\Snelstart\Support\SnelstartConfig`: `baseUrl()` (no trailing slash), `clientKey()` (empty string when not set), `subscriptionKey()` (null when empty) and `tokenUrl()`. Publish the file with `php artisan vendor:publish --tag=snelstart-config`.

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
- A refused call: `Http::response(['message' => 'Too many requests'], 429)` and `->toThrow(RuntimeException::class, 'HTTP status: 429')`.
- A timeout: a fake that throws `new ConnectionException('cURL error 28')`.
- An expired token: `$this->travel(1)->hours()` between two calls, then `Http::assertSentCount(4)`.
