---
title: Standalone client
nav_order: 6
description: "Use the SnelStart client in a PHP project without Laravel, with cURL, and see where it behaves differently from the Laravel client."
---

# Standalone client

`Darvis\Snelstart\Standalone\SnelstartAPI` does what the Laravel client does, without the container, the config or Laravel's HTTP client. It uses the PHP cURL extension.

## From an array

```php
use Darvis\Snelstart\Standalone\SnelstartAPI;

$snelstart = new SnelstartAPI([
    'client_key' => $clientKey,
    'subscription_key' => $subscriptionKey,
]);

$company = $snelstart->getCompanyInfo();
$relations = $snelstart->getRelaties();
```

`base_url` and `token_url` are optional; they fall back to `https://b2bapi.snelstart.nl/v2` and `https://auth.snelstart.nl/b2b/token`. Without a `client_key` the constructor throws `Snelstart API config is incomplete (token_url, client_key).`

## From the environment

```php
$snelstart = SnelstartAPI::fromEnv();
```

`fromEnv()` reads `SNELSTART_BASE_URL`, `SNELSTART_TOKEN_URL`, `SNELSTART_CLIENT_KEY` and `SNELSTART_SUBSCRIPTION_KEY` with `getenv()`. It does not load a `.env` file; the variables have to be in the real environment.

## Which one do I use?

| | `Services\SnelstartAPI` | `Standalone\SnelstartAPI` |
| --- | --- | --- |
| Meant for | A Laravel application | A project without Laravel |
| Settings | `config/snelstart.php` and `.env` | An array, or `getenv()` |
| Built by | The container, as a singleton | You, with `new` or `fromEnv()` |
| HTTP | Laravel's HTTP client | cURL |
| Fake in tests | `Http::fake()` | Not possible; point `base_url` and `token_url` at your own test server |
| Timeout | 30 seconds, 10 to connect | **None**: a call waits for as long as the server keeps the connection open |
| Connection error | `Illuminate\Http\Client\ConnectionException` | `RuntimeException` with `cURL error: ...` |
| Error body in the message | JSON is decoded and encoded again | The body as it was received |

The methods, the token handling, the messages of the exceptions and the redaction of the keys are the same. Inside Laravel, use the Laravel client: it is the one `snelstart:test` and `EchoService` use, and the only one your tests can fake.

Because the standalone client has no timeout, don't call it from a web request without a limit of your own, such as `set_time_limit()` or the timeout of the queue worker.

## Requirements

- PHP 8.2+
- The cURL extension

Composer installs the `illuminate/*` packages the Laravel client needs in every project; the standalone client does not load them.
