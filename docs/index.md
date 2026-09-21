---
title: Home
nav_order: 1
description: "A PHP client for the SnelStart B2B API v2: client key authentication, relations, articles and sales orders, for Laravel and for plain PHP."
permalink: /
---

# SnelStart for Laravel

`darvis/snelstart` is a client for the SnelStart B2B API v2, the API of the Dutch accounting software SnelStart.

- **Authentication handled**: the client key is exchanged for an access token, and the token is reused until it is about to expire.
- **Two clients, one set of methods**: `Services\SnelstartAPI` for Laravel, `Standalone\SnelstartAPI` for a project without Laravel.
- **Every endpoint**: five convenience methods and `get()`, `post()`, `put()`, `delete()` and `head()` for the rest.
- **Keys stay out of sight**: never in a URL, and removed from error messages before they are thrown, logged or printed.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A SnelStart client key and a subscription key of the B2B API
- The cURL extension, only for the standalone client

## Install

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

## In short

```php
use Darvis\Snelstart\Services\SnelstartAPI;

$snelstart = app(SnelstartAPI::class);

$company = $snelstart->getCompanyInfo();
$relations = $snelstart->getRelaties(['$top' => 50]);
$relation = $snelstart->createRelatie(['naam' => 'Example B.V.']);
$same = $snelstart->get('/relaties/'.$relation['id']);
```

A failed call throws a `SnelstartException`, a `RuntimeException` with the HTTP status in `$e->status()` and the response body in the message.

## Pages

- [Installation](installation.md): the package, the keys and the config file
- [Quick start](quickstart.md): the first calls, in a controller and in a job
- [How it works](concepts.md): authentication, the token lifetime, failures and where the keys go
- [API reference](api-reference.md): every public method, the command and the container bindings
- [Standalone client](standalone.md): the client for a project without Laravel, and how it differs
- [Testing](testing.md): fake SnelStart in the tests of your application
- [Troubleshooting](troubleshooting.md): the messages you can run into
- [FAQ](faq.md)

## Links

- [Source on GitHub](https://github.com/ArvidDeJong/snelstart)
- [Packagist](https://packagist.org/packages/darvis/snelstart)
- [Changelog](https://github.com/ArvidDeJong/snelstart/blob/main/CHANGELOG.md)
- [Report an issue](https://github.com/ArvidDeJong/snelstart/issues)
