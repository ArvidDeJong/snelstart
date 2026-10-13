---
name: snelstart-development
description: Work with darvis/snelstart. Use it to read or write relations, articles, sales orders, invoices, bookings or any other endpoint of the SnelStart B2B API from a Laravel or plain PHP app, to page through a collection, to handle a failed call, a SnelStart error code, a 401 or a timeout, to work with several administrations, and to test code that talks to SnelStart without calling it.
---

# darvis/snelstart development

## When to use this skill

Use this skill when code reads from or writes to SnelStart in an application that has `darvis/snelstart` installed, when a call throws or `php artisan snelstart:test` fails, when you have to decide where the keys go, or when you write tests around such code.

## The parts

| Class | What it is |
| --- | --- |
| `Darvis\Snelstart\Client` | The client. Plain PHP. In Laravel a singleton, alias `snelstart`, facade `Darvis\Snelstart\Laravel\Facades\Snelstart` |
| `Darvis\Snelstart\Resource` | One collection: `list()`, `all()`, `find()`, `create()`, `update()`, `delete()` |
| `Darvis\Snelstart\Config` | Immutable settings: keys, URLs, timeouts |
| `Darvis\Snelstart\Exceptions\SnelstartException` | The only exception: `status()`, `errorCodes()`, `hasErrorCode()`, `errors()` |
| `Darvis\Snelstart\ErrorCodes` | The 272 error codes SnelStart publishes, with their Dutch texts |
| `Darvis\Snelstart\Http\Transport` | How a request is sent: `Http\CurlTransport` or `Laravel\HttpTransport` |
| `Darvis\Snelstart\Token\TokenStore` | Where the token is kept: `Token\MemoryTokenStore` or `Laravel\CacheTokenStore` |
| `Darvis\Snelstart\Testing\FakeTransport` | A transport for tests |

## How a call runs

1. The client looks for a token in the token store. In Laravel that is memory, then the cache (encrypted with `APP_KEY`, key `snelstart.token.<sha256 of token URL and client key>`).
2. Without one it posts `grant_type=clientkey` and `clientkey=<key>` as a form to the token URL, under a lock in Laravel. The token is kept until sixty seconds before `expires_in` (3600 when absent).
3. The call goes to `base_url` plus the path with `Authorization: Bearer <token>`, `Accept: application/json` and `Ocp-Apim-Subscription-Key`. A query array is RFC 3986 encoded, a data array is sent as JSON.
4. A 401 on a token the client already had: it forgets the token, fetches a new one and repeats the request once.
5. A 4xx or 5xx throws. Anything else is decoded and returned.

| Situation | Result | `$e->status()` |
| --- | --- | --- |
| 2xx, JSON object or list | the decoded array | |
| 2xx, empty body | `null` from `get()`/`post()`/`put()`/`delete()`, `[]` from a resource method | |
| 401 on a token the client already had | new token, the call is repeated once; a second 401 throws | `401` |
| 401 on a token fetched for this call | not repeated, throws | `401` |
| 400 with SnelStart error codes | `SnelstartException`, codes in `errorCodes()` and `errors()` | `400` |
| Any other 4xx or 5xx (never retried) | `SnelstartException`: `Snelstart API call failed. HTTP status: <status>. Response: <body>` | that status |
| Token endpoint 4xx or 5xx (never retried) | `SnelstartException`: `Failed to retrieve access_token from Snelstart. HTTP status: <status>. Response: <body>` | that status |
| Timeout, DNS, refused connection | `SnelstartException`: `Snelstart API call failed. No response: ...`, the cause in `getPrevious()` | `0` |
| No client key | `SnelstartException` on the first call that needs a token: `Snelstart config is incomplete: the client key is empty.` | `0` |
| Cache store down, unknown store, no `APP_KEY`, a lock that throws | one `Log::warning()` (`Snelstart token cache is not available, ...`), the token stays in memory, calls work | |

## Reading

```php
use Darvis\Snelstart\Laravel\Facades\Snelstart;

// One call, at most 500 rows.
$first = Snelstart::relaties()->list(['$top' => 25]);

// Everything, 500 at a time, fetched while you loop.
foreach (Snelstart::relaties()->all(['$filter' => "Relatiesoort/any(r:r eq 'Klant')"]) as $relatie) {
    // ...
}

$relatie = Snelstart::relaties()->find($id);
$company = Snelstart::companyInfo();
$fields = Snelstart::get("relaties/{$id}/customFields");
```

