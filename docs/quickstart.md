---
title: "Quick start"
nav_order: 3
description: "One complete darvis/snelstart example for Laravel: an Artisan command that reads every customer from SnelStart, creates one, and handles a failure."
---

# Quick start

This page builds one thing from start to finish: an Artisan command that lists the customers of your SnelStart administration and can add one. You need a working installation first; `php artisan snelstart:test` must say `Connection successful!` (see [Installation](installation.md)).

## 1. Read a collection

`app/Console/Commands/SnelstartCustomers.php`

```php
<?php

namespace App\Console\Commands;

use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Laravel\Facades\Snelstart;
use Illuminate\Console\Command;

class SnelstartCustomers extends Command
{
    protected $signature = 'snelstart:customers {--add= : The name of a customer to create}';

    protected $description = 'List the customers in SnelStart, or add one';

    public function handle(): int
    {
        try {
            if ($name = $this->option('add')) {
                $relatie = Snelstart::relaties()->create([
                    'relatiesoort' => ['Klant'],
                    'naam' => $name,
                ]);

                $this->info('Created: '.json_encode($relatie));

                return self::SUCCESS;
            }

            $customers = Snelstart::relaties()->all([
                '$filter' => "Relatiesoort/any(r:r eq 'Klant')",
            ]);

            foreach ($customers as $customer) {
                $this->line($customer['relatiecode'].'  '.$customer['naam']);
            }

            return self::SUCCESS;
        } catch (SnelstartException $e) {
            return $this->failed($e);
        }
    }

    private function failed(SnelstartException $e): int
    {
        if ($e->status() === 400) {
            // SnelStart refused the data. errors() has its error codes with their meaning.
            foreach ($e->errors() as $code => $meaning) {
                $this->error($code.': '.($meaning ?? 'unknown error code'));
            }

            return self::INVALID;
        }

        if ($e->status() === 0 || $e->status() === 429 || $e->status() >= 500) {
            // No response, too many calls, or SnelStart is down: try again later.
            $this->warn('SnelStart is not available right now.');

            return self::FAILURE;
        }

        // Anything else (401, 403, 404) will not get better by trying again.
        $this->error($e->getMessage());

        return self::FAILURE;
    }
}
```

```bash
php artisan snelstart:customers
php artisan snelstart:customers --add="Example B.V."
```

## What happens

- `Snelstart::relaties()` is the collection `/relaties`. The facade reaches `Darvis\Snelstart\Client`, a singleton; you can inject that class instead: `public function handle(Client $snelstart)`.
- `all()` asks SnelStart for 500 rows at a time and gives you one row per loop. With 1,200 customers that is three calls; stop looping and no further call is made. `list()` is a single call and returns an array.
- `'$filter'` is an OData filter. The package passes it on as it is; the filter above is the one from the example in the SnelStart documentation, with `Klant` in place of `Eigen`.
- `create()` posts your array as JSON and returns the row SnelStart answers with, unchanged. The field names (`relatiesoort`, `naam`, `relatiecode`) are those of the API.
- Every failure is a `SnelstartException`: a 4xx or 5xx, a refused key, a timeout. `status()` is the HTTP status, and `0` when there was no response.

## Other calls

```php
$relatie = Snelstart::relaties()->find($id);
$relatie = Snelstart::relaties()->update($id, $relatie);   // send the whole row
Snelstart::relaties()->delete($id);

$company = Snelstart::companyInfo();

// Anything that is not a plain collection, by its path:
$fields = Snelstart::get("relaties/{$id}/customFields");
Snelstart::put("verkooporders/{$id}/ProcesStatus", ['procesStatus' => 'Bevestiging']);
$balance = Snelstart::get('rapportages/periodebalans', ['start' => '2026-01-01', 'end' => '2026-03-31']);
```

The collections and what they support are listed in the [API reference](api-reference.md#collections).

## Before this goes live

- **Write from a queued job.** A 429 or a 5xx is not retried by the package. A job with `$tries` and `$backoff` that rethrows the exception for those statuses is.
- **A call without a response may have arrived.** After a timeout on a `create()`, look the row up before you send it again, or you book it twice.
- **Protect what you show.** This is data from a bookkeeping: put routes that show it behind your own authorisation.

Next: [How it works](concepts.md).
