# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-09-21

### Added
- `Darvis\Snelstart\Exceptions\SnelstartException`, which both clients now throw. It extends
  `RuntimeException` and has the same messages, so every `catch (\RuntimeException $e)` and every match
  on the text keeps working. `$e->status()` gives the HTTP status of the response that caused it, so
  you no longer have to take it out of the message: retry a 429, give up on a 404. It is `0` when
  there was no response: an incomplete config, or a cURL error in the standalone client. The response
  body is not a property of the exception; it is where it always was, in the message, with the keys
  redacted.
- **A lock around the token request** in the Laravel client, when the token cache is on. Requests that
  find the cache empty at the same moment, after a deploy or a `cache:clear`, used to fetch a token
  each. One now fetches it; the others wait at most five seconds and use that token. Nothing to do and
  nothing to configure. A cache store without locks, a lock that does not come free in time or a lock
  that fails means the client fetches a token without the lock; the lock never fails a call. The
  standalone client has no cache and so no lock.

### Changed
- **`getCode()` of the exceptions is the HTTP status instead of `0`**, and so is `error_code` in the
  result of `EchoService`: `401`, `429`, `503`. It stays `0` when there was no response, such as a
  timeout. If you compared either with `0` to mean "a SnelStart failure", compare with
  `$result['success']` or catch `SnelstartException` instead. Error trackers that group by exception
  class will start a new group for `SnelstartException`.
- `Illuminate\Http\Client\ConnectionException` from the Laravel client is left exactly as it was: it
  is not a `SnelstartException`. Keep catching it separately.

## [1.2.0] - 2026-09-21

### Added
- **The access token is kept in Laravel's cache**, encrypted with the application key, until sixty
  seconds before it expires. A web request no longer fetches its own token; it used to make one extra
  request to SnelStart for every page that called the API. Nothing to do. The cache key holds a hash
  of the token URL and the client key, so two administrations in one application never share a token,
  and the client key is not readable in it. A cached value that cannot be decrypted (after a rotated
  `APP_KEY`) counts as no token, and `php artisan cache:clear` only makes the next call fetch a new
  one. When the cache store is down or the application has no `APP_KEY`, the client logs one warning
  and works from memory. New config keys `token_cache.enabled` (`SNELSTART_TOKEN_CACHE`, default
  `true`) and `token_cache.store` (`SNELSTART_TOKEN_CACHE_STORE`, default the default store). Set
  `SNELSTART_TOKEN_CACHE=false` to get the behaviour of 1.1.0 back: in memory, on the instance, only.
- `forgetToken()` on both clients drops the access token on purpose, in memory and in the cache.
- `timeout` and `connect_timeout` config keys (`SNELSTART_TIMEOUT`, `SNELSTART_CONNECT_TIMEOUT`), in
  seconds, for the Laravel client, and the same two keys in the config array of the standalone client
  and in `fromEnv()`. A value that is not a positive number gives the default. A config file you
  published earlier does not need the new keys; the package file is merged underneath it.
- `SnelstartConfig::timeout()`, `connectTimeout()`, `tokenCacheEnabled()` and `tokenCacheStore()`.

### Changed
- **A 401 on a token the client already had is answered with a new token and one repeat of the call**,
  in both clients. SnelStart can drop a token before it expires; up to 1.1.0 every call in a queue
  worker then failed until the token ran out or the worker was restarted. A second 401 throws as
  before, a 401 on a token that was fetched for that same call is not repeated, a 401 of the token
  endpoint is never repeated, and a 429 or a 5xx is still not retried. If your tests fake a 401 after
  an earlier successful call, that fake now sees the call twice, with a token request in between.
- **The standalone client now has a timeout: 30 seconds for a request and 10 to connect**, the same
  as the Laravel client, for the token request too. It had none, so a call could hang for as long as
  the server kept the connection open. A call that really needs longer than 30 seconds now fails with
  `cURL error: Operation timed out ...`; raise it with `'timeout' => 120` in the config array, or
  `SNELSTART_TIMEOUT=120` for `fromEnv()`.
- The Laravel client sets its timeouts itself instead of relying on the defaults of Laravel's HTTP
  client. The numbers are the same, 30 and 10 seconds, so nothing changes until you set
  `SNELSTART_TIMEOUT` or `SNELSTART_CONNECT_TIMEOUT`.
- `illuminate/cache`, `illuminate/contracts` and `illuminate/encryption` are now declared
  dependencies. In a Laravel application they are already there.
- If your test suite runs on a cache store that persists (file, Redis, database), a token from one
  test is reused in the next, so the token endpoint fake is called less often. Laravel's default
  `CACHE_STORE=array` in `phpunit.xml` is not affected; otherwise set `SNELSTART_TOKEN_CACHE=false`
  there or call `Cache::flush()` before each test.

## [1.1.0] - 2026-09-21

### Added
- `Darvis\Snelstart\Support\SnelstartConfig`, the one place that reads the package config, with
  `baseUrl()`, `clientKey()`, `subscriptionKey()` and `tokenUrl()`. A test fails the build on a direct
  `config('snelstart.…')` read.
