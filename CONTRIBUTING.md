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

The test of the cURL transport starts `php -S` with the PHP that runs the tests. Set `PHP_SERVER_BINARY` to another PHP binary when yours cannot serve.

## Pull requests

- Add or update tests for every change in behaviour. Never call SnelStart from a test: the Laravel test case blocks every request that is not faked, the client is tested with `Testing\FakeTransport`, and the cURL transport against `tests/Fixtures/server.php` on `127.0.0.1`.
- Keep the public API compatible within 2.x:
  - `Darvis\Snelstart\Client`: its constructor, its public methods and what they return, `withClientKey()` and `fromEnv()`;
  - `Resource` (`list()`, `all()`, `find()`, `create()`, `update()`, `delete()`), `Config` (constructor arguments, properties, `fromArray()` with its keys, `fromEnv()` with its variables), `ErrorCodes` and `Testing\FakeTransport`;
  - the interfaces `Http\Transport` and `Token\TokenStore`: host apps implement them, so a new method is a breaking change;
  - `Exceptions\SnelstartException`: it stays a `RuntimeException`, with `status()`, `errorCodes()`, `hasErrorCode()` and `errors()`, the HTTP status (or `0` without a response) as its code, and the texts `Snelstart API call failed. HTTP status: …`, `Snelstart API call failed. No response: …` and `Failed to retrieve access_token from Snelstart. …`;
  - in Laravel: the container bindings and the alias `snelstart`, the `Snelstart` facade, the `snelstart:test` command name and its exit codes, the `snelstart-config` publish tag, the config keys (`base_url`, `client_key`, `connect_timeout`, `echo_url`, `subscription_key`, `timeout`, `token_cache.enabled`, `token_cache.store`, `token_url`) and the environment variables that go with them;
  - the defaults a site owner relies on: the token cache is on, 30 seconds and 10 to connect, pages of 500, one repeat after a 401 on a token the client already had, no retry for anything else;
  - the format of the token key (`snelstart.token.<sha256>`), so an upgrade does not drop cached tokens by accident.
- Everything outside `src/Laravel` is plain PHP: no `Illuminate\`, no Carbon, no Laravel helpers. `tests/Unit/ArchitectureTest.php` checks it.
- Behaviour lives in `Client`, once. A transport only carries a request and a token store only keeps a token; neither decides anything.
- The client throws `SnelstartException` only.
- A key or a token never goes into a URL, and every message built from a response goes through `redact()`. The token only goes into the cache encrypted, under a key that holds a hash of the client key, never the client key.
- Read the Laravel config through `Laravel\SnelstartConfig`, never with `config('snelstart.…')` elsewhere. Defaults live in `Config`.
- `ErrorCodes::MESSAGES` is the list SnelStart publishes, texts unchanged and in Dutch. `tests/Unit/ErrorCodesTest.php` pins its checksum; update both when SnelStart changes the list.
- Write code, comments and messages in English.
- Update `docs/`, `CHANGELOG.md` (under `Unreleased`) and `resources/boost/` when users will notice the change.
- The documentation in `docs/` is also the website. Don't write `{{ }}` or `{% %}` there outside a raw block; Jekyll would render it.

## Code of conduct

This project follows the [Contributor Covenant](https://github.com/ArvidDeJong/.github/blob/main/CODE_OF_CONDUCT.md).
