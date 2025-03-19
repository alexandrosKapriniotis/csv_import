<?php

namespace Tests\Feature;

use App\Jobs\ImportProductsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_dispatches_the_import_job()
    {
        Queue::fake();

        $this->artisan('app:import-products --csvPath=' . storage_path('test_import.csv'))
            ->expectsOutput('Queuing import for ' . storage_path('test_import.csv'))
            ->assertExitCode(0);

        Queue::assertPushed(ImportProductsJob::class);
    }
}
