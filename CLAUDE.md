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
- The token lives on the instance, until sixty seconds before `expires_in`. It is not in the cache. A 401 does not trigger a new token. Both are documented behaviour; changing either is a design decision, not a bug fix.
- [EchoService](src/Services/EchoService.php) wraps `/echo/resource` and never throws; it logs and returns `success => false`.
- [TestSnelstartConnection](src/Console/Commands/TestSnelstartConnection.php) resolves the client inside its `try`. Don't inject it into `handle()`: the constructor of the client throws without a client key, and an injected argument is resolved before the `try`, so the user gets a stack trace instead of `Connection failed`.
- The standalone client cannot be faked with `Http::fake()`. `tests/Unit/StandaloneSnelstartApiTest.php` starts [tests/Fixtures/standalone-server.php](tests/Fixtures/standalone-server.php) on `127.0.0.1` with a free port; the path of a request picks the answer, and every request is written to a log file the test reads. For `HEAD` that server keeps the connection open, which is what caught the missing `CURLOPT_NOBODY`.

## Conventions

- Never put a key or the token in a URL. An HTTP exception quotes the URL, and host apps log exception messages. The client key goes in the form body of the token request, the subscription key and the token in headers. The tests assert on the URL of every request.
- Never build an exception message from a response without `redactSecrets()`. The message contains the response body, a server can echo what it received, and the message is what `EchoService` logs and `snelstart:test` prints. Both clients have their own copy of `redactSecrets()`; keep them the same.
- Never print or log a key in the command, not even partly ("the first four characters"): command output ends up in deploy logs and CI output.
- Keep the public API compatible within 1.x: the list is in `CONTRIBUTING.md`. It includes the protected methods and properties, because neither client is final, and the wording of the exception messages and log lines, because host apps match on them. Don't merge, rename or remove either client.
- Failures throw a plain `RuntimeException` with code `0`. Host apps catch `RuntimeException`; don't replace it with a class that does not extend it within 1.x.
- Never call SnelStart from a test: it is a real bookkeeping. `TestCase` calls `Http::preventStrayRequests()`; use `fakeSnelstart()` or your own `Http::fake()`. Don't put a real key, customer name or administration id in tests, docs or examples.
- The SnelStart field and endpoint names are Dutch (`relaties`, `artikelen`, `verkooporders`, `naam`) and so are the method names that mirror them (`getRelaties()`). That is the API, not a language violation; everything around them is English.
