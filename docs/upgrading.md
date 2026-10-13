---
title: "Upgrading"
nav_order: 10
description: "Upgrade darvis/snelstart from 1.x to 2.0: the two clients became one, what each old class and method is now, and what changed in exceptions and output."
---

# Upgrading from 1.x to 2.0

Version 2 is a rewrite. Version 1 had two clients that had to be kept in step, one for Laravel and one on cURL; version 2 has one plain PHP client, and Laravel only supplies its parts. The config keys and the environment variables are the same.

## Classes

| 1.x | 2.0 |
| --- | --- |
| `Darvis\Snelstart\Services\SnelstartAPI` | `Darvis\Snelstart\Client`, or the `Snelstart` facade |
| `Darvis\Snelstart\Standalone\SnelstartAPI` | `Darvis\Snelstart\Client` |
| `new Standalone\SnelstartAPI([...])` | `new Client(Config::fromArray([...]))`, the same array keys |
| `Standalone\SnelstartAPI::fromEnv()` | `Client::fromEnv()` |
| `Darvis\Snelstart\Services\EchoService` | `$client->echo()`, which throws like every other call |
| `Darvis\Snelstart\Support\SnelstartConfig` | `$client->config()`, a `Darvis\Snelstart\Config` |
| `Darvis\Snelstart\SnelstartServiceProvider` | `Darvis\Snelstart\Laravel\SnelstartServiceProvider`; package discovery finds it by itself |
| container alias `snelstart.echo` | removed |

## Methods

| 1.x | 2.0 |
| --- | --- |
| `getCompanyInfo()` | `companyInfo()` |
| `getRelaties($query)` | `relaties()->list($query)`, or `relaties()->all($query)` for more than 500 rows |
| `createRelatie($data)` | `relaties()->create($data)` |
| `getArtikelen($query)` | `artikelen()->list($query)` |
| `createVerkooporder($data)` | `verkooporders()->create($data)` |
| `get()`, `post()`, `put()`, `delete()` | the same names; they return the decoded response, which is `null` for an empty one instead of `[]` |
| `head()` | removed; the API v2 has no `HEAD` call |

## Behaviour

- **A timeout is a `SnelstartException`.** In 1.x the Laravel client let `Illuminate\Http\Client\ConnectionException` through. Now every failure is a `SnelstartException` with status `0`, and the connection exception is its previous exception. A `catch (ConnectionException $e)` around a SnelStart call no longer catches anything.
- **The client is built without a client key.** In 1.x the constructor threw `Snelstart API config is incomplete (token_url, client_key).`; now the first call that needs a token throws `Snelstart config is incomplete: the client key is empty.`
- **`snelstart:test` prints less.** It checks the subscription key and the client key separately and prints the name of the administration, no longer the whole company info as JSON. The lines `Connection failed:` and `Company info retrieved.` are gone.
- **The Echo API is called where it lives.** In 1.x `EchoService` called `/echo/resource` below the base URL of the v2 API. The Echo API has its own base URL, `echo_url`.
- **The classes are closed.** The protected methods and properties of the 1.x clients are gone. To change how requests are sent or where the token is kept, give the client a `Transport` or a `TokenStore`.
- **Requirements.** `illuminate/*` and Carbon are no longer required by the package, so it installs in a project without Laravel. In Laravel it still needs 11, 12 or 13.

The messages `Snelstart API call failed. HTTP status: ...` and `Failed to retrieve access_token from Snelstart. ...` are unchanged, and so is the cache key of the token.
