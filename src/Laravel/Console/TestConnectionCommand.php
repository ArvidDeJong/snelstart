<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Laravel\Console;

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Exceptions\SnelstartException;
use Illuminate\Console\Command;

class TestConnectionCommand extends Command
{
    protected $signature = 'snelstart:test';

    protected $description = 'Check the subscription key and the client key against the SnelStart B2B API';

    /**
     * Two steps, so the output says which key is wrong. It prints the name of the administration
     * and never a key, a token or anything else from the bookkeeping: command output ends up in
     * deploy logs.
     */
    public function handle(Client $snelstart): int
    {
        $this->info('Testing Snelstart API connection...');

        try {
            $snelstart->echo();
            $this->info('✓ Subscription key accepted.');
        } catch (SnelstartException $e) {
            $this->error('✗ Subscription key check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $company = $snelstart->companyInfo();
        } catch (SnelstartException $e) {
            $this->error('✗ Client key check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $name = $company['administratieNaam'] ?? null;

        $this->info('✓ Client key accepted.');
        $this->info('Connection successful!'.(is_string($name) && $name !== '' ? ' Administration: '.$name : ''));

        return self::SUCCESS;
    }
}
