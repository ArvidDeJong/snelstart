<?php

namespace Darvis\Snelstart\Console\Commands;

use Darvis\Snelstart\Services\SnelstartAPI;
use Illuminate\Console\Command;

class TestSnelstartConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snelstart:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the connection with the Snelstart API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Testing Snelstart API connection...');

        try {
            // The client is resolved here and not injected: its constructor throws when the client
            // key is missing, and that has to end up in the catch below instead of in a stack trace.
            $snelstart = $this->laravel->make(SnelstartAPI::class);

            // Test the connection by retrieving company info
            $companyInfo = $snelstart->getCompanyInfo();

            $this->info('✓ Connection successful!');

            if (! empty($companyInfo)) {
                $this->info('Company info retrieved.');

                // This prints the company data of the administration. The package never prints a key or a token.
                $json = json_encode($companyInfo, JSON_PRETTY_PRINT);

                if ($json !== false) {
                    $this->line($json);
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Connection failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