- A test suite, the first one: the token request, token reuse and expiry, every public method of both
  clients, the echo service, the `snelstart:test` command, the service provider and the failure paths
  (401, 429, 5xx, timeouts, malformed JSON). The standalone client is tested against a small server
  on `127.0.0.1`. Nothing calls SnelStart.
- A documentation site at https://arviddejong.github.io/snelstart/ with an FAQ and an `llms.txt`, and
  a Laravel Boost guideline and skill in `resources/boost/`.
- The tooling of the other darvis packages: Pint, Larastan level 8, the `test`, `lint`, `format` and
  `analyse` composer scripts, CI on PHP 8.2 to 8.4 with Laravel 11, 12 and 13, issue forms,
  `CONTRIBUTING.md`, `SECURITY.md`, a code of conduct and a `.gitattributes` that keeps development
  files out of the dist archive.

### Fixed
- **`head()` of the standalone client waited for a body that never comes.** A `HEAD` response
  announces a `Content-Length` but has no body; cURL kept waiting until the server closed the
  connection and then threw `cURL error: transfer closed …`. The call now returns `[]` right away.
- **A response that is valid JSON but not an object or a list crashed the Laravel client.** A body
  such as `"ok"` or `42` gave a `TypeError`, which `EchoService` does not catch either. It now returns
  `[]`, as the standalone client already did.
- **`php artisan snelstart:test` crashed with a stack trace when `SNELSTART_CLIENT_KEY` was missing.**
  The client was injected into the command, so its constructor threw before the command could catch
  anything. It now prints `Connection failed: Snelstart API config is incomplete (token_url,
  client_key).` and exits with 1.
- The service provider imported a `Snelstart` facade that does not exist. The package has no facade;
  resolve `Darvis\Snelstart\Services\SnelstartAPI` or the `snelstart` alias.

### Security
- The client key, the subscription key and the access token are replaced by `[redacted]` in the
  message of every exception both clients build from a response. That message contains the response
  body, a server can echo what it was sent (the token endpoint the client key, a gateway the request
  headers), and the message is what `EchoService` writes to the log and what `snelstart:test` prints.
  No such echo is known from SnelStart itself; if your logs are shared or shipped to an external
  service, search them for your keys and rotate a key you find.

### Changed
- **Requirements are now declared.** `composer.json` had no `require` section at all. It now asks for
  PHP `^8.2`, `illuminate/console`, `illuminate/http` and `illuminate/support` `^11.0|^12.0|^13.0` and
  `nesbot/carbon`. Composer refuses the update on PHP below 8.2 or Laravel 10 and lower; stay on 1.0.0
  there. A project that only uses the standalone client now gets the `illuminate/*` packages installed
  as well; `ext-curl` is a suggestion, because only the standalone client needs it.
- A subscription key that is set to an empty string counts as not set. The header was already left
  out in that case, so no request changes.
- The keys in `config/snelstart.php` are in alphabetical order. No key was renamed or removed; a
  published config file keeps working.
- `TestSnelstartConnection::handle()` no longer takes the client as an argument. Only a subclass that
  overrides `handle()` notices.
- The README is shorter and links to the documentation site. `project.md`, a working note, is removed.
  `LICENSE.md` is now `LICENSE`.

## [1.0.0] - 2024-12-09

### Added

- **SnelstartAPI Service** - Main API client for SnelStart B2B-Api v2
  - OAuth token authentication with automatic refresh
  - Company info retrieval (`getCompanyInfo`)
  - Relations management (`getRelaties`, `createRelatie`)
  - Articles retrieval (`getArtikelen`)
  - Sales orders (`createVerkooporder`)
  - Generic HTTP methods (`get`, `post`, `put`, `delete`, `head`)

- **EchoService** - Test service for API connection verification
  - GET, HEAD, and POST requests to echo endpoint
  - Response time measurement
  - Detailed success/error responses

- **Standalone Support** - Use without Laravel
  - `Darvis\Snelstart\Standalone\SnelstartAPI` class
  - Native PHP cURL implementation
  - Configuration via array or environment variables
  - `fromEnv()` factory method

- **Laravel Integration**
  - Auto-discovery ServiceProvider
  - Publishable configuration file
  - Dependency injection support
  - Container bindings (`snelstart`, `snelstart.echo`)

- **Artisan Command**
  - `snelstart:test` - Test API connection

### Configuration

- `SNELSTART_BASE_URL` - API base URL
- `SNELSTART_TOKEN_URL` - Authentication endpoint
- `SNELSTART_CLIENT_KEY` - Custom client key
- `SNELSTART_SUBSCRIPTION_KEY` - B2B portal subscription key

[Unreleased]: https://github.com/ArvidDeJong/snelstart/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/ArvidDeJong/snelstart/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/ArvidDeJong/snelstart/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/ArvidDeJong/snelstart/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ArvidDeJong/snelstart/releases/tag/v1.0.0
