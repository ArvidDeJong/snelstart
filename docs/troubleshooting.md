---
title: Troubleshooting
nav_order: 8
description: "The messages darvis/snelstart can give, what causes them and what to do: incomplete config, a refused key, 401, 429, timeouts and empty results."
---

# Troubleshooting

Start with `php artisan snelstart:test`. It shows the same message your code would get.

## Snelstart API config is incomplete (token_url, client_key).

`SNELSTART_CLIENT_KEY` is empty, or `token_url` was set to an empty value. When the config is cached, run `php artisan config:clear` after changing `.env`.

## Failed to retrieve access_token from Snelstart. HTTP status: 400 or 401

The token endpoint refused the client key. Check that the key is complete (copying can cut off the end) and that it belongs to the administration you expect. `[redacted]` in the message is where the server echoed your key.

## Snelstart token response does not contain access_token.

The token URL answered with a 2xx that is not the expected JSON, for example a login page. Check `SNELSTART_TOKEN_URL`.

## Snelstart API call failed. HTTP status: 401

- The subscription key is missing or wrong: without it the package leaves the `Ocp-Apim-Subscription-Key` header out and still makes the call.
- In a long running process (a queue worker): the token was rejected before the client considered it expired. The client does not fetch a new one on a 401. Call `app()->forgetInstance(\Darvis\Snelstart\Services\SnelstartAPI::class)` and try again, or restart the worker.

## Snelstart API call failed. HTTP status: 429

SnelStart refused the call because there were too many. The package does not wait and does not retry. Make the calls from a queued job with a backoff.

## Illuminate\Http\Client\ConnectionException

SnelStart could not be reached within 30 seconds (10 to connect). This is not a `RuntimeException`; catch it separately. A write that timed out may still have been processed by SnelStart, so check before you send it again.

## An empty array comes back

- The response had no body (`204`, or a `HEAD`).
- The body was not a JSON object or list. The package returns `[]` for that instead of throwing.
- The administration simply has no such records.

## A call in the standalone client never returns

The standalone client sets no timeout. See [Standalone client](standalone.md).

## The config change is ignored

The client is a singleton and reads the config when it is built. In a test or a long running process, call `app()->forgetInstance(\Darvis\Snelstart\Services\SnelstartAPI::class)` after changing it.
