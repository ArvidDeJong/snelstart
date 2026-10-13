---
title: "How it works"
nav_order: 4
description: "How darvis/snelstart works: one plain PHP client, the token and where it is kept, the repeat after a 401, paging, error codes, timeouts and where keys go."
---

# How it works

## One client, two ways to run it

`Darvis\Snelstart\Client` is plain PHP. It builds every request itself (URL, headers, body) and hands it to two small parts it gets from outside:

| Part | Without Laravel | In Laravel |
| --- | --- | --- |
| Transport: sends one request | `Http\CurlTransport`, PHP cURL | `Laravel\HttpTransport`, Laravel's HTTP client, so `Http::fake()` works |
| Token store: keeps the token between calls | `Token\MemoryTokenStore`, for as long as the PHP process lives | `Laravel\CacheTokenStore`, Laravel's cache, encrypted, plus memory |

Everything else is the same code in both: authentication, the repeat after a 401, paging, the exception, the redaction of keys. There is nothing to keep in step.

## Authentication

SnelStart uses two keys. The subscription key goes with every call in the `Ocp-Apim-Subscription-Key` header. The client key is exchanged for an access token:

```text
POST https://auth.snelstart.nl/b2b/token
Content-Type: application/x-www-form-urlencoded

grant_type=clientkey&clientkey=<your client key>
```

The token from the answer goes with every API call in the `Authorization: Bearer` header. The client does this before the first call; you never fetch a token yourself.

## Where the token is kept

A token is kept until sixty seconds before SnelStart says it expires (`expires_in`, 3600 seconds when the answer does not say). A token that would expire within those sixty seconds is used for the one call and not kept.

In Laravel the token is kept in memory and in the cache, so a web request or a queue worker does not fetch its own:

- It is encrypted with your `APP_KEY`. A cached value that cannot be decrypted, for example after the key was rotated, counts as no token: it is removed and a new token is fetched.
- The cache key is `snelstart.token.` plus a SHA-256 hash of the token URL and the client key. The client key itself is not in it.
- When several requests find the cache empty at the same moment, one fetches a token under a lock and the others wait at most five seconds and use that token.
- The cache never fails a call. A store that is down, a store without locks or a lock that fails means one warning in the log (`Snelstart token cache is not available, the token is kept in memory only: ...`) and a client that works from memory.

`SNELSTART_TOKEN_CACHE=false` keeps the token in memory only. `php artisan cache:clear` only makes the next call fetch a new token. `forgetToken()` drops the token on purpose.

## The one repeat after a 401

When SnelStart answers a call with a 401 and the token was one the client already had, the client forgets that token, fetches a new one and repeats the call once. A 401 on a token that was fetched for this very call is not repeated: that 401 is not about the token, and repeating it would only send the client key to the token endpoint again and again.

Nothing else is ever repeated. A 429, a 5xx and a timeout are thrown as they are, because only your application knows whether a call may be sent twice.

## Collections and paging

A collection such as `relaties()` is a `Darvis\Snelstart\Resource`: a path with six methods.

| Method | Call |
| --- | --- |
| `list($query)` | `GET /relaties`, one call |
| `all($query, $pageSize)` | `GET /relaties` with `$skip` and `$top`, page by page |
| `find($id)` | `GET /relaties/<id>` |
| `create($data)` | `POST /relaties` |
| `update($id, $data)` | `PUT /relaties/<id>` |
| `delete($id)` | `DELETE /relaties/<id>` |

The SnelStart documentation gives 500 as the default for `$top`, the maximum number of results of one call. `list()` therefore returns at most the first 500 rows unless you page yourself. `all()` does the paging: it asks for 500 rows, hands them to your loop one by one, and asks for the next 500 only when your loop gets there. It stops after a page that is not full, and throws when a collection answers with the same page twice instead of the next one.

```php
foreach ($snelstart->verkoopfacturen()->all(['$filter' => 'openstaandSaldo gt 0']) as $factuur) {
    // ...
}
```

