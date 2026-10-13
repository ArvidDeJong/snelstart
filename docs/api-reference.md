---
title: "API reference"
nav_order: 5
description: "Every public class of darvis/snelstart: Client, Resource, Config, ErrorCodes, SnelstartException, the transports and token stores, the facade and command."
---

# API reference

## Darvis\Snelstart\Client

```php
new Client(Config $config, ?Transport $transport = null, ?TokenStore $tokens = null)
```

Without a transport it uses `CurlTransport`, without a token store `MemoryTokenStore`. In Laravel you do not build it: resolve `Client::class` or use the facade. Every method that makes a call throws a `SnelstartException` when it fails, see [How it works](concepts.md#when-a-call-fails).

| Method | What it does |
| --- | --- |
| `Client::fromEnv(): Client` | A client with the keys from the environment, see `Config::fromEnv()` |
| `withClientKey(string $clientKey): Client` | The same client for another administration; shares the transport and the token store |
| `config(): Config` | The settings of this client |
| `get(string $path, array $query = []): mixed` | `GET` with a query string |
| `post(string $path, array $data = [], array $query = []): mixed` | `POST` with a JSON body |
| `put(string $path, array $data = [], array $query = []): mixed` | `PUT` with a JSON body |
| `delete(string $path, array $query = []): mixed` | `DELETE` with a query string |
| `resource(string $path, bool $paged = true): Resource` | Any collection by its path |
| `companyInfo(): array` | `GET /companyInfo`: the administration the client key belongs to |
| `echo(): mixed` | `GET <echo_url>/resource?param1=sample`: the Echo API, with the subscription key only; no token is fetched |
| `forgetToken(): void` | No call: drops the access token, so the next call fetches a new one |

- `$path` is relative to the base URL, with or without a leading slash.
- An empty `$data` array sends no body.
- `get()`, `post()`, `put()` and `delete()` return the decoded response: an array, `null` for an empty response, or the raw string when it is not JSON.

### Collections

Each of these returns a `Resource` for the path of the same name.

| Method | Paged | In the SnelStart documentation |
| --- | --- | --- |
| `actieprijzen()` | yes | list |
| `artikelen()` | yes | list, find, create, update, delete |
| `artikelomzetgroepen()` | no | list, find |
| `bankboekingen()` | yes | list, find, create, update, delete |
| `btwaangiftes()` | yes | list, find |
| `btwtarieven()` | no | list |
| `dagboeken()` | no | list |
| `documenten()` | no | find, update, delete; create per type with `post('documenten/Verkoopboekingen', $data)` |
| `grootboeken()` | yes | list, find, create |
| `grootboekmutaties()` | yes | list, find |
| `inkoopboekingen()` | no | find, create, update, delete |
| `inkoopfacturen()` | yes | list |
| `kasboekingen()` | yes | list, find, create, update, delete |
| `kostenplaatsen()` | no | list, find, create, update, delete |
| `landen()` | no | list, find |
| `memoriaalboekingen()` | no | find, create, update, delete |
| `offertes()` | yes | list, find, create, update, delete |
| `prijsafspraken()` | yes | list; a `$filter` on `Artikel/Id` is required |
| `relaties()` | yes | list, find, create, update, delete |
| `vatratedefinitions()` | yes | list |
| `vatrates()` | yes | list, find |
| `verkoopboekingen()` | no | find, create, update, delete |
| `verkoopfacturen()` | yes | list, find |
| `verkooporders()` | yes | list, find, create, update, delete |
| `verkoopordersjablonen()` | no | list, find |

The last column is what the SnelStart developer portal listed when this version was made. The package does not enforce it: a method SnelStart does not have is answered by SnelStart.

Endpoints that are not a plain collection go through `get()`, `post()` and `put()`. Some of them:

| Call | Endpoint |
| --- | --- |
| `get("relaties/{$id}/customFields")`, `put(...)` | The extra fields of a relation |
| `get("relaties/{$id}/verkoopboekingen")`, `get("relaties/{$id}/inkoopboekingen")` | The bookings of a relation |
| `get("relaties/{$id}/doorlopendeincassomachtigingen")` | The direct debit mandates of a relation |
| `get("artikelen/{$id}/customFields")`, `put(...)` | The extra fields of an article |
| `resource('artikelen/prijsafspraken')->all()` | The price agreements per article |
| `put("verkooporders/{$id}/ProcesStatus", ['procesStatus' => ...])` | The process status of a sales order |
| `get("verkoopfacturen/{$id}/ubl")` | A sales invoice as UBL |
| `post('inkoopboekingen/ubl', $data)` | A purchase invoice from UBL |
| `get("documenten/{$type}/{$parentId}")` | The attachments of a booking or relation |
| `put("btwaangiftes/{$id}/externAangeven", $data)` | Mark a VAT return as filed outside SnelStart |
| `get('rapportages/periodebalans', ['start' => ..., 'end' => ...])` | The period balance; `rapportages/kolommenbalans` likewise |
| `post('bankafschriftbestanden', $files)` | Upload bank statement files |

## Darvis\Snelstart\Resource

| Method | Call |
| --- | --- |
| `list(array $query = []): array` | `GET` the collection, one call |
| `all(array $query = [], int $pageSize = 500): Generator` | Every row, fetched page by page while you loop. For a collection without paging: one call |
| `find(string $id, array $query = []): array` | `GET` one row |
| `create(array $data): array` | `POST` a new row, returns the row SnelStart answers with |
| `update(string $id, array $data): array` | `PUT` a row; the API documents a whole model for this call |
| `delete(string $id): void` | `DELETE` a row |

`$query` takes the OData options of the endpoint: `'$filter'`, `'$top'` and `'$skip'`. In `all()` a `'$skip'` is where it starts and `'$top'` is ignored; `$pageSize` is at most 500, and a collection that returns the same page twice ends in a `SnelstartException`. A response that is not a list, or not a row, gives `[]`.

## Darvis\Snelstart\Config

Immutable. Every default lives here.

```php
new Config(
    string $clientKey = '',
    string $subscriptionKey = '',
    string $baseUrl = 'https://b2bapi.snelstart.nl/v2',
    string $echoUrl = 'https://b2bapi.snelstart.nl/echo',
    string $tokenUrl = 'https://auth.snelstart.nl/b2b/token',
    float $timeout = 30,
    float $connectTimeout = 10,
)
```

| Member | What it is |
| --- | --- |
| `Config::fromArray(array $values): Config` | From the keys `base_url`, `client_key`, `connect_timeout`, `echo_url`, `subscription_key`, `timeout` and `token_url`. A value that is missing, empty or not usable gives the default |
| `Config::fromEnv(): Config` | From `SNELSTART_BASE_URL`, `SNELSTART_CLIENT_KEY`, `SNELSTART_CONNECT_TIMEOUT`, `SNELSTART_ECHO_URL`, `SNELSTART_SUBSCRIPTION_KEY`, `SNELSTART_TIMEOUT` and `SNELSTART_TOKEN_URL` |
| `withClientKey(string $clientKey): Config` | The same settings with another client key |
| `$clientKey`, `$subscriptionKey`, `$baseUrl`, `$echoUrl`, `$tokenUrl`, `$timeout`, `$connectTimeout` | Read only properties. The URLs have no trailing slash; a timeout that is not positive is the default |

## Darvis\Snelstart\Exceptions\SnelstartException

Extends `RuntimeException`. The only exception the client throws.

| Method | Returns |
| --- | --- |
| `status(): int` | The HTTP status of the response that caused it, or `0` when there was no response |
| `getCode()` | The same number |
| `errorCodes(): array` | The SnelStart error codes in the response, such as `['REL-0007']`, or `[]` |
| `hasErrorCode(string ...$codes): bool` | Whether the response had one of these codes |
| `errors(): array` | The codes with what SnelStart documents for each: `['REL-0007' => 'Naam moet gevuld zijn']`; `null` for a code the package does not know |
| `getMessage()` | See [How it works](concepts.md#when-a-call-fails); the keys and the token are redacted |
| `getPrevious()` | For a missing response: what the transport threw |

## Darvis\Snelstart\ErrorCodes

The error codes of the B2B API v2 as SnelStart publishes them.

| Member | What it is |
| --- | --- |
| `ErrorCodes::MESSAGES` | `code => text`, 272 codes, the texts in Dutch as SnelStart wrote them |
| `ErrorCodes::RESOURCES` | The three letters of a code and the part of the API: `ALG` Algemeen, `ART` Artikelen, `BLG` Bijlagen, `BOE` Boekingen, `BTW` Btw Aangifte, `KPL` Kostenplaatsen, `REF` Extra velden, `REL` Relaties, `VOR` Verkooporders |
| `ErrorCodes::message(string $code): ?string` | The text of a code, or `null` |
| `ErrorCodes::resource(string $code): ?string` | `'Relaties'` for `REL-0007`, or `null` |
| `ErrorCodes::find(string $text): array` | Every code of these resources in a text, each one once |

## Transports and token stores

| Class | What it is |
| --- | --- |
| `Http\Transport` | Interface with one method: `send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Http\Response`. Returns a response for every answer, a 4xx or 5xx included, and throws when there was none. Follows no redirect |
| `Http\Response` | `$status`, `$body`, `successful()` and `decoded()` |
| `Http\CurlTransport` | PHP cURL. The default without Laravel |
| `Laravel\HttpTransport` | Laravel's HTTP client. The default in Laravel |
| `Testing\FakeTransport` | For tests, see [Testing](testing.md) |
| `Token\TokenStore` | Interface: `get(string $key): ?string`, `put(string $key, string $token, int $seconds): void`, `forget(string $key): void` and `synchronized(string $key, int $seconds, callable $callback): mixed` |
| `Token\MemoryTokenStore` | Keeps the token on the instance |
| `Laravel\CacheTokenStore` | Laravel's cache, encrypted, with a lock, plus memory |

## Laravel

### Container bindings

| Abstract | Alias | Lifetime | Concrete |
| --- | --- | --- | --- |
| `Darvis\Snelstart\Client` | `snelstart` | singleton | built from the config on first use |
| `Darvis\Snelstart\Http\Transport` | | singleton | `Laravel\HttpTransport` |
| `Darvis\Snelstart\Token\TokenStore` | | singleton | `Laravel\CacheTokenStore`, or `Token\MemoryTokenStore` when the token cache is off |

The transport and the token store are bound only when your application has not bound them itself, so you can swap either one. The client is built the first time it is resolved: an application without keys boots normally and fails where it calls SnelStart.

### Facade

`Darvis\Snelstart\Laravel\Facades\Snelstart`, alias `Snelstart`, reaches the `Client` singleton: `Snelstart::relaties()->all()`.

### Darvis\Snelstart\Laravel\SnelstartConfig

The one place in the package that reads `config('snelstart')`.

| Method | Returns |
| --- | --- |
| `connection(): Config` | The connection settings from the config file |
| `tokenCacheEnabled(): bool` | `snelstart.token_cache.enabled`, `true` unless it is a no (`false`, `0`, `off`, `no`) |
| `tokenCacheStore(): ?string` | `snelstart.token_cache.store`, or `null` for the default cache store |

### Artisan

| Command | What it does |
| --- | --- |
| `php artisan snelstart:test` | Checks the subscription key against the Echo API and the client key against `/companyInfo`, prints the name of the administration, exits with 0 or 1 |

### Publish tags

| Tag | Publishes |
| --- | --- |
| `snelstart-config` | `config/snelstart.php` |
