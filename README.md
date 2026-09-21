# SnelStart for Laravel

[![Latest version](https://img.shields.io/packagist/v/darvis/snelstart.svg)](https://packagist.org/packages/darvis/snelstart)
[![Tests](https://github.com/ArvidDeJong/snelstart/actions/workflows/tests.yml/badge.svg)](https://github.com/ArvidDeJong/snelstart/actions/workflows/tests.yml)
[![PHP version](https://img.shields.io/packagist/dependency-v/darvis/snelstart/php.svg)](https://packagist.org/packages/darvis/snelstart)
[![License](https://img.shields.io/packagist/l/darvis/snelstart.svg)](LICENSE)

A PHP client for the SnelStart B2B API v2, the API of the Dutch accounting software SnelStart. It exchanges your client key for an access token, keeps that token, and sends your calls with the right headers. It has no models, no synchronisation and no webhooks: you call an endpoint and get the decoded response back.

## Features

- **Authentication handled** - the client key is exchanged for an access token, kept encrypted in Laravel's cache until sixty seconds before it expires
- **One new token after a 401** - when SnelStart refuses a token the client already had, it fetches a new one and repeats the call once
- **Relations, articles and sales orders** - and `get()`, `post()`, `put()`, `delete()` and `head()` for every other endpoint
- **Failures with a status** - a `SnelstartException` with the HTTP status in `$e->status()`, and a timeout of 30 seconds
- **Safe with your keys** - never in a URL, and replaced by `[redacted]` in error messages, log lines and command output
- **A connection test** - `php artisan snelstart:test`
- **A standalone client** - the same methods on cURL, for a project without Laravel
- **Laravel Boost** - guideline and skill included, so an AI assistant in your app knows the API

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A SnelStart client key and a subscription key for the B2B API, both issued by SnelStart
- The cURL extension, only for the standalone client

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

The command prints `✓ Connection successful!` and the company info of your administration, or `✗ Connection failed:` with the reason.

## Quick start

```php
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Http\Client\ConnectionException;

try {
    $snelstart = app(SnelstartAPI::class);

    $relations = $snelstart->getRelaties();
    $relation = $snelstart->createRelatie(['naam' => 'Example B.V.']);
    $other = $snelstart->get('/relaties/'.$id);   // any other endpoint
} catch (ConnectionException $e) {
    // SnelStart could not be reached, or did not answer within the timeout.
} catch (SnelstartException $e) {
    $status = $e->status();   // 401, 404, 429 ..., or 0 when there was no response
}
```

The arrays you pass go to SnelStart unchanged, and the decoded response comes back unchanged. A 429 or a 5xx is not retried.

## Documentation

The full documentation lives on the [documentation site](https://arviddejong.github.io/snelstart/):

- [Installation](https://arviddejong.github.io/snelstart/installation.html): the steps, the settings and how to check that it works
- [Quick start](https://arviddejong.github.io/snelstart/quickstart.html): one complete example, a route and a controller
- [How it works](https://arviddejong.github.io/snelstart/concepts.html): the token cache, the repeat after a 401, timeouts and where the keys go
- [API reference](https://arviddejong.github.io/snelstart/api-reference.html): every public method and the exception
- [Standalone client](https://arviddejong.github.io/snelstart/standalone.html): without Laravel, and how it differs
- [Testing](https://arviddejong.github.io/snelstart/testing.html): fake SnelStart in your tests
- [Troubleshooting](https://arviddejong.github.io/snelstart/troubleshooting.html): every message with its cause and its fix
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

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md).

## Security

Please report a vulnerability privately, as described in [SECURITY](SECURITY.md), not in the issue tracker.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