Some collections have no paging in the SnelStart documentation (`landen()`, `dagboeken()`, `kostenplaatsen()` and a few more). For those `all()` is one call without `$skip` and `$top`. Which is which is in the [API reference](api-reference.md#collections).

The package does not know which methods a collection supports. Calling one SnelStart does not have, such as `btwtarieven()->delete($id)`, is answered by SnelStart with a 404 or a 405, which arrives as a `SnelstartException`.

## Query and body

A query array is encoded once, by the client: `['$filter' => "naam eq 'Jansen'", '$top' => 10]` becomes `?%24filter=naam%20eq%20%27Jansen%27&%24top=10`. `true` and `false` are sent as the words `true` and `false`.

A data array is sent as JSON. An empty array sends no body. What comes back is decoded: an array for a JSON object or list, `null` for an empty response, and the raw string when the response is not JSON. The methods of a collection always return an array.

## When a call fails

Every failure is a `Darvis\Snelstart\Exceptions\SnelstartException`, which extends `RuntimeException`.

| What happened | `status()` | Message starts with |
| --- | --- | --- |
| The API answered with a 4xx or 5xx | that status | `Snelstart API call failed. HTTP status: 429. Response: ...` |
| The token endpoint answered with a 4xx or 5xx | that status | `Failed to retrieve access_token from Snelstart. HTTP status: 400. Response: ...` |
| The token endpoint answered without a token | the status of that answer | `Snelstart token response does not contain access_token.` |
| There was no response: timeout, DNS, refused connection | `0` | `Snelstart API call failed. No response: ...` |
| The client key is empty | `0` | `Snelstart config is incomplete: the client key is empty.` |
| The data cannot be encoded as JSON | `0` | `Snelstart request data cannot be sent as JSON: ...` |
| `all()` got the same page twice | `0` | `Snelstart paging does not advance: ...` |

For a missing response the cause, such as Laravel's `ConnectionException`, is the previous exception: `$e->getPrevious()`.

### SnelStart error codes

With a 400 SnelStart often sends its own error codes: three letters for the part of the API and four digits, such as `REL-0007`. The exception finds the codes in the response, whatever the shape of that response:

```php
try {
    $snelstart->relaties()->create($data);
} catch (SnelstartException $e) {
    $e->errorCodes();              // ['REL-0007', 'REL-0011']
    $e->hasErrorCode('REL-0008');  // false
    $e->errors();                  // ['REL-0007' => 'Naam moet gevuld zijn', 'REL-0011' => 'Iban is ongeldig']
}
```

The texts in `errors()` come from the list SnelStart publishes, which the package carries in `Darvis\Snelstart\ErrorCodes` (272 codes, in Dutch, as SnelStart wrote them). A number that is not in that list yet has `null`. Decide on the code, never on the text.

The codes are found by their shape, anywhere in the body. When your own data has that shape, say an article code `ART-0002` that SnelStart quotes in its answer, it is listed too.

The response body is not a property of the exception. It can hold data of the administration, and a property ends up in error trackers that serialise exceptions. The message has the body, with the keys redacted.

## Timeouts

Every request, the token request included, gives up after 30 seconds, and after 10 seconds when no connection is made. `SNELSTART_TIMEOUT` and `SNELSTART_CONNECT_TIMEOUT` change that; a value below one second, such as `0.5`, works.

## More than one administration

A client key belongs to one administration. `withClientKey()` gives the same client for another one:

```php
$nl = Snelstart::withClientKey($company->snelstart_client_key);
$be = Snelstart::withClientKey($other->snelstart_client_key);

$nl->relaties()->list();
$be->relaties()->list();
```

Both share the subscription key, the transport and the token store. Tokens are kept per client key, so the administrations never see each other's token. Store client keys encrypted, for example with an `encrypted` cast.

## Where the keys go

- The client key goes in the body of the token request, the subscription key and the token in headers. Never in a URL: error messages and logs quote URLs.
- Before a message is thrown the client replaces the client key, the subscription key and the access token in it with `[redacted]`, also in their JSON escaped, URL encoded and HTML encoded forms. A server can echo what it received.
- The keys are marked as sensitive parameters, so PHP leaves them out of stack traces.
- No redirect is followed: it would carry the keys to another host.
- In Laravel the requests pass through the HTTP client and its events like any other. A tool that records outgoing requests, such as Telescope, records these too, with the subscription key header, the `clientkey` field of the token request and the `access_token` in its answer. Check what yours stores, and hide those three.
- `php artisan snelstart:test` prints the name of the administration and nothing else from it.
- The rest of an error response can hold data of the administration. Who can read your logs is up to your application.
