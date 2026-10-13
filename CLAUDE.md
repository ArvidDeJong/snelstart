# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. The conventions shared by every darvis package (language, releases, CI, docs site, Boost guidelines, public API policy) are in [../CLAUDE.md](../CLAUDE.md); this file only holds what is specific to this package.

## Package overview

`darvis/snelstart` is a PHP client (PHP 8.2+, no dependencies) for the SnelStart B2B API v2, the API of the Dutch accounting software SnelStart, with a Laravel integration (Laravel 11/12/13) on top. It holds the keys to somebody's bookkeeping.

- Namespace: `Darvis\Snelstart\` → `src/`. Everything outside `src/Laravel` is plain PHP.
- Service provider `Laravel\SnelstartServiceProvider` and facade `Laravel\Facades\Snelstart`, both auto-registered via `extra.laravel` in [composer.json](composer.json). `illuminate/*` is not required, only excluded below 11 with `conflict`.
- Config key: `snelstart`
- Container: `Client` (alias `snelstart`), `Http\Transport` and `Token\TokenStore`, all singletons. There are no models.
- The API documentation is behind a login: https://b2bapi-developer.snelstart.nl/apis. The error codes are on https://b2bapi-developer.snelstart.nl/foutcodes-v2.

## Commands

The cURL transport test starts `php -S` with `PHP_BINARY`; set `PHP_SERVER_BINARY` when that binary cannot serve.

## Architecture

- [Client](src/Client.php) is the whole behaviour, once: it builds the URL, the headers and the body, fetches and keeps the token, repeats once after a 401, decodes, and builds the exception. `request()` is the one method that calls the API, `fetchToken()` the one that calls the token endpoint, `send()` the one attempt, `decode()` the one place an exception for a response is built.
- [Http\Transport](src/Http/Transport.php) carries one request and returns status and raw body. [CurlTransport](src/Http/CurlTransport.php) is the default, [Laravel\HttpTransport](src/Laravel/HttpTransport.php) uses Laravel's HTTP client so `Http::fake()` works. A transport decides nothing: no retry, no redirect (it would carry the keys to another host), no exception for a 4xx.
- [Token\TokenStore](src/Token/TokenStore.php) keeps the token: [MemoryTokenStore](src/Token/MemoryTokenStore.php), and [Laravel\CacheTokenStore](src/Laravel/CacheTokenStore.php), which is memory plus Laravel's cache, encrypted with `Crypt`, with a lock around the token request (`synchronized()`). The key is `snelstart.token.<sha256 of token URL|client key>`; the client makes it.
- The token is kept until sixty seconds before `expires_in` (`TOKEN_MARGIN_SECONDS`). `token()` returns the token and whether it was fetched just now; `request()` repeats a call once after a 401 only when it was not.
- [Resource](src/Resource.php) is a path with `list()`, `all()`, `find()`, `create()`, `update()`, `delete()`. The named collections on the client (`relaties()` ...) only say the path and whether the SnelStart documentation gives the collection `$skip` and `$top`; `all()` pages only when it does.
- [Config](src/Config.php) is immutable and holds every default. `fromArray()` normalises loose values for the Laravel config, an array and the environment alike. [Laravel\SnelstartConfig](src/Laravel/SnelstartConfig.php) is the only place that calls `config()`.
- [ErrorCodes](src/ErrorCodes.php) is the list of SnelStart error codes. `decode()` passes the codes found in a failed response to the [SnelstartException](src/Exceptions/SnelstartException.php).
- The Echo API is a separate API with its own base URL (`echo_url`), not a path below `/v2`, and it takes the subscription key only. `echo()` and `snelstart:test` use it to tell a wrong subscription key from a wrong client key.
- [Testing\FakeTransport](src/Testing/FakeTransport.php) ships in `src/` on purpose: host apps without Laravel test with it, and so does `tests/Unit`.
- `tests/Unit` runs without Laravel (no `TestCase`), `tests/Laravel` on Testbench. The cURL transport cannot be faked: `tests/Unit/CurlTransportTest.php` starts [tests/Fixtures/server.php](tests/Fixtures/server.php) on `127.0.0.1`, which answers with what it received. That server handles one request at a time, so the tests that make it sleep come last.

## Conventions

- Never add behaviour to a transport or a token store that the other implementation would need too. That is how 1.x ended up with two clients that had to be kept in step. If both need it, it belongs in `Client`.
- Never import `Illuminate\`, Carbon or a Laravel helper outside `src/Laravel`. `tests/Unit/ArchitectureTest.php` checks it.
- Never put a key or the token in a URL. Error messages quote URLs, and host apps log them. The client key goes in the form body of the token request, the subscription key and the token in headers. The tests assert on the URL of every request.
- Never add a parameter that carries a key, a token, headers or a body without `#[\SensitiveParameter]`: without it the value is in the stack trace of every exception, and error trackers record those.
- Never build an exception message from a response or from a transport error without `redact()`. A server can echo what it received, and the message is what host apps log and `snelstart:test` prints.
- Never put the response body on the exception as a property. It can hold data of the administration, and a property ends up in error trackers that serialise exceptions. The error codes are a property because they hold no data.
- Never put the token in the cache unencrypted. A cache store is often shared or readable by more people than `.env`, and this token opens a bookkeeping. A value that cannot be decrypted is "no token", never an exception: a rotated `APP_KEY` must not take the integration down.
- Never put the client key in a token key, not even partly. Cache keys show up in `redis-cli keys`, in file names and in monitoring. `TestCase::tokenKey()` pins the format.
- Never let a token store fail a call. `CacheTokenStore` catches everything from the cache and from the lock, logs one warning per instance and works from memory. The lock only saves token requests on a cold cache; it gives up after five seconds and the client goes on without it.
- Never retry more than once, and never anything but a 401 on a token that was not fresh. A 401 on a fresh token is about the subscription key or the rights of the client key, and a loop there hammers the token endpoint with a key for a bookkeeping. A 429 or a 5xx is the host app's decision; a repeated `POST` after a 5xx can book something twice.
- Never send a request without the timeouts. Every transport gets them as arguments and must apply both.
- Never throw anything but `SnelstartException` from `Client`, with the HTTP status as its code and `0` when there was no response. What a transport throws is wrapped, with the original as previous. `ArchitectureTest` checks the `throw` statements.
- Never print or log a key in the command, not even partly, and nothing from the bookkeeping but the name of the administration: command output ends up in deploy logs and CI output.
- Never state in the docs what SnelStart does unless its documentation says so, and then say that it is their documentation. The package passes arrays on and returns what comes back; which fields and filters exist is theirs.
- Never edit a text in `ErrorCodes::MESSAGES`. They are SnelStart's, in Dutch, and `ErrorCodesTest` pins the checksum of the list. When SnelStart changes the page: update the list and the checksum together.
- Never call SnelStart from a test: it is a real bookkeeping. `TestCase` calls `Http::preventStrayRequests()`. Don't put a real key, customer name or administration id in tests, docs or examples.
- Keep the public API compatible within 2.x: the list is in `CONTRIBUTING.md`. `Transport` and `TokenStore` are implemented by host apps, so a new method on either is a breaking change. `Client` is not final, so host apps can mock it; `Resource` is.
- The SnelStart field and endpoint names are Dutch (`relaties`, `artikelen`, `verkooporders`, `naam`) and so are the method names that mirror them (`relaties()`), and the error texts. That is the API, not a language violation; everything around them is English.
