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
    public function handle(SnelstartAPI $snelstart): int
    {
        $this->info('Testing Snelstart API connection...');

        try {
            // Test the connection by retrieving company info
            $companyInfo = $snelstart->getCompanyInfo();
            
            $this->info('✓ Connection successful!');
            
            if (!empty($companyInfo)) {
                $this->info('Company info retrieved.');
                $this->line(json_encode($companyInfo, JSON_PRETTY_PRINT));
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
