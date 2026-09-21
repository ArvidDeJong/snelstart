---
title: API reference
nav_order: 5
description: "Every public method of the SnelStart client, the echo service, the config accessor, the artisan command and the container bindings."
---

# API reference

## Darvis\Snelstart\Services\SnelstartAPI

The constructor reads the config and throws a `RuntimeException` when the token URL or the client key is empty. Every method returns an array and throws on a 4xx or 5xx, see [How it works](concepts.md).

| Method | Request |
| --- | --- |
| `getCompanyInfo(): array` | `GET /companyInfo` |
| `getRelaties(array $query = []): array` | `GET /relaties` |
| `createRelatie(array $data): array` | `POST /relaties` |
| `getArtikelen(array $query = []): array` | `GET /artikelen` |
| `createVerkooporder(array $data): array` | `POST /verkooporders` |
| `get(string $uri, array $query = []): array` | `GET` with a query string |
| `post(string $uri, array $data = []): array` | `POST` with a JSON body |
| `put(string $uri, array $data = []): array` | `PUT` with a JSON body |
| `delete(string $uri, array $query = []): array` | `DELETE` with a query string |
| `head(string $uri, array $query = []): array` | `HEAD` with a query string, always returns `[]` |

- `$uri` is relative to the base URL, with or without a leading slash.
- An empty `$data` array sends no body at all.

The class is not final. The protected members `request()`, `getAccessToken()`, `handleError()`, `redactSecrets()` and the properties `$baseUrl`, `$tokenUrl`, `$clientKey`, `$subscriptionKey`, `$accessToken` and `$tokenExpiresAt` are there for a subclass.

## Darvis\Snelstart\Services\EchoService

Calls the `/echo/resource` endpoint through the client, to see that a request makes the round trip. It never throws.

| Method | Request | Without arguments it sends |
| --- | --- | --- |
| `getEchoResource(array $params = []): array` | `GET /echo/resource` | `param1=sample` |
| `headEchoResource(array $params = []): array` | `HEAD /echo/resource` | `param1=sample` |
| `postEchoResource(array $data = []): array` | `POST /echo/resource` | a sample vehicle (`vehicleType`, `maxSpeed`, `avgSpeed`, `speedUnit`) |

On success:

```php
[
    'success' => true,
    'message' => 'Echo resource GET successful',
    'response_time_ms' => 182.41,
    'timestamp' => '2026-09-21T10:00:00.000000Z',
    'query_params' => ['param1' => 'sample'],   // 'request_data' for the POST
    'response' => [...],
]
```

On a failure:

```php
[
    'success' => false,
    'message' => 'Echo resource GET failed',
    'error' => 'Snelstart API call failed. HTTP status: 401. ...',
    'error_code' => 0,
    'timestamp' => '2026-09-21T10:00:00.000000Z',
]
```

`error_code` is the code of the exception, which is `0` for every failure of the client; it is not the HTTP status.

## Darvis\Snelstart\Standalone\SnelstartAPI

The same ten methods, plus:

| Method | What it does |
| --- | --- |
| `__construct(array $config)` | Keys `base_url`, `token_url`, `client_key`, `subscription_key`. The URLs fall back to the SnelStart URLs. |
| `fromEnv(): self` | Reads `SNELSTART_BASE_URL`, `SNELSTART_TOKEN_URL`, `SNELSTART_CLIENT_KEY` and `SNELSTART_SUBSCRIPTION_KEY` with `getenv()`. |

See [Standalone client](standalone.md).

## Darvis\Snelstart\Support\SnelstartConfig

| Method | Returns |
| --- | --- |
| `baseUrl(): string` | `snelstart.base_url` without a trailing slash, default `https://b2bapi.snelstart.nl/v2` |
| `clientKey(): string` | `snelstart.client_key`, or an empty string |
| `subscriptionKey(): ?string` | `snelstart.subscription_key`, or `null` when it is empty |
| `tokenUrl(): string` | `snelstart.token_url`, default `https://auth.snelstart.nl/b2b/token` |

## Container bindings

| Abstract | Alias | Lifetime |
| --- | --- | --- |
| `Darvis\Snelstart\Services\SnelstartAPI` | `snelstart` | singleton |
| `Darvis\Snelstart\Services\EchoService` | `snelstart.echo` | singleton |

The client is built the first time it is resolved, so an application without keys boots normally and only fails where it uses the client. The package has no facade.

## Artisan

| Command | What it does |
| --- | --- |
| `php artisan snelstart:test` | Fetches `/companyInfo`, prints the result as JSON, exits with 0 or 1 |

## Publish tags

| Tag | Publishes |
| --- | --- |
| `snelstart-config` | `config/snelstart.php` |
