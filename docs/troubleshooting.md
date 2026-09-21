---
title: Troubleshooting
nav_order: 8
description: "The messages darvis/snelstart can give, what causes them and what to do: incomplete config, a refused key, 401, 429, timeouts, the token cache and empty results."
---

# Troubleshooting

Start with `php artisan snelstart:test`. It shows the same message your code would get.

In code, read the HTTP status from the exception with `$e->status()` instead of taking it out of the message. It is `0` when there was no response at all.

## Snelstart API config is incomplete (token_url, client_key).

`SNELSTART_CLIENT_KEY` is empty, or `token_url` was set to an empty value. When the config is cached, run `php artisan config:clear` after changing `.env`.

## Failed to retrieve access_token from Snelstart. HTTP status: 400 or 401

The token endpoint refused the client key. Check that the key is complete (copying can cut off the end) and that it belongs to the administration you expect. `[redacted]` in the message is where the server echoed your key.

## Snelstart token response does not contain access_token.

The token URL answered with a 2xx that is not the expected JSON, for example a login page. Check `SNELSTART_TOKEN_URL`.

## Snelstart API call failed. HTTP status: 401

When the token was one the client already had, it has by now fetched a new token and repeated the call once; this is the answer to the second attempt. So the token is not the problem:

- The subscription key is missing or wrong: without it the package leaves the `Ocp-Apim-Subscription-Key` header out and still makes the call.
- The client key has no access to what you ask for.

## Snelstart API call failed. HTTP status: 429

SnelStart refused the call because there were too many. The package does not wait and does not retry. Make the calls from a queued job with a backoff.

## Illuminate\Http\Client\ConnectionException, or cURL error: Operation timed out

SnelStart could not be reached or did not answer within 30 seconds (10 to connect). Raise `SNELSTART_TIMEOUT` or `SNELSTART_CONNECT_TIMEOUT` (the `timeout` and `connect_timeout` keys of the standalone client) for a call that really needs longer. The Laravel client throws the `ConnectionException`, which is not a `RuntimeException`, so catch it separately. The standalone client throws a `SnelstartException` with the cURL message and status `0`. A write that timed out may still have been processed by SnelStart, so check before you send it again.

## An empty array comes back

- The response had no body (`204`, or a `HEAD`).
- The body was not a JSON object or list. The package returns `[]` for that instead of throwing.
- The administration simply has no such records.

## Snelstart token cache is not available, the token is kept in memory only

A warning in the log, once per process. The cache store could not be used: it is down, `SNELSTART_TOKEN_CACHE_STORE` names a store that is not in `config/cache.php`, or the application has no `APP_KEY` to encrypt the token with. The calls themselves work; every process fetches its own token until the cache is back.

## A request waits up to five seconds before it calls SnelStart

Another request holds the lock around the token request, and did not finish or release it. After five seconds the waiting request fetches its own token, so nothing fails. It happens when a token request itself is slow, or when a process died while holding the lock; the lock then runs out by itself after the timeout plus five seconds, two minutes at most.

## Every request still fetches a token

- `SNELSTART_TOKEN_CACHE` is `false`.
- The default cache store is `array` or `null`, which forget everything at the end of the request. Point `SNELSTART_TOKEN_CACHE_STORE` at a store that persists.
- The token lives sixty seconds or less, so there is nothing to keep.

## After php artisan cache:clear

The token is gone with the rest of the cache. The next call fetches a new one; you don't have to do anything.

## The config change is ignored

The client is a singleton and reads the config when it is built. In a test or a long running process, call `app()->forgetInstance(\Darvis\Snelstart\Services\SnelstartAPI::class)` after changing it.
