<?php

namespace App\Jobs;

use App\Services\ProductImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $csvPath;

    /**
     * Create a new job instance.
     */
    public function __construct(string $csvPath)
    {
        $this->csvPath = $csvPath;
    }

    /**
     * Execute the job.
     */
    public function handle(ProductImporter $importer): void
    {
        try {
            Log::info('Starting queued import from ' . $this->csvPath);

            $stats = $importer->import($this->csvPath);

            Log::info('Queued import completed.');
            Log::info('Products imported: ' . $stats['productsImported']);
            Log::info('Variants imported: ' . $stats['variantsImported']);
            Log::warning('Corrupted rows: ' . $stats['corruptedRows']);
        } catch (\Exception $e) {
            Log::error('Error in queued import: ' . $e->getMessage());
        }
    }
}
