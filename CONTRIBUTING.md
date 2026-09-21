# Contributing

Contributions are welcome: bug reports, fixes, documentation and ideas.

## Before you start

- **Bugs:** open an [issue](https://github.com/ArvidDeJong/snelstart/issues/new/choose) with the steps to reproduce and the exception message. Leave your keys, the token and data from a real administration out.
- **Features:** open an issue first. This package stays small on purpose, so let's agree a feature fits before you build it.
- **Security issues:** don't open an issue; see [SECURITY.md](SECURITY.md).

## Development

```bash
git clone https://github.com/ArvidDeJong/snelstart.git
cd snelstart
composer install

composer test      # Pest
composer lint      # Pint, check only (composer format fixes)
composer analyse   # Larastan, level 8
```

CI runs the tests on PHP 8.2 to 8.4 with Laravel 11, 12 and 13, on the lowest and the latest dependencies.

## Pull requests

- Add or update tests for every change in behaviour. Never call SnelStart from a test: the test case blocks every request that is not faked, and the standalone client is tested against `tests/Fixtures/standalone-server.php` on `127.0.0.1`.
- Keep the public API compatible within 1.x:
  - both clients, `Darvis\Snelstart\Services\SnelstartAPI` and `Darvis\Snelstart\Standalone\SnelstartAPI`: their public methods (`forgetToken()` included) and array return values, the constructor of the standalone client with its array keys (`base_url`, `token_url`, `client_key`, `subscription_key`, `timeout`, `connect_timeout`) and `fromEnv()`, the protected methods and properties (neither class is final), and that a failed call throws a `RuntimeException` with the texts `Snelstart API call failed. HTTP status: …` and `Failed to retrieve access_token from Snelstart. …`;
  - `EchoService`: its three methods, the keys of the arrays they return, that it never throws, and the wording of its log lines (`Snelstart Echo Resource GET failed: …`);
  - the container bindings and the aliases `snelstart` and `snelstart.echo`, the `snelstart:test` command name and its exit codes, the `snelstart-config` publish tag, the config keys (`base_url`, `client_key`, `connect_timeout`, `subscription_key`, `timeout`, `token_cache.enabled`, `token_cache.store`, `token_url`) and the environment variables (`SNELSTART_BASE_URL`, `SNELSTART_CLIENT_KEY`, `SNELSTART_CONNECT_TIMEOUT`, `SNELSTART_SUBSCRIPTION_KEY`, `SNELSTART_TIMEOUT`, `SNELSTART_TOKEN_CACHE`, `SNELSTART_TOKEN_CACHE_STORE`, `SNELSTART_TOKEN_URL`);
  - the defaults a site owner relies on: the token cache is on, 30 seconds and 10 to connect, one repeat after a 401 on a token the client already had, no retry for anything else;
  - the methods of `Support\SnelstartConfig`.
- A change to one client goes into the other one too. They are two implementations of the same behaviour, and the differences that exist are listed in `docs/standalone.md`.
- A key or a token never goes into a URL, and every message built from a response goes through `redactSecrets()`. The token only goes into the cache encrypted, under a key that holds a hash of the client key, never the client key.
- Read settings through `Support\SnelstartConfig`, never with `config('snelstart.…')`.
- Write code, comments and messages in English.
- Update `docs/`, `CHANGELOG.md` (under `Unreleased`) and `resources/boost/` when users will notice the change.
- The documentation in `docs/` is also the website. Don't write `{{ }}` or `{% %}` there outside a raw block; Jekyll would render it.

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
