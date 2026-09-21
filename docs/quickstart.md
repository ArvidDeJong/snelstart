---
title: Quick start
nav_order: 3
description: "The first calls to SnelStart from a Laravel application: company info, relations, articles, a sales order and any other endpoint."
---

# Quick start

## Get the client

```php
use Darvis\Snelstart\Services\SnelstartAPI;

class RelationController extends Controller
{
    public function index(SnelstartAPI $snelstart)
    {
        return view('relations.index', [
            'relations' => $snelstart->getRelaties(),
        ]);
    }
}
```

`app(SnelstartAPI::class)` and `app('snelstart')` give the same singleton.

## Read

```php
$company = $snelstart->getCompanyInfo();

$relations = $snelstart->getRelaties(['$top' => 50, '$skip' => 100]);

$articles = $snelstart->getArtikelen();
```

The array you pass becomes the query string as it is. Which query parameters an endpoint accepts is described in the SnelStart API documentation; the package does not check them.

## Write

```php
$relation = $snelstart->createRelatie(['naam' => 'Example B.V.']);

$order = $snelstart->createVerkooporder($orderData);
```

The array is sent as JSON. Which fields a relation or a sales order needs is described in the SnelStart API documentation; the package passes the array on unchanged and returns the decoded response.

## Every other endpoint

```php
$relation = $snelstart->get('/relaties/'.$id);
$snelstart->put('/relaties/'.$id, $data);
$snelstart->delete('/relaties/'.$id);
```

The path is relative to the base URL, with or without a leading slash.

## Catch a failure

```php
use Darvis\Snelstart\Exceptions\SnelstartException;
use Illuminate\Http\Client\ConnectionException;

try {
    $snelstart->createVerkooporder($data);
} catch (ConnectionException $e) {
    // SnelStart could not be reached, or the call timed out.
} catch (SnelstartException $e) {
    if ($e->status() === 429) {
        // Too many calls: try again later.
    }

    // Any other 4xx or 5xx. The message has the status and the body, with the keys redacted.
}
```

`SnelstartException` extends `RuntimeException`, so a `catch (\RuntimeException $e)` you already have keeps working.

Write to SnelStart from a queued job: a call can take seconds, and a job can be tried again.
