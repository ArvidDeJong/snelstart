---
title: "Troubleshooting"
nav_order: 8
description: "Every message of darvis/snelstart with its cause and its fix: refused keys, an empty client key, a 401, SnelStart error codes, timeouts and cache warnings."
---

# Troubleshooting

Start with `php artisan snelstart:test`. It prints `Testing Snelstart API connection...`, checks the two keys one after the other, and ends with `Connection successful!` or with one of the messages below.

In every message, the part after `Response:` is what SnelStart answered. A key or a token in it is replaced by `[redacted]`.

## Subscription key check failed

**Message.** `✗ Subscription key check failed: Snelstart API call failed. HTTP status: 401. Response: ...`

**Cause.** The Echo API did not accept the subscription key. That call uses no client key and no token, so this is about `SNELSTART_SUBSCRIPTION_KEY` only: it is empty, wrong, or the config is cached with an old value.

**Fix.** Check the key in the SnelStart developer portal and in `.env`, then run `php artisan config:clear`.

## Snelstart config is incomplete: the client key is empty.

**Message.** `Snelstart config is incomplete: the client key is empty.` In the command: `✗ Client key check failed: Snelstart config is incomplete: the client key is empty.`

**Cause.** A call needed a token and there is no client key to fetch one with. Nothing was sent.

**Fix.** Set `SNELSTART_CLIENT_KEY` in `.env` and run `php artisan config:clear`. When your application picks the key per administration with `withClientKey()`, the key you passed was empty.

## Failed to retrieve access_token from Snelstart.

**Message.** `Failed to retrieve access_token from Snelstart. HTTP status: 400. Response: ...` (or another status)

**Cause.** The token endpoint did not accept the client key. `[redacted]` in the message is where the server echoed your key.

**Fix.** Compare the key with the one in SnelStart Web: the whole key, without spaces or quotes around it. A 5xx here is on the side of SnelStart; try again later.

## Snelstart token response does not contain access_token.

**Cause.** The token endpoint answered with a 2xx, but without an `access_token`. Usually `SNELSTART_TOKEN_URL` points at something that is not the token endpoint.

**Fix.** Remove `SNELSTART_TOKEN_URL` from `.env` to get the default, `https://auth.snelstart.nl/b2b/token`.

## Snelstart API call failed. HTTP status: 401

**Cause.** The token endpoint gave a token, and the API refused the call with it. The client already fetched a new token and tried once more if the token was an older one, so this is not an expired token.

**Fix.** Run `php artisan snelstart:test`. When the subscription key check passes, the client key has no access to what you asked for; that is arranged at SnelStart. The same goes for a `403`: the SnelStart documentation lists the scopes each call needs.

## Snelstart API call failed. HTTP status: 400

**Cause.** SnelStart refused the data you sent. The response usually names one or more error codes.

**Fix.** Read them from the exception:

```php
$e->errors();   // ['REL-0007' => 'Naam moet gevuld zijn']
```

The codes and their texts are in `Darvis\Snelstart\ErrorCodes::MESSAGES`, taken from the list SnelStart publishes. `ALG-0104` is an OData filter SnelStart cannot read; `ALG-0101` is an id in the URL that differs from the id in the data.

## Snelstart API call failed. HTTP status: 404 or 405

**Cause.** The path does not exist, the row does not exist, or the collection does not have the method you called. The package lets you call `delete()` on every collection; SnelStart decides whether it exists.

**Fix.** Check the id and the path. The [API reference](api-reference.md#collections) lists what each collection has in the SnelStart documentation.

## Snelstart API call failed. HTTP status: 429 or 5xx

**Cause.** SnelStart is busy or down. The package does not retry these: only your application knows whether a call may be sent twice.

**Fix.** Make the call from a queued job with a backoff, and rethrow the exception for these statuses so the queue tries again.

## Snelstart API call failed. No response

**Message.** `Snelstart API call failed. No response: cURL error 28: ...` (or another cause)

**Cause.** No answer came back: the host name did not resolve (`cURL error 6`), the connection was refused (`cURL error 7`), or the answer took longer than the timeout (`cURL error 28`). `$e->status()` is `0`, and `$e->getPrevious()` is the original exception. In a test it reads `No response: Attempted request to [...] without a matching fake`: a request you did not fake.

**Fix.** Check that the server can reach `b2bapi.snelstart.nl` and `auth.snelstart.nl` over HTTPS. For a slow call, raise `SNELSTART_TIMEOUT`. After a timeout on a call that writes, check in SnelStart whether it arrived before sending it again.

## Snelstart request data cannot be sent as JSON

**Cause.** The array you passed cannot be encoded: usually a string that is not valid UTF-8.

**Fix.** Convert the text to UTF-8 before you pass it, for example with `mb_convert_encoding()`.

## Snelstart token cache is not available, the token is kept in memory only

**Where.** A warning in your log, once per process. Calls keep working.

**Cause.** The cache store could not be used: it is down, `SNELSTART_TOKEN_CACHE_STORE` names a store that is not in `config/cache.php`, the application has no `APP_KEY`, or the lock failed, for example a `database` cache store without the `cache_locks` table.

**Fix.** Fix the store, or set `SNELSTART_TOKEN_CACHE=false` to keep the token in memory on purpose.

## Snelstart paging does not advance

**Message.** `Snelstart paging does not advance: "landen" returned the same page twice. Use list(), or resource($path, false) for a collection without paging.`

**Cause.** `all()` asks for the next page after a full one. This collection answered with the same rows again, so it ignores `$skip`, and the loop would never end.

**Fix.** The collections of the package know whether SnelStart documents paging for them. For `resource($path)` you say so yourself: pass `false` as the second argument for a collection without `$skip` and `$top`. In a test, a fake that always returns the same full page causes this: return fewer rows than the page size.

## There are no commands defined in the "snelstart" namespace.

**Cause.** Laravel has not discovered the package.

**Fix.** Run `php artisan package:discover`. When your `composer.json` lists the package under `extra.laravel.dont-discover`, register `Darvis\Snelstart\Laravel\SnelstartServiceProvider` in `bootstrap/providers.php`.

## Class "Darvis\Snelstart\Services\SnelstartAPI" not found

**Cause.** Code written for version 1 of the package.

**Fix.** See [Upgrading](upgrading.md).

## A value in .env is ignored

**Cause.** The config is cached.

**Fix.** `php artisan config:clear`, or `php artisan config:cache` again.
