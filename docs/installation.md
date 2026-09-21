---
title: Installation
nav_order: 2
description: "Install darvis/snelstart, set the client key and the subscription key, publish the config file and check the connection."
---

# Installation

## The package

```bash
composer require darvis/snelstart
```

Laravel discovers the service provider by itself.

## The keys

You need two keys:

| Key | Where it comes from | How it is sent |
| --- | --- | --- |
| Client key | SnelStart Web, the "Maatwerk" tile of the administration | In the form body of the token request |
| Subscription key | The SnelStart B2B developer portal (usually the primary key) | In the `Ocp-Apim-Subscription-Key` header of every API call |

```env
SNELSTART_CLIENT_KEY=your-client-key
SNELSTART_SUBSCRIPTION_KEY=your-subscription-key
```

The client key is required: without it the client throws `Snelstart API config is incomplete (token_url, client_key).` the moment it is built. The subscription key is optional for the package; without it the header is left out.

Both keys give access to a bookkeeping. Keep them in `.env`, never in the repository.

## The config file

Publishing is only needed when you want to change it:

```bash
php artisan vendor:publish --tag=snelstart-config
```

| Config key | Environment variable | Default |
| --- | --- | --- |
| `base_url` | `SNELSTART_BASE_URL` | `https://b2bapi.snelstart.nl/v2` |
| `client_key` | `SNELSTART_CLIENT_KEY` | none |
| `subscription_key` | `SNELSTART_SUBSCRIPTION_KEY` | none |
| `token_url` | `SNELSTART_TOKEN_URL` | `https://auth.snelstart.nl/b2b/token` |

A trailing slash on the base URL is removed.

In your own code, read the settings through `Darvis\Snelstart\Support\SnelstartConfig` (`baseUrl()`, `clientKey()`, `subscriptionKey()`, `tokenUrl()`); the defaults live there.

## Check the connection

```bash
php artisan snelstart:test
```

The command fetches `/companyInfo`. On success it prints `Connection successful!` and the company info as JSON, and exits with 0. On a failure it prints `Connection failed:` with the reason and exits with 1. It never prints a key or the token.
