---
title: "Quick start"
nav_order: 3
description: "One complete darvis/snelstart example for Laravel: a route and a controller that read the company info from SnelStart and handle a failure by its status."
---

# Quick start

This page builds one thing from start to finish: a URL in your application that shows the company info of your SnelStart administration, and that answers sensibly when SnelStart does not. You need a working installation first; `php artisan snelstart:test` must say `Connection successful!` (see [Installation](installation.md)).

## 1. The route

`routes/web.php`

```php
use App\Http\Controllers\SnelstartCompanyController;
use Illuminate\Support\Facades\Route;

Route::get('/snelstart/company', SnelstartCompanyController::class);
```

One URL that points at a controller with a single action. Put it behind your own `auth` middleware before it goes live: this is data from a bookkeeping.

## 2. The controller

`app/Http/Controllers/SnelstartCompanyController.php`

```php
<?php

namespace App\Http\Controllers;

use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SnelstartCompanyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            $snelstart = app(SnelstartAPI::class);

            return response()->json($snelstart->getCompanyInfo());
        } catch (ConnectionException $e) {
            // No answer within the timeout, or SnelStart could not be reached.
            return response()->json(['error' => 'SnelStart did not answer.'], 504);
        } catch (SnelstartException $e) {
            // The message has the response body, with the keys redacted. Log it, don't show it.
            Log::error($e->getMessage());

            $reason = match (true) {
                $e->status() === 0 => 'The SnelStart keys are not configured.',
                $e->status() === 401 => 'SnelStart refused the keys.',
                $e->status() === 429 => 'Too many calls, try again later.',
                default => 'SnelStart answered with status '.$e->status().'.',
            };

            return response()->json(['error' => $reason], 502);
        }
    }
}
```

What happens when you open `/snelstart/company`:

1. `app(SnelstartAPI::class)` gives the client, a singleton (one instance for the whole request). It reads `config/snelstart.php`. Without a client key it throws a `SnelstartException` with status `0` right here, which is why the client is resolved inside the `try` instead of being injected as an argument.
2. `getCompanyInfo()` first gets an access token: from memory, from the cache, or from the token endpoint. Then it sends `GET /companyInfo` and returns the decoded JSON as an array.
3. A 4xx or 5xx from SnelStart becomes a `SnelstartException`. `$e->status()` is the HTTP status, so you decide on a number instead of reading it out of the message. `0` means there was no response at all.
4. A timeout or a network failure is an `Illuminate\Http\Client\ConnectionException`, the exception of Laravel's HTTP client. It is not a `SnelstartException`, so it has its own `catch`.

## 3. The other calls

Everything below goes where `getCompanyInfo()` is in the controller above, in `app/Http/Controllers/SnelstartCompanyController.php` or in any class of your own, with the same `try` and `catch` around it.

```php
$relations = $snelstart->getRelaties();          // GET /relaties
$filtered = $snelstart->getRelaties($query);     // GET /relaties?... , $query is an array
$articles = $snelstart->getArtikelen();          // GET /artikelen

$relation = $snelstart->createRelatie(['naam' => 'Example B.V.']);   // POST /relaties
$order = $snelstart->createVerkooporder($orderData);                 // POST /verkooporders
```

An array you pass to a `get` method becomes the query string as it is. An array you pass to a `create` method is sent as JSON. The package does not check or translate either: which query parameters and which fields an endpoint takes is in the SnelStart API documentation, and the decoded response comes back unchanged.

For an endpoint without a method of its own, give the path, relative to the base URL:

```php
$relation = $snelstart->get('/relaties/'.$id);
$snelstart->put('/relaties/'.$id, $data);
$snelstart->delete('/relaties/'.$id);
```

## 4. Write from a queued job

A call to SnelStart can take seconds, and the package does not retry a 429 or a 5xx. For a write, use a queued job (a class Laravel runs in the background and can try again) with `$tries` and `$backoff`, and let the status decide: throw the exception again for a `429` or a `5xx` so the queue tries later, and call `$this->fail($e)` for any other status. After a `ConnectionException` on a write, look the record up before you send it again: SnelStart may have processed it.

Next: [How it works](concepts.md) for the token, the one repeat after a 401 and the timeouts, and [Testing](testing.md) to test the controller above without calling SnelStart.
