---
title: "Installation"
nav_order: 2
description: "Install darvis/snelstart in Laravel: the package, the client key and the subscription key in .env, the optional config file, and how to check that it works."
---

# Installation

This page is for a Laravel application. For a project without Laravel see [Without Laravel](standalone.md).

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A SnelStart client key and a subscription key for the B2B API
- An `APP_KEY` and a cache store that persists, if you want the access token to be cached (a new Laravel application has both)

## 1. Install the package

```bash
composer require darvis/snelstart
```

Laravel registers the service provider and the `Snelstart` facade by itself (package discovery). There is nothing to add to `bootstrap/providers.php`, and the package has no migrations.

## 2. Get the two keys

The package needs two values, and SnelStart issues both:

| Key | What the package does with it |
| --- | --- |
| Client key | Exchanges it for an access token: it is posted to the token endpoint as `clientkey`, with `grant_type=clientkey`. It belongs to one administration. Required for every call to the API. |
| Subscription key | Sends it in the `Ocp-Apim-Subscription-Key` header of every call. Without it the header is left out. |

The SnelStart developer portal describes the client key ("koppelsleutel") as managed by the user of an administration in SnelStart Web, and the subscription key as yours, found under Products in the portal. How you get access to either is up to SnelStart.

Both keys open a bookkeeping. Keep them in `.env`, never in the repository.

## 3. Put them in .env

```env
SNELSTART_CLIENT_KEY=your-client-key
SNELSTART_SUBSCRIPTION_KEY=your-subscription-key
```

When your application caches its config (`php artisan config:cache`, usual in production), run `php artisan config:clear` or cache it again after changing `.env`. Otherwise the old values stay in use.

That is all you need to start. Continue with [Check that it works](#check-that-it-works).

Does your application talk to more than one administration? Put one client key in `.env`, or none at all, and pick the key per call with `Snelstart::withClientKey($key)`; see [How it works](concepts.md#more-than-one-administration).

## 4. Optional: the other settings

| Config key | Environment variable | Default | What it is |
| --- | --- | --- | --- |
| `base_url` | `SNELSTART_BASE_URL` | `https://b2bapi.snelstart.nl/v2` | Where the API calls go. A trailing slash is removed. |
| `client_key` | `SNELSTART_CLIENT_KEY` | none | See above. |
| `connect_timeout` | `SNELSTART_CONNECT_TIMEOUT` | `10` | Seconds to wait for the connection. |
| `echo_url` | `SNELSTART_ECHO_URL` | `https://b2bapi.snelstart.nl/echo` | The Echo API, which `snelstart:test` uses to check the subscription key. |
| `subscription_key` | `SNELSTART_SUBSCRIPTION_KEY` | none | See above. |
| `timeout` | `SNELSTART_TIMEOUT` | `30` | Seconds to wait for a whole request, the token request included. |
| `token_cache.enabled` | `SNELSTART_TOKEN_CACHE` | `true` | Keep the access token in Laravel's cache, encrypted with your `APP_KEY`. |
| `token_cache.store` | `SNELSTART_TOKEN_CACHE_STORE` | none | A store from `config/cache.php`. None means the default store. |
| `token_url` | `SNELSTART_TOKEN_URL` | `https://auth.snelstart.nl/b2b/token` | Where the token is fetched. |

A value that is empty, or a timeout that is not a positive number, gives the default. What the token cache does is explained in [How it works](concepts.md).

You only need the config file in your application when an environment variable is not enough:

```bash
php artisan vendor:publish --tag=snelstart-config
```

This writes `config/snelstart.php`. A file you published with an older version keeps working without the newer keys: Laravel merges the package file underneath yours, so the defaults and the environment variables above still apply.

In your own code, read the settings from the client: `Snelstart::config()` gives a `Darvis\Snelstart\Config` with `baseUrl`, `clientKey`, `timeout` and the rest as properties.

## Check that it works

```bash
php artisan snelstart:test
```

The command makes two calls: one to the Echo API, which only needs the subscription key, and one to `/companyInfo`, which needs the client key too. When everything is right it exits with code 0 and prints this, with the name of your administration:

```text
Testing Snelstart API connection...
✓ Subscription key accepted.
✓ Client key accepted.
Connection successful! Administration: Your Company B.V.
```

When something is wrong it exits with code 1 and prints one of these. The part after `Response:` is what SnelStart answered; a key or a token in it is replaced by `[redacted]`.

| You see | What it means |
| --- | --- |
| `✗ Subscription key check failed: Snelstart API call failed. HTTP status: 401. Response: ...` | The Echo API refused the subscription key, or `SNELSTART_SUBSCRIPTION_KEY` is empty. |
| `✗ Client key check failed: Snelstart config is incomplete: the client key is empty.` | `SNELSTART_CLIENT_KEY` is empty, or the config is cached with the old value. |
| `✗ Client key check failed: Failed to retrieve access_token from Snelstart. HTTP status: 400. Response: ...` (or `401`) | The token endpoint refused the client key. |
| `✗ Client key check failed: Snelstart API call failed. HTTP status: 401. Response: ...` (or `403`) | The token was accepted, the call to `/companyInfo` was not. |
| `✗ Subscription key check failed: Snelstart API call failed. No response: ...`, for example with `cURL error 28` | SnelStart could not be reached (`cURL error 6`, the host name did not resolve) or did not answer within the timeout (`cURL error 28`). |
| `There are no commands defined in the "snelstart" namespace.` | Laravel has not discovered the package: run `php artisan package:discover`. |

Each of these has its cause and its fix on the [Troubleshooting](troubleshooting.md) page. The command never prints a key, the token or anything from the bookkeeping but the name of the administration.

Next: the [Quick start](quickstart.md).
