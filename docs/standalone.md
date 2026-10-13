---
title: "Without Laravel"
nav_order: 6
description: "Use darvis/snelstart in a plain PHP project: build the client from an array or the environment, the cURL transport, and how to keep the token between runs."
---

# Without Laravel

The client is plain PHP. Outside Laravel it needs PHP 8.2+ and the cURL extension, and nothing else: the package has no other dependencies.

```bash
composer require darvis/snelstart
```

## Build the client

```php
use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;

$snelstart = new Client(new Config(
    clientKey: $clientKey,
    subscriptionKey: $subscriptionKey,
));
```

Or from loose values, such as your own config file, where an empty or missing value gives the default:

```php
$snelstart = new Client(Config::fromArray([
    'client_key' => $settings['snelstart_client_key'],
    'subscription_key' => $settings['snelstart_subscription_key'],
    'timeout' => 15,
]));
```

Or from the environment, with `SNELSTART_CLIENT_KEY` and `SNELSTART_SUBSCRIPTION_KEY` set:

```php
$snelstart = Client::fromEnv();
```

The package reads `$_ENV` and `getenv()`. It does not load a `.env` file; use your own loader for that.

## Use it

Everything on the other pages applies; only the facade is Laravel.

```php
use Darvis\Snelstart\Exceptions\SnelstartException;

try {
    foreach ($snelstart->artikelen()->all() as $artikel) {
        echo $artikel['artikelcode'], PHP_EOL;
    }

    $order = $snelstart->verkooporders()->create($data);
} catch (SnelstartException $e) {
    error_log($e->status().' '.implode(', ', $e->errorCodes()).' '.$e->getMessage());
}
```

## How it differs from Laravel

| | Without Laravel | In Laravel |
| --- | --- | --- |
| Requests are sent with | PHP cURL (`Http\CurlTransport`) | Laravel's HTTP client (`Laravel\HttpTransport`) |
| The token is kept | in memory, for as long as the PHP process lives | in the cache, encrypted, and in memory |
| Settings come from | the `Config` you build | `config/snelstart.php` and `.env` |
| In tests | `Testing\FakeTransport` | `Http::fake()`, or `Testing\FakeTransport` |
| Connection test | `$snelstart->echo()` and `$snelstart->companyInfo()` | `php artisan snelstart:test` |

The client itself is the same class, so there is no difference in behaviour to remember.

## Keep the token between runs

In a web application without Laravel every request is a new PHP process, and each one fetches its own token. That works, and it costs one extra call per request. To share the token, give the client a token store of your own: implement `Darvis\Snelstart\Token\TokenStore` on what you have (APCu, Redis, a database).

```php
use Darvis\Snelstart\Token\TokenStore;

final class ApcuTokenStore implements TokenStore
{
    public function get(string $key): ?string
    {
        $token = apcu_fetch($key, $found);

        return $found && is_string($token) ? $token : null;
    }

    public function put(string $key, string $token, int $seconds): void
    {
        apcu_store($key, $token, $seconds);
    }

    public function forget(string $key): void
    {
        apcu_delete($key);
    }

    public function synchronized(string $key, int $seconds, callable $callback): mixed
    {
        return $callback();
    }
}

$snelstart = new Client($config, null, new ApcuTokenStore);
```

Three rules for a store:

- It never throws. When it cannot keep a token, the client fetches one more.
- `get()` returns `null` for a token that has expired.
- The token opens a bookkeeping. APCu memory belongs to your own PHP processes; a store that others can read, such as a shared Redis or a file, needs the token encrypted.

`synchronized()` may run the callback under a lock, so that processes which find the store empty at the same moment make one token request. Running it without a lock, as above, is always correct.

## Your own transport

`Darvis\Snelstart\Http\Transport` has one method. Implement it to send the requests with the HTTP client your project already has, for example to get its logging or its proxy settings:

```php
use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Http\Transport;

final class MyTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        // Send it, and return new Response($status, $rawBody) for every answer, a 4xx or 5xx included.
        // Throw when there was no answer. Do not retry and do not follow redirects.
        // The headers hold the keys: keep them and the body out of exception messages.
    }
}
```
