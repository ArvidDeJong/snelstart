---
title: "Standalone client"
nav_order: 6
description: "Use darvis/snelstart in a PHP project without Laravel: the cURL based standalone client, its settings and timeouts, and how it differs from the Laravel client."
---

# Standalone client

`Darvis\Snelstart\Standalone\SnelstartAPI` does what the Laravel client does, without the container, the config or Laravel's HTTP client. It uses the PHP cURL extension.

## Build the client from an array

```php
use Darvis\Snelstart\Standalone\SnelstartAPI;

$snelstart = new SnelstartAPI([
    'client_key' => $clientKey,
    'subscription_key' => $subscriptionKey,
]);

$company = $snelstart->getCompanyInfo();
$relations = $snelstart->getRelaties();
```

This goes in any PHP file of your project that loads Composer's `vendor/autoload.php`. `$clientKey` and `$subscriptionKey` are the two keys SnelStart issued; read them from your own configuration, never from the source code.

`base_url` and `token_url` are optional; they fall back to `https://b2bapi.snelstart.nl/v2` and `https://auth.snelstart.nl/b2b/token`. So are `timeout` and `connect_timeout`, in seconds; they fall back to 30 and 10, also when the value is not a positive number. Without a `client_key` the constructor throws `Snelstart API config is incomplete (token_url, client_key).`

## Build the client from environment variables

```php
$snelstart = SnelstartAPI::fromEnv();
```

`fromEnv()` reads `SNELSTART_BASE_URL`, `SNELSTART_TOKEN_URL`, `SNELSTART_CLIENT_KEY`, `SNELSTART_SUBSCRIPTION_KEY`, `SNELSTART_TIMEOUT` and `SNELSTART_CONNECT_TIMEOUT` with `getenv()`. It does not load a `.env` file; the variables have to be in the real environment.

## Which of the two clients do I use?

| | `Services\SnelstartAPI` | `Standalone\SnelstartAPI` |
| --- | --- | --- |
| Meant for | A Laravel application | A project without Laravel |
| Settings | `config/snelstart.php` and `.env` | An array, or `getenv()` |
| Built by | The container, as a singleton | You, with `new` or `fromEnv()` |
| HTTP | Laravel's HTTP client | cURL |
| Fake in tests | `Http::fake()` | Not possible; point `base_url` and `token_url` at your own test server |
| Timeout | 30 seconds, 10 to connect, from the config | 30 seconds, 10 to connect, from the array |
| Access token | In memory and, encrypted, in Laravel's cache | In memory, on the instance |
| Connection error | `Illuminate\Http\Client\ConnectionException`, unchanged | `SnelstartException` with `cURL error: ...` and status `0` |
| Lock around the token request | Yes, on the cache store | No: there is no cache to share |
| Error body in the message | JSON is decoded and encoded again | The body as it was received |

The methods, the one repeat after a refused token, the `SnelstartException` with its status and messages, and the redaction of the keys are the same. Inside Laravel, use the Laravel client: it is the one `snelstart:test` and `EchoService` use, and the only one your tests can fake.

## A call that needs more than 30 seconds

Up to 1.1 the standalone client had no timeout at all. It now gives up after 30 seconds with `cURL error: Operation timed out ...`. For a call that really needs longer, raise it:

```php
$snelstart = new SnelstartAPI([
    'client_key' => $clientKey,
    'subscription_key' => $subscriptionKey,
    'timeout' => 120,
]);
```

Because the token lives on the instance, a script that builds a new client for every call also fetches a new token for every call. Build it once and pass it around.

## Requirements

- PHP 8.2+
- The cURL extension

Composer installs the `illuminate/*` packages the Laravel client needs in every project; the standalone client does not load them.
