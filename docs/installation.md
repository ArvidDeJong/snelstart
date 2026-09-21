---
title: "Installation"
nav_order: 2
description: "Install darvis/snelstart in Laravel: the package, the client key and the subscription key in .env, the optional config file, and how to check that it works."
---

# Installation

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A SnelStart client key and a subscription key for the B2B API
- An `APP_KEY` and a cache store that persists, if you want the access token to be cached (a new Laravel application has both)

## 1. Install the package

```bash
composer require darvis/snelstart
```

Laravel registers the service provider by itself (package discovery). There is nothing to add to `bootstrap/providers.php`, and the package has no migrations.

## 2. Get the two keys

The package needs two values, and SnelStart issues both:

| Key | What the package does with it |
| --- | --- |
| Client key | Exchanges it for an access token: it is posted to the token endpoint as `clientkey`, with `grant_type=clientkey`. Required. |
| Subscription key | Sends it in the `Ocp-Apim-Subscription-Key` header of every API call. Without it the header is left out. |

The config file of the package notes where they are found: the client key under the tile "Maatwerk" in SnelStart Web, and the subscription key in the B2B developer portal, where it is usually the primary key. That is a note of the package author, not documentation of SnelStart; how you get access to either is up to SnelStart.

Both keys open a bookkeeping. Keep them in `.env`, never in the repository.

## 3. Put them in .env

```env
SNELSTART_CLIENT_KEY=your-client-key
SNELSTART_SUBSCRIPTION_KEY=your-subscription-key
```

When your application caches its config (`php artisan config:cache`, usual in production), run `php artisan config:clear` or cache it again after changing `.env`. Otherwise the old values stay in use.

That is all you need to start. Continue with [Check that it works](#check-that-it-works).

## 4. Optional: the other settings

| Config key | Environment variable | Default | What it is |
| --- | --- | --- | --- |
| `base_url` | `SNELSTART_BASE_URL` | `https://b2bapi.snelstart.nl/v2` | Where the API calls go. A trailing slash is removed. |
| `client_key` | `SNELSTART_CLIENT_KEY` | none | See above. |
| `connect_timeout` | `SNELSTART_CONNECT_TIMEOUT` | `10` | Seconds to wait for the connection. |
| `subscription_key` | `SNELSTART_SUBSCRIPTION_KEY` | none | See above. |
| `timeout` | `SNELSTART_TIMEOUT` | `30` | Seconds to wait for a whole request, the token request included. |
| `token_cache.enabled` | `SNELSTART_TOKEN_CACHE` | `true` | Keep the access token in Laravel's cache, encrypted with your `APP_KEY`. |
| `token_cache.store` | `SNELSTART_TOKEN_CACHE_STORE` | none | A store from `config/cache.php`. None means the default store. |
| `token_url` | `SNELSTART_TOKEN_URL` | `https://auth.snelstart.nl/b2b/token` | Where the token is fetched. |

A timeout that is not a positive number gives the default. What the token cache does is explained in [How it works](concepts.md).

You only need the config file in your application when an environment variable is not enough:

```bash
php artisan vendor:publish --tag=snelstart-config
```

This writes `config/snelstart.php`. A file you published with an older version keeps working without the newer keys: Laravel merges the package file underneath yours, so the defaults and the environment variables above still apply.

In your own code, read the settings through `Darvis\Snelstart\Support\SnelstartConfig` (`baseUrl()`, `clientKey()`, `connectTimeout()`, `subscriptionKey()`, `timeout()`, `tokenCacheEnabled()`, `tokenCacheStore()`, `tokenUrl()`); the defaults live there.

## Check that it works

```bash
php artisan snelstart:test
```

The command fetches `/companyInfo` with your keys. When everything is right it exits with code 0 and prints this, with the company info of your administration as JSON:

```text
Testing Snelstart API connection...
✓ Connection successful!
Company info retrieved.
{
    "...": "..."
}
```

When something is wrong it exits with code 1 and prints one of these. The part after `Response:` is what SnelStart answered; a key or a token in it is replaced by `[redacted]`.

| You see | What it means |
| --- | --- |
| `✗ Connection failed: Snelstart API config is incomplete (token_url, client_key).` | `SNELSTART_CLIENT_KEY` is empty, or the config is cached with the old value. |
| `✗ Connection failed: Failed to retrieve access_token from Snelstart. HTTP status: 401. Response: ...` (or `400`) | The token endpoint refused the client key. |
| `✗ Connection failed: Snelstart API call failed. HTTP status: 401. Response: ...` | The token was accepted, the API call was not: check the subscription key. |
| `✗ Connection failed: cURL error ...`, for example `cURL error 28` | SnelStart could not be reached (`cURL error 6`, the host name did not resolve) or did not answer within the timeout (`cURL error 28`). |
| `There are no commands defined in the "snelstart" namespace.` | Laravel has not discovered the package: run `php artisan package:discover`. |

Each of these has its cause and its fix on the [Troubleshooting](troubleshooting.md) page. The command never prints a key or the token.

Next: the [Quick start](quickstart.md).
