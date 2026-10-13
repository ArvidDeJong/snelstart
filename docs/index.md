---
title: "Home"
nav_order: 1
description: "darvis/snelstart is a PHP client for the SnelStart B2B API v2: token handling, every collection with paging, error codes, and a Laravel integration."
permalink: /
---

# SnelStart for PHP and Laravel

`darvis/snelstart` is a client for the SnelStart B2B API v2, the API of the Dutch accounting software SnelStart. It exchanges your client key for an access token, keeps that token, and sends your calls with the right headers.

The client is plain PHP and works in any project. In a Laravel application the package adds a service provider, a facade, an encrypted token cache and a connection test on top of the same client.

## What it does

- **Authentication.** The client key is exchanged for an access token, which is kept until sixty seconds before it expires. When SnelStart refuses a token the client already had, it fetches a new one and repeats the call once.
- **Every collection of the API**: `relaties()`, `artikelen()`, `verkooporders()`, `verkoopfacturen()` and twenty one more, each with `list()`, `all()`, `find()`, `create()`, `update()` and `delete()`.
- **Paging done for you**: `all()` walks through a collection page by page while you loop.
- **Every other endpoint** through `get()`, `post()`, `put()` and `delete()`.
- **Failures you can act on**: one `SnelstartException` with the HTTP status in `$e->status()` and the SnelStart error codes, such as `REL-0007`, in `$e->errorCodes()`.
- **More than one administration**: `withClientKey()` gives the same client for another client key.
- **Keys stay out of sight**: never in a URL, and removed from error messages before they are thrown, logged or printed.
- **In Laravel**: the `Snelstart` facade, a token cache encrypted with your application key, `Http::fake()` in your tests, and `php artisan snelstart:test`.

## What it does not do

- No Eloquent models, no migrations and no database tables. You get arrays back and store what you need yourself.
- No synchronisation, no scheduler and no queue jobs. When and how often you call SnelStart is up to your application.
- No webhooks and no routes.
- No validation and no mapping of fields. The arrays you pass go to SnelStart as they are, and the decoded response comes back as it is; which fields an endpoint takes is in the SnelStart API documentation.
- No retry for a 429 or a 5xx.

## Requirements

- PHP 8.2+
- A SnelStart client key and a subscription key for the B2B API, both issued by SnelStart
- For the Laravel integration: Laravel 11, 12 or 13
- Without Laravel: the cURL extension

## Install

```bash
composer require darvis/snelstart
```

In Laravel:

```env
SNELSTART_CLIENT_KEY=your-client-key
SNELSTART_SUBSCRIPTION_KEY=your-subscription-key
```

```bash
php artisan snelstart:test
```

```php
use Darvis\Snelstart\Laravel\Facades\Snelstart;

foreach (Snelstart::relaties()->all() as $relatie) {
    // every relation of the administration, fetched 500 at a time
}
```

## Pages

- [Installation](installation.md): the steps from `composer require` to a connection that works, and how to check it
- [Quick start](quickstart.md): one complete example, a command that copies the customers of an administration
- [How it works](concepts.md): authentication, where the token is kept, the one repeat after a 401, paging, timeouts and where the keys go
- [API reference](api-reference.md): every public class and method, the exception, the command and the container bindings
- [Without Laravel](standalone.md): the same client in a plain PHP project
- [Testing](testing.md): fake SnelStart in the tests of your application
- [Troubleshooting](troubleshooting.md): every message the package gives, with its cause and its fix
- [Upgrading](upgrading.md): what changed between 1.x and 2.0, old call by old call
- [FAQ](faq.md): short answers to the questions people ask first

## Links

- [Source on GitHub](https://github.com/ArvidDeJong/snelstart)
- [Packagist](https://packagist.org/packages/darvis/snelstart)
- [Changelog](https://github.com/ArvidDeJong/snelstart/blob/main/CHANGELOG.md)
- [Report an issue](https://github.com/ArvidDeJong/snelstart/issues)
