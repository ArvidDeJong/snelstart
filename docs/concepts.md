---
title: How it works
nav_order: 4
description: "How darvis/snelstart authenticates, how long the access token is kept, what a failed call does and where the SnelStart keys travel."
---

# How it works

## A call, step by step

1. The client looks for an access token on its own instance. When there is none, or it expires within sixty seconds, it fetches one first.
2. The token request is a form post to the token URL with `grant_type=clientkey` and `clientkey=<your client key>`.
3. The API call goes to the base URL plus the path, with `Authorization: Bearer <token>`, `Accept: application/json` and, when a subscription key is set, `Ocp-Apim-Subscription-Key`.
4. A 4xx or 5xx answer throws. Anything else is decoded as JSON and returned as an array.

## The token lifetime

- The token is kept until sixty seconds before its `expires_in`. Without `expires_in` in the response the client assumes 3600 seconds.
- It lives **in memory, on the instance**. The Laravel client is a singleton, so one web request fetches one token, however many calls it makes. The next web request fetches a new one. A queue worker keeps the instance, and so the token, for as long as the process lives.
- The token is not stored in the cache and not shared between processes.
- **A 401 is not retried.** When SnelStart rejects a token that the client still considers valid, the call throws, and so does every next call on that instance until the token expires. In a long running process, call `app()->forgetInstance(SnelstartAPI::class)` to start again with a new token.

## What comes back

| Response | Result |
| --- | --- |
| 2xx with a JSON object or list | The decoded array |
| 2xx with an empty body (204, `HEAD`) | `[]` |
| 2xx with a body that is not a JSON object or list | `[]` |
| 4xx or 5xx | `RuntimeException` |

## What a failure looks like

```text
Snelstart API call failed. HTTP status: 429. Response: {"message":"..."}
Failed to retrieve access_token from Snelstart. HTTP status: 401. Response: {"error":"..."}
Snelstart token response does not contain access_token.
Snelstart API config is incomplete (token_url, client_key).
```

- All four are a plain `RuntimeException`; the exception code is `0`, the HTTP status is only in the message.
- **Nothing is retried**: not a 401, not a 429, not a 5xx. Retry from a queued job with a backoff.
- In the Laravel client a timeout or a connection error is an `Illuminate\Http\Client\ConnectionException`. That class is not a `RuntimeException`, so catch it separately. The timeout is that of Laravel's HTTP client: 30 seconds, and 10 seconds to connect.
- `EchoService` is the exception: it catches everything, writes `Snelstart Echo Resource GET failed: ...` to the log with `Log::error()` and returns an array with `success` set to `false`.

## Where the keys go

| Secret | Travels in | Never in |
| --- | --- | --- |
| Client key | The form body of the token request | A URL, a header |
| Subscription key | The `Ocp-Apim-Subscription-Key` header of API calls | A URL, the token request |
| Access token | The `Authorization` header of API calls | A URL |

The response body of a failed call is part of the exception message, and a server can echo what it received. Before the message is thrown the client replaces the client key, the subscription key and the access token in it with `[redacted]`, also in their JSON escaped and URL encoded forms. That message is what `EchoService` logs and what `php artisan snelstart:test` prints.

The rest of the response body is not filtered. It can hold data of the administration, so treat your log as you treat the bookkeeping.

## Two clients

`Darvis\Snelstart\Services\SnelstartAPI` is the client for Laravel. `Darvis\Snelstart\Standalone\SnelstartAPI` is the same set of methods for a project without Laravel. See [Standalone client](standalone.md) for the differences.
