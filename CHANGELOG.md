# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/ArvidDeJong/snelstart/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ArvidDeJong/snelstart/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ArvidDeJong/snelstart/releases/tag/v1.0.0