- `list()` on a large collection silently gives the first 500 rows only. For "all customers", "every invoice", a sync or an export, use `all()`.
- `all()` is a generator: `iterator_to_array()` it only when you need the whole list in memory.
- Collections without paging in the SnelStart documentation (`landen()`, `dagboeken()`, `kostenplaatsen()`, `btwtarieven()`, `artikelomzetgroepen()`, `verkoopordersjablonen()`) are one call in `all()` too.
- `prijsafspraken()` needs a `$filter` on `Artikel/Id`.
- A collection that has no method: `Snelstart::resource('artikelen/prijsafspraken')->all()`.

## Writing

```php
$relatie = Snelstart::relaties()->create(['relatiesoort' => ['Klant'], 'naam' => 'Example B.V.']);

$relatie['email'] = 'info@example.com';
$relatie = Snelstart::relaties()->update($relatie['id'], $relatie);   // the whole row

Snelstart::relaties()->delete($relatie['id']);
```

- A reference to another row is an array with its id: `'relatie' => ['id' => $relatieId]`, `'artikel' => ['id' => $artikelId]`.
- `update()` sends a `PUT`; the API documents the whole model for it. Fetch the row, change it, send it back.
- Dates are ISO 8601 strings; amounts are numbers. Don't invent fields: take them from a response or the SnelStart documentation.
- Write from a queued job. Rethrow for status `0`, `429` and `>= 500` so the queue retries; `fail()` for other statuses.

## Error codes

```php
use Darvis\Snelstart\ErrorCodes;
use Darvis\Snelstart\Exceptions\SnelstartException;

try {
    Snelstart::relaties()->create($data);
} catch (SnelstartException $e) {
    if ($e->hasErrorCode('REL-0008')) {
        // Relatiecode reeds in gebruik
    }

    foreach ($e->errors() as $code => $meaning) {
        logger()->warning("SnelStart refused the relation: {$code} {$meaning}");
    }
}

ErrorCodes::message('BOE-0021');    // 'Het factuurnummer bestaat al'
ErrorCodes::resource('BOE-0021');   // 'Boekingen'
```

The prefixes: `ALG` general, `ART` articles, `BLG` attachments, `BOE` bookings, `BTW` VAT returns, `KPL` cost centres, `REF` extra fields, `REL` relations, `VOR` sales orders. The texts are Dutch and written for a developer; translate them yourself before you show one to a user.

## More than one administration

```php
$snelstart = Snelstart::withClientKey($company->snelstart_client_key);

$snelstart->relaties()->all();
```

`withClientKey()` returns a new client with the same subscription key, transport and token store. Tokens are kept per client key. Keep the client keys in an `encrypted` cast, never in plain text and never in a log line.

## Without Laravel

```php
use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;

$snelstart = new Client(new Config($clientKey, $subscriptionKey));
// or: new Client(Config::fromArray([...])), Client::fromEnv()
```

The same class and the same methods. It sends with cURL and keeps the token in memory; pass your own `Transport` or `TokenStore` as second and third argument to change either.

## Testing

In Laravel:

```php
Http::preventStrayRequests();
config(['snelstart.client_key' => 'test-client-key', 'cache.default' => 'array']);

Http::fake([
    'auth.snelstart.nl/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
    'b2bapi.snelstart.nl/*' => Http::response([['naam' => 'Example B.V.']]),
]);
```

- A failure with codes: `Http::response([['errorCode' => 'REL-0007']], 400)`. The package finds the codes anywhere in the body.
- A timeout: a closure that throws `Illuminate\Http\Client\ConnectionException`. The code under test sees a `SnelstartException` with status `0`.
- `all()` asks for a next page after a full one: return fewer than 500 rows.

Anywhere:

```php
use Darvis\Snelstart\Testing\FakeTransport;

$transport = (new FakeTransport)->respond('relaties', [['naam' => 'Example B.V.']]);
$snelstart = new Client(new Config('test-client-key', 'test-subscription-key'), $transport);

$transport->sent('relaties');   // method, url, headers and body of each request
```

In Laravel, `app()->instance(\Darvis\Snelstart\Http\Transport::class, $transport)` makes the facade use it.

## Don't

- Don't call the API with `Http::` or fetch a token yourself.
- Don't put a key or the token in a URL, a log line, an exception message or command output.
- Don't retry a 401 yourself, and don't retry a write after a timeout without checking whether it arrived.
- Don't catch `Illuminate\Http\Client\ConnectionException` around a SnelStart call: it never arrives; catch `SnelstartException` and check for status `0`.
- Don't read settings with `config('snelstart.…')`; use `Snelstart::config()`.
- Don't use the class names of 1.x (`Services\SnelstartAPI`, `Standalone\SnelstartAPI`, `EchoService`, `getRelaties()`): they are gone.
