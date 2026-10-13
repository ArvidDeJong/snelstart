# SnelStart for PHP and Laravel

[![Latest version](https://img.shields.io/packagist/v/darvis/snelstart.svg)](https://packagist.org/packages/darvis/snelstart)
[![Tests](https://github.com/ArvidDeJong/snelstart/actions/workflows/tests.yml/badge.svg)](https://github.com/ArvidDeJong/snelstart/actions/workflows/tests.yml)
[![PHP version](https://img.shields.io/packagist/dependency-v/darvis/snelstart/php.svg)](https://packagist.org/packages/darvis/snelstart)
[![License](https://img.shields.io/packagist/l/darvis/snelstart.svg)](LICENSE)

A PHP client for the SnelStart B2B API v2, the API of the Dutch accounting software SnelStart. It exchanges your client key for an access token, keeps that token, and reads and writes every collection of the API. The client is plain PHP; in Laravel it comes with a facade, an encrypted token cache and a connection test. It has no models, no synchronisation and no webhooks: you call an endpoint and get the decoded response back.

## Features

- **Authentication handled** - the client key is exchanged for an access token, kept until sixty seconds before it expires
- **Every collection** - `relaties()`, `artikelen()`, `verkooporders()`, `verkoopfacturen()` and twenty one more, each with `list()`, `all()`, `find()`, `create()`, `update()` and `delete()`
- **Paging done for you** - `all()` walks through a collection 500 rows at a time while you loop
- **Every other endpoint** - `get()`, `post()`, `put()` and `delete()`
- **Failures with a status and error codes** - one `SnelstartException` with `status()`, and the SnelStart error codes (`REL-0007`) with their meaning in `errors()`
- **One new token after a 401** - when SnelStart refuses a token the client already had, it fetches a new one and repeats the call once
- **More than one administration** - `withClientKey()` gives the same client for another client key
- **Safe with your keys** - never in a URL, and replaced by `[redacted]` in error messages and command output
- **Laravel** - the `Snelstart` facade, a token cache encrypted with your application key, `Http::fake()` in your tests and `php artisan snelstart:test`
- **Plain PHP** - no dependencies; cURL by default, or your own transport and token store
- **Laravel Boost** - guideline and skill included, so an AI assistant in your app knows the API

## Requirements

- PHP 8.2+
- A SnelStart client key and a subscription key for the B2B API, both issued by SnelStart
- For the Laravel integration: Laravel 11, 12 or 13
- Without Laravel: the cURL extension

## Installation

```bash
composer require darvis/snelstart
```

```env
SNELSTART_CLIENT_KEY=your-client-key
SNELSTART_SUBSCRIPTION_KEY=your-subscription-key
```

```bash
php artisan snelstart:test
```

The command checks both keys and prints `Connection successful!` with the name of your administration, or which of the two keys was refused and why.

## Quick start

In Laravel:

```php
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Laravel\Facades\Snelstart;

try {
    foreach (Snelstart::relaties()->all() as $relatie) {
        // every relation, fetched 500 at a time
    }

    $relatie = Snelstart::relaties()->create(['relatiesoort' => ['Klant'], 'naam' => 'Example B.V.']);
    $fields = Snelstart::get("relaties/{$relatie['id']}/customFields");   // any other endpoint
} catch (SnelstartException $e) {
    $e->status();   // 400, 401, 404, 429 ..., or 0 when there was no response
    $e->errors();   // ['REL-0007' => 'Naam moet gevuld zijn']
}
```

Without Laravel:

```php
use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;

$snelstart = new Client(new Config($clientKey, $subscriptionKey));

$invoices = $snelstart->verkoopfacturen()->list(['$top' => 10]);
```

The arrays you pass go to SnelStart unchanged, and the decoded response comes back unchanged. A 429 or a 5xx is not retried.

## Documentation

The full documentation lives on the [documentation site](https://arviddejong.github.io/snelstart/):

- [Installation](https://arviddejong.github.io/snelstart/installation.html): the steps, the settings and how to check that it works
- [Quick start](https://arviddejong.github.io/snelstart/quickstart.html): one complete example, a command that reads and creates customers
- [How it works](https://arviddejong.github.io/snelstart/concepts.html): the token, the repeat after a 401, paging, error codes, timeouts and where the keys go
- [API reference](https://arviddejong.github.io/snelstart/api-reference.html): every public class and method, and every collection
- [Without Laravel](https://arviddejong.github.io/snelstart/standalone.html): the same client in a plain PHP project
- [Testing](https://arviddejong.github.io/snelstart/testing.html): fake SnelStart in your tests
- [Troubleshooting](https://arviddejong.github.io/snelstart/troubleshooting.html): every message with its cause and its fix
- [Upgrading](https://arviddejong.github.io/snelstart/upgrading.html): from 1.x to 2.0
- [FAQ](https://arviddejong.github.io/snelstart/faq.html): short answers

## Laravel Boost

This package ships a guideline and a skill for [Laravel Boost](https://github.com/laravel/boost). Run `php artisan boost:install`, or `php artisan boost:update --discover` in a project that already uses Boost.

## Testing

```bash
composer test      # Pest
composer lint      # Pint, check only; composer format fixes
composer analyse   # Larastan
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Support the package

If darvis/snelstart saves you time, buy me a beer 🍺: [sponsor me on GitHub](https://github.com/sponsors/ArvidDeJong).

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md).

## Security

Please report a vulnerability privately, as described in [SECURITY](SECURITY.md), not in the issue tracker.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
