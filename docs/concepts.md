---
title: "How it works"
nav_order: 4
description: "How darvis/snelstart authenticates, where the access token is cached, the retry on a refused token, the timeouts and where the SnelStart keys travel."
---

# How it works

## What happens when you make a call

1. The client looks for an access token (the short lived key SnelStart gives in exchange for your client key): first on its own instance, then in the cache. When there is none, or it expires within sixty seconds, it fetches one first.
2. The token request is a form post to the token URL with `grant_type=clientkey` and `clientkey=<your client key>`.
3. The API call goes to the base URL plus the path, with `Authorization: Bearer <token>`, `Accept: application/json` and, when a subscription key is set, `Ocp-Apim-Subscription-Key`.
4. A 401 on a token the client already had is answered with a new token and one repeat of the call, see below.
5. A 4xx or 5xx answer throws. Anything else is decoded as JSON and returned as an array.

## Where the token lives

- The token is kept until sixty seconds before its `expires_in`. Without `expires_in` in the response the client assumes 3600 seconds.
- **In memory, on the instance.** The Laravel client is a singleton, so within one process the cache is read once, however many calls follow.
- **In the cache, encrypted.** The Laravel client stores the token in Laravel's cache for the same period, so the next web request, the next job and the next process use it instead of fetching their own. The value is encrypted with the application key (`Crypt`), because a cache store is often shared and this is a token for a bookkeeping.
- The cache key is `snelstart.token.` plus a SHA-256 hash of the token URL and the client key. Two administrations in one application never share a token, and the client key is not readable in the key.
- A cached value the application cannot decrypt (a rotated `APP_KEY`, something else under that key) counts as no token: it is removed and a new token is fetched. Nothing throws.
- `php artisan cache:clear` removes the token with everything else. The next call fetches a new one; nothing else happens.
- When the cache store is down, does not exist, or the application has no `APP_KEY`, the client logs one warning (`Snelstart token cache is not available, the token is kept in memory only: ...`) and works from memory.
- `$snelstart->forgetToken()` drops the token on purpose, in memory and in the cache.
- **One request fetches, the others wait.** When the cache has no token, the client takes a lock on the same cache store before it calls the token endpoint, and looks in the cache once more when it has the lock. Requests that arrive together on a cold cache wait at most five seconds for the first one and then use its token, so there is one token request instead of one per request. A store without locks, a lock that does not come free in those five seconds or a lock that fails: the client fetches a token without the lock. The lock never fails a call.

| Setting | Environment variable | Default |
| --- | --- | --- |
| `token_cache.enabled` | `SNELSTART_TOKEN_CACHE` | `true` |
| `token_cache.store` | `SNELSTART_TOKEN_CACHE_STORE` | none: the default cache store |

With `SNELSTART_TOKEN_CACHE=false` the token only lives in memory, on the instance, as it did up to 1.1: every web request fetches its own.

The standalone client has no cache and no lock; its token lives on the instance.

## A refused token: one new token, one repeat

When a call gets a 401 (the HTTP status for "not authorised") and the token was one the client already had, from memory or from the cache, the token is no longer accepted although its lifetime has not run out. Both clients then forget the token, fetch a new one and send the same request once more.

- Only a 401 is repeated: the status with which a server refuses a request because of its credentials.
- A second 401 throws, as any other failure does.
- A 401 on a token that was fetched for this very call is not repeated: a new token would not change the answer. Check the subscription key.
- A 401 of the token endpoint is never repeated.
- Nothing else is retried: not a 429, not a 5xx.

## Timeouts

Both clients wait 30 seconds for a whole request and 10 seconds for the connection, the token request included.

| Setting | Environment variable | Default |
| --- | --- | --- |
| `timeout` | `SNELSTART_TIMEOUT` | `30` |
| `connect_timeout` | `SNELSTART_CONNECT_TIMEOUT` | `10` |

Both are in seconds, and `2.5` is allowed. A value that is not a positive number gives the default.

## What a call returns

| Response | Result |
| --- | --- |
| 2xx with a JSON object or list | The decoded array |
| 2xx with an empty body (204, `HEAD`) | `[]` |
| 2xx with a body that is not a JSON object or list | `[]` |
| 4xx or 5xx | `SnelstartException` with that status |

## What a failure looks like

```text
Snelstart API call failed. HTTP status: 429. Response: {"message":"..."}
Failed to retrieve access_token from Snelstart. HTTP status: 401. Response: {"error":"..."}
Snelstart token response does not contain access_token.
Snelstart API config is incomplete (token_url, client_key).
```

- All four are a `Darvis\Snelstart\Exceptions\SnelstartException`, which extends `RuntimeException`. The messages are the ones above, so a `catch (\RuntimeException $e)` and a match on the text keep working.
- **Read the status, don't parse the message.** `$e->status()`, and `$e->getCode()`, give the HTTP status of the response that caused the exception: 429 and 401 for the first two lines, the status of the 2xx token response for the third, and `0` for the fourth, because there was no response.
- The response body is not a property of the exception. It can hold data of the administration; the message has the part that was always there, with the keys redacted.
- **A 429 or a 5xx is not retried.** Retry from a queued job with a backoff.
- In the Laravel client a timeout or a connection error is an `Illuminate\Http\Client\ConnectionException`. That class is not a `RuntimeException` and not a `SnelstartException`, so catch it separately; the package leaves it as it is, because host apps catch that class. The standalone client throws a `SnelstartException` with `cURL error: ...` and status `0` for the same.
- `EchoService` does not throw: it catches everything, writes `Snelstart Echo Resource GET failed: ...` to the log with `Log::error()` and returns an array with `success` set to `false`.

## Where the keys go

| Secret | Travels in | Never in |
| --- | --- | --- |
| Client key | The form body of the token request | A URL, a header |
| Subscription key | The `Ocp-Apim-Subscription-Key` header of API calls | A URL, the token request |
| Access token | The `Authorization` header of API calls; encrypted in the cache | A URL, a log line, the cache in readable form |

The response body of a failed call is part of the exception message, and a server can echo what it received. Before the message is thrown the client replaces the client key, the subscription key and the access token in it with `[redacted]`, also in their JSON escaped and URL encoded forms. That message is what `EchoService` logs and what `php artisan snelstart:test` prints.

The rest of the response body is not filtered. It can hold data of the administration, so treat your log as you treat the bookkeeping.

## Which of the two clients this is about

`Darvis\Snelstart\Services\SnelstartAPI` is the client for Laravel. `Darvis\Snelstart\Standalone\SnelstartAPI` is the same set of methods for a project without Laravel. See [Standalone client](standalone.md) for the differences.
