<?php

namespace App\Console\Commands;

use App\Jobs\ImportProductsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-products {--csvPath=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is the entry point of the csv import';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $csvPath = $this->option('csvPath') ?? public_path('input.csv');

        try {
            $this->info('Queuing import for ' . $csvPath);

            ImportProductsJob::dispatch($csvPath);

            $this->info('Import has been queued successfully.');
        } catch (\Exception $e) {
            Log::error('Error during import: ' . $e->getMessage());
            $this->error('Import failed: ' . $e->getMessage());
        }
    }
}
