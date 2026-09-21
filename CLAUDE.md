# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. The conventions shared by every darvis package (language, releases, CI, docs site, Boost guidelines, public API policy) are in [../CLAUDE.md](../CLAUDE.md); this file only holds what is specific to this package.

## Package overview

`darvis/snelstart` is a Laravel package (PHP 8.2+, Laravel 11/12/13) with a client for the SnelStart B2B API v2, the API of the Dutch accounting software SnelStart. It holds the keys to somebody's bookkeeping.

- Namespace: `Darvis\Snelstart\` → `src/`
- Service provider auto-registered via `extra.laravel.providers` in [composer.json](composer.json)
- Config key: `snelstart`
- Container: `Services\SnelstartAPI` (alias `snelstart`) and `Services\EchoService` (alias `snelstart.echo`), both singletons. There is no facade and there are no models; `src/Facades` and `src/Models` were plans in the 1.0 working note that were never built.

## Architecture

- [SnelstartConfig](src/Support/SnelstartConfig.php) is the only place that reads the package config; don't call `config('snelstart.…')` elsewhere. `tests/Feature/ConfigAccessorTest.php` walks `src/`, `resources/` and `routes/` for it.
- [Services\SnelstartAPI](src/Services/SnelstartAPI.php) is the client for Laravel: config, Laravel's HTTP client, Carbon for the token expiry. `request()` is the one method that talks to the API, `getAccessToken()` the one that talks to the token endpoint, `handleError()` the one place an exception for a response is built.
- [Standalone\SnelstartAPI](src/Standalone/SnelstartAPI.php) is the same client for a project without Laravel: a config array or `fromEnv()`, cURL, `DateTime`. It must not use anything from `Illuminate\` or Carbon. The two classes are two implementations of one behaviour, not a base class and a subclass; the known differences are the table in `docs/standalone.md`. A fix in one goes into the other.
- The token lives on the instance until sixty seconds before `expires_in`, and the Laravel client also keeps it in Laravel's cache for that same period (`token_cache.enabled`, `token_cache.store`), encrypted with `Crypt`, under `snelstart.token.<sha256 of token URL|client key>`. `tokenCache()` is the one method that touches the cache: it does nothing when the cache is off, and turns any failure of the store or of the encryption into one warning and a client that works from memory. The standalone client has no cache.
- `getAccessToken()` sets `$tokenIsFresh`: true when the token was fetched from the token endpoint during this call, false when it was held or came from the cache. `request()` repeats a call once after a 401 only when the token was not fresh. `sendRequest()` is one attempt. Both clients have the same three members.
- Timeouts: `timeout` and `connect_timeout` (30 and 10 seconds, the numbers of Laravel's HTTP client), on every request, the token request included. The Laravel client passes them as Guzzle options, the standalone client as `CURLOPT_TIMEOUT_MS` and `CURLOPT_CONNECTTIMEOUT_MS` with `CURLOPT_NOSIGNAL`, which is what Guzzle does too, so `0.5` works in both.
- [EchoService](src/Services/EchoService.php) wraps `/echo/resource` and never throws; it logs and returns `success => false`.
- [TestSnelstartConnection](src/Console/Commands/TestSnelstartConnection.php) resolves the client inside its `try`. Don't inject it into `handle()`: the constructor of the client throws without a client key, and an injected argument is resolved before the `try`, so the user gets a stack trace instead of `Connection failed`.
- The standalone client cannot be faked with `Http::fake()`. `tests/Unit/StandaloneSnelstartApiTest.php` starts [tests/Fixtures/standalone-server.php](tests/Fixtures/standalone-server.php) on `127.0.0.1` with a free port; the path of a request picks the answer, and every request is written to a log file the test reads. For `HEAD` that server keeps the connection open, which is what caught the missing `CURLOPT_NOBODY`.

## Conventions

- Never put a key or the token in a URL. An HTTP exception quotes the URL, and host apps log exception messages. The client key goes in the form body of the token request, the subscription key and the token in headers. The tests assert on the URL of every request.
- Never build an exception message from a response without `redactSecrets()`. The message contains the response body, a server can echo what it received, and the message is what `EchoService` logs and `snelstart:test` prints. Both clients have their own copy of `redactSecrets()`; keep them the same.
- Never put the token in the cache unencrypted. A cache store (file, Redis, database) is often shared with other applications or readable by more people than `.env`, and this token opens a bookkeeping. A value that cannot be decrypted is "no token", never an exception: a rotated `APP_KEY` must not take the integration down.
- Never put the client key in the cache key, not even partly. Cache keys show up in `redis-cli keys`, in cache file names, in debug bars and in monitoring. The key holds a SHA-256 of the token URL and the client key; `TestCase::tokenCacheKey()` pins the format.
- Never let a cache failure fail a call. The cache only saves a token request; `tokenCache()` catches everything and logs once per instance.
- Never retry more than once, and never anything but a 401 on a token that was not fresh. A 401 on a fresh token is about the subscription key or the rights of the client key, and a loop there hammers the token endpoint with a key for a bookkeeping, which is how a key gets blocked. A 429 or a 5xx is the host app's decision (queue, backoff); a repeated `POST` after a 5xx can book something twice, after a 401 it cannot.
- Never send a request without the timeouts, in either client. Without them cURL waits for as long as the server keeps the connection open, and a PHP worker hangs with it.
- Never print or log a key in the command, not even partly ("the first four characters"): command output ends up in deploy logs and CI output.
- Keep the public API compatible within 1.x: the list is in `CONTRIBUTING.md`. It includes the protected methods and properties, because neither client is final, and the wording of the exception messages and log lines, because host apps match on them. Don't merge, rename or remove either client.
- Failures throw a plain `RuntimeException` with code `0`. Host apps catch `RuntimeException`; don't replace it with a class that does not extend it within 1.x.
- Never call SnelStart from a test: it is a real bookkeeping. `TestCase` calls `Http::preventStrayRequests()`; use `fakeSnelstart()` or your own `Http::fake()`. Don't put a real key, customer name or administration id in tests, docs or examples.
- The SnelStart field and endpoint names are Dutch (`relaties`, `artikelen`, `verkooporders`, `naam`) and so are the method names that mirror them (`getRelaties()`). That is the API, not a language violation; everything around them is English.
