---
title: "Troubleshooting"
nav_order: 8
description: "Every error message and log line of darvis/snelstart, quoted literally, with cause and fix: missing keys, a refused key, 401, 429, timeouts, the token cache."
---

# Troubleshooting

Start with `php artisan snelstart:test`. It makes one call with your keys and prints the same message your code would get.

In code, read the HTTP status from the exception with `$e->status()` instead of taking it out of the message. `0` means there was no response at all.

## Snelstart API config is incomplete (token_url, client_key).

**Cause.** The client was built without a client key, or `token_url` was set to an empty value. The usual reasons: `SNELSTART_CLIENT_KEY` is missing from `.env`, or the config is cached with the old value.

**Fix.** Add the key to `.env`, then run `php artisan config:clear` (or `php artisan config:cache` again in production). The exception has status `0`.

## Failed to retrieve access_token from Snelstart. HTTP status: 401

Also with `400` or another status.

**Cause.** The token endpoint did not accept the client key. `[redacted]` in the message is where the server echoed your key.

**Fix.** Compare `SNELSTART_CLIENT_KEY` with the key SnelStart gave you, character by character, and check `SNELSTART_TOKEN_URL` if you changed it. This request is never repeated.

## Snelstart token response does not contain access_token.

**Cause.** The token URL answered with a 2xx status, but the body is not JSON with an `access_token`.

**Fix.** Check `SNELSTART_TOKEN_URL`. The default is `https://auth.snelstart.nl/b2b/token`.

## Snelstart API call failed. HTTP status: 401

**Cause.** SnelStart accepted the client key (there is a token) but refused the call. When the token was one the client already had, the client has by now fetched a new token and repeated the call once; this is the answer to the second attempt. So a new token does not help.

**Fix.** Check `SNELSTART_SUBSCRIPTION_KEY`: without it the package leaves the `Ocp-Apim-Subscription-Key` header out and still makes the call. What the keys are allowed to do is decided by SnelStart.

## Snelstart API call failed. HTTP status: 429

**Cause.** SnelStart answered "too many requests". The package does not wait and does not retry.

**Fix.** Make the calls from a queued job with a backoff, and throw the exception again when `$e->status() === 429` so the queue tries later.

## Snelstart API call failed. HTTP status: 400, 404, 500 ...

**Cause.** SnelStart refused or could not handle the request. The part after `Response:` is its answer, with your keys replaced by `[redacted]`.

**Fix.** Read that answer. The package sends your array unchanged, so compare what you sent with the SnelStart API documentation. A 5xx is not retried; try again later from a queued job.

## Illuminate\Http\Client\ConnectionException, or cURL error: Operation timed out

**Cause.** SnelStart could not be reached, or did not answer within 30 seconds (10 to connect). The Laravel client throws `Illuminate\Http\Client\ConnectionException`, which is not a `SnelstartException` and not a `RuntimeException`. The standalone client throws a `SnelstartException` with `cURL error: ...` and status `0`.

**Fix.** Catch `ConnectionException` separately. For a call that really needs longer, raise `SNELSTART_TIMEOUT` or `SNELSTART_CONNECT_TIMEOUT` (the `timeout` and `connect_timeout` keys of the standalone client). A write that timed out may still have been processed, so look the record up before you send it again.

## There are no commands defined in the "snelstart" namespace.

**Cause.** Laravel has not registered the service provider of the package: package discovery is turned off for it (`dont-discover` in your `composer.json`), or the cached package list is out of date.

**Fix.** Run `php artisan package:discover`. When discovery is off on purpose, add `Darvis\Snelstart\SnelstartServiceProvider::class` to `bootstrap/providers.php`.

## An empty array comes back

**Cause.** One of three: the response had no body (a `204`, or a `HEAD`), the body was not a JSON object or list (the package returns `[]` for that instead of throwing), or the administration has no such records.

**Fix.** Make the same call with `php artisan tinker` and another endpoint you know has data. `[]` alone does not tell the three apart.

## Snelstart token cache is not available, the token is kept in memory only

A warning in the log, once per client instance, followed by the reason.

**Cause.** The cache store or its lock could not be used: the store is down, `SNELSTART_TOKEN_CACHE_STORE` names a store that is not in `config/cache.php`, the `database` store has no `cache_locks` table, or the application has no `APP_KEY` to encrypt the token with.

**Fix.** Repair what the reason names, or set `SNELSTART_TOKEN_CACHE=false`. The calls themselves keep working in the meantime; every process fetches its own token.

## A request waits up to five seconds before it calls SnelStart

**Cause.** Another request holds the lock around the token request and has not released it: its token request is slow, or its process died. After five seconds the waiting request fetches a token itself, so nothing fails.

**Fix.** Nothing to do. A lock that was never released runs out by itself after the timeout plus five seconds, two minutes at most.

## Every request still fetches a token

**Cause.** `SNELSTART_TOKEN_CACHE` is `false`; or the default cache store is `array` or `null`, which keep nothing between requests; or the token lives sixty seconds or less, so there is nothing to keep.

**Fix.** Point `SNELSTART_TOKEN_CACHE_STORE` at a store that persists, such as `file`, `database` or `redis`.

## A new key or URL in .env is ignored

**Cause.** The config is cached, or the client was already built. The client is a singleton that reads the config once, when it is first used.

**Fix.** Run `php artisan config:clear`. In a queue worker, restart it (`php artisan queue:restart`). In a test, call `app()->forgetInstance(\Darvis\Snelstart\Services\SnelstartAPI::class)` after changing the config. A token that was fetched with the old client key stays in the cache under the old key's name and is not used for the new one.

## After php artisan cache:clear

The token is gone with the rest of the cache. The next call fetches a new one; you don't have to do anything.

## A published config/snelstart.php from an older version

Nothing to fix. Laravel merges the package file underneath yours, so keys that were added later (`timeout`, `connect_timeout`, `token_cache`) have their defaults and their environment variables.
