---
title: "Home"
nav_order: 1
description: "darvis/snelstart is a PHP client for the SnelStart B2B API v2: it fetches and caches the access token and calls relations, articles, orders and any endpoint."
permalink: /
---

# SnelStart for Laravel

`darvis/snelstart` is a client for the SnelStart B2B API v2, the API of the Dutch accounting software SnelStart. It exchanges your client key for an access token, keeps that token, and sends your calls with the right headers.

It is for a Laravel developer who has to read from or write to a SnelStart administration, and for a PHP project without Laravel through a second, standalone client.

## What it does

- **Authentication.** The client key is exchanged for an access token. The Laravel client keeps the token encrypted in the cache until sixty seconds before it expires, and fetches a new one once when SnelStart refuses it.
- **Five ready made calls**: `getCompanyInfo()`, `getRelaties()`, `createRelatie()`, `getArtikelen()` and `createVerkooporder()`.
- **Every other endpoint** through `get()`, `post()`, `put()`, `delete()` and `head()`.
- **Failures you can act on**: a `SnelstartException` with the HTTP status in `$e->status()`.
- **Keys stay out of sight**: never in a URL, and removed from error messages before they are thrown, logged or printed.
- **A connection test**: `php artisan snelstart:test`.

## What it does not do

- No Eloquent models, no migrations and no database tables. You get arrays back and store what you need yourself.
- No synchronisation, no scheduler and no queue jobs. When and how often you call SnelStart is up to your application.
- No webhooks and no routes.
- No validation and no mapping of fields. The arrays you pass go to SnelStart as they are, and the decoded response comes back as it is; which fields an endpoint takes is in the SnelStart API documentation.
- No paging, and no retry for a 429 or a 5xx.
- No facade.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A SnelStart client key and a subscription key for the B2B API, both issued by SnelStart
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

## Pages

- [Installation](installation.md): the steps from `composer require` to a connection that works, and how to check it
- [Quick start](quickstart.md): one complete example, a controller that reads from SnelStart and handles a failure
- [How it works](concepts.md): authentication, where the token is kept, the one repeat after a 401, timeouts and where the keys go
- [API reference](api-reference.md): every public method, the exception, the command and the container bindings
- [Standalone client](standalone.md): the client for a project without Laravel, and how it differs
- [Testing](testing.md): fake SnelStart in the tests of your application
- [Troubleshooting](troubleshooting.md): every message the package gives, with its cause and its fix
- [FAQ](faq.md): short answers to the questions people ask first

## Links

- [Source on GitHub](https://github.com/ArvidDeJong/snelstart)
- [Packagist](https://packagist.org/packages/darvis/snelstart)
- [Changelog](https://github.com/ArvidDeJong/snelstart/blob/main/CHANGELOG.md)
- [Report an issue](https://github.com/ArvidDeJong/snelstart/issues)
