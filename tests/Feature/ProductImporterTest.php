<?php

namespace Tests\Feature;

use App\Services\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProductImporterTest extends TestCase
{
    use RefreshDatabase;

    protected ProductImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = app(ProductImporter::class);
    }

    /** @test
     * @throws \Exception
     */
    public function it_can_import_products_and_variants_from_csv()
    {
        // Prepare a mock CSV file
        $csvContent = "Handle,Title,Vendor,Variant SKU,Variant Inventory Qty,Variant Price,Variant Barcode\n"
            . "product-1,Product 1,BrandX,Sku1,10,99.99,123456789\n"
            . "product-2,Product 2,BrandY,Sku2,5,49.99,987654321\n";

        $csvPath = storage_path('test_import.csv');
        file_put_contents($csvPath, $csvContent);

        // Run the import
        $stats = $this->importer->import($csvPath);

        // Assertions
        $this->assertEquals(0, $stats['corruptedRows']);
        $this->assertEquals(2, $stats['productsImported']);
        $this->assertEquals(2, $stats['variantsImported']);

        // Clean up
        unlink($csvPath);
    }

    /** @test */
    public function it_rejects_csv_with_invalid_headers()
    {
        // CSV with wrong header format (missing 'Variant Barcode' column)
        $csvContent = "Handle,Title,Vendor,Variant SKU,Variant Inventory Qty,Variant Price\n"
            . "product-1,Product 1,BrandX,Sku1,10,99.99\n";

        $csvPath = storage_path('invalid_headers.csv');
        file_put_contents($csvPath, $csvContent);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Invalid header row: Expected 7 columns, got 6");

        app(ProductImporter::class)->import($csvPath);

        unlink($csvPath);
    }

    /** @test */
    public function it_skips_rows_with_missing_or_extra_columns()
    {
        // Row 1: Valid
        // Row 2: Missing 'Variant Barcode'
        // Row 3: Extra column
        $csvContent = "Handle,Title,Vendor,Variant SKU,Variant Inventory Qty,Variant Price,Variant Barcode\n"
            . "product-1,Product 1,BrandX,Sku1,10,99.99,123456789\n"
            . "product-2,Product 2,BrandY,Sku2,5,49.99\n"
            . "product-3,Product 3,BrandZ,Sku3,15,149.99,456789123,ExtraColumn\n";

        $csvPath = storage_path('corrupted_rows.csv');
        file_put_contents($csvPath, $csvContent);

        $stats = app(ProductImporter::class)->import($csvPath);

        // Assertions
        $this->assertEquals(2, $stats['corruptedRows']); // Row 2 & 3 are invalid
        $this->assertEquals(1, $stats['productsImported']); // Only Row 1 is inserted
        $this->assertEquals(1, $stats['variantsImported']);

        unlink($csvPath);
    }

    /** @test */
    public function it_skips_rows_with_invalid_data_types()
    {
        // Row 1: Valid
        // Row 2: Non-numeric price
        // Row 3: Negative quantity
        $csvContent = "Handle,Title,Vendor,Variant SKU,Variant Inventory Qty,Variant Price,Variant Barcode\n"
            . "product-1,Product 1,BrandX,Sku1,10,99.99,123456789\n"
            . "product-2,Product 2,BrandY,Sku2,10,INVALID_PRICE,987654321\n"
            . "product-3,Product 3,BrandZ,Sku3,-5,79.99,555555555\n";

        $csvPath = storage_path('invalid_data.csv');
        file_put_contents($csvPath, $csvContent);

        $stats = app(ProductImporter::class)->import($csvPath);

        // Assertions
        $this->assertEquals(2, $stats['corruptedRows']); // Rows 2 & 3 are invalid
        $this->assertEquals(1, $stats['productsImported']);
        $this->assertEquals(1, $stats['variantsImported']);

        unlink($csvPath);
    }
}
