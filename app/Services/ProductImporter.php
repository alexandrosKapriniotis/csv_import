<?php

namespace App\Services;

use App\Dtos\ProductDto;
use App\Dtos\VariantDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonSchema\Validator;
use Illuminate\Support\Str;

class ProductImporter
{
    protected const EXPECTED_HEADER_COUNT = 7;
    protected const CHUNK_SIZE = 1000;

    protected Validator $validator;
    protected array $schema;

    public function __construct(Validator $validator)
    {
        $this->validator = $validator;
        $this->schema = json_decode(file_get_contents(app_path('Validation/product_variant_schema.json')), true);
    }

    /**
     * Import products and variants from a CSV file.
     *
     * @param string $csvPath The path to the CSV file
     * @return array<int, int> [corruptedRows, productsImported, variantsImported]
     * @throws \Exception
     */
    public function import(string $csvPath): array
    {
        if (!file_exists($csvPath)) {
            throw new \Exception("CSV file not found at: {$csvPath}");
        }

        $csvFile = $this->openCsvFile($csvPath);

        $stats = ['corruptedRows' => 0, 'productsImported' => 0, 'variantsImported' => 0];
        $productStatement = $this->prepareProductStatement();
        $variantStatement = $this->prepareVariantStatement();

        $productBatches = [];
        $variantData = [];
        $productIdMap = [];

        DB::beginTransaction();
        try {
            $this->processRows($csvFile, $productBatches, $variantData, $productIdMap, $stats);
            $this->flushRemainingProducts($productStatement, $productBatches, $stats);
            $this->updateProductIdMap($productIdMap);
            $this->processVariants($variantData, $variantStatement, $stats);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import failed: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            throw $e;
        } finally {
            fclose($csvFile);
        }

        return $stats;
    }

    /**
     * Reads the headers from the CSV file and validates the count.
     *
     * @param resource $csvFile The open CSV file handle
     * @return array The header row
     * @throws \Exception
     */
    private function readHeaders($csvFile): array
    {
        $headers = fgetcsv($csvFile);
        if ($headers === false || count($headers) !== self::EXPECTED_HEADER_COUNT) {
            throw new \Exception("Invalid header row: Expected " . self::EXPECTED_HEADER_COUNT . " columns, got " . (count($headers) ?: 0));
        }
        return $headers;
    }

    /**
     * Prepares the PDO statement for product insertions.
     *
     * @return \PDOStatement
     */
    private function prepareProductStatement(): \PDOStatement
    {
        return DB::connection()->getPdo()->prepare("
            INSERT INTO products (id, handle, name, description, brand, created_at, updated_at)
            VALUES (:id, :handle, :name, :description, :brand, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE name = VALUES(name), brand = VALUES(brand), updated_at = VALUES(updated_at)
        ");
    }

    /**
     * Prepares the PDO statement for variant insertions.
     *
     * @return \PDOStatement
     */
    private function prepareVariantStatement(): \PDOStatement
    {
        return DB::connection()->getPdo()->prepare("
            INSERT INTO variants (id, sku, product_id, quantity, price, barcode, status, created_at, updated_at)
            VALUES (:id, :sku, :product_id, :quantity, :price, :barcode, :status, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), price = VALUES(price), status = VALUES(status), updated_at = VALUES(updated_at)
        ");
    }

    /**
     * Processes the CSV file to collect product and variant data.
     *
     * @param resource $csvFile The open CSV file handle
     * @param array $productBatches The array to store product batches
     * @param array $variantData The array to store temporary variant data
     * @param array $productIdMap The map of handles to product IDs
     * @param array $stats The import statistics
     * @return void
     * @throws \Exception
     */
    private function processRows($csvFile, array &$productBatches, array &$variantData, array &$productIdMap, array &$stats): void
    {
        $headers = $this->readHeaders($csvFile);
        $now = now()->format('Y-m-d H:i:s');

        while (($row = fgetcsv($csvFile)) !== false) {
            if (!$this->isValidRow($row, $headers, $stats)) {
                continue;
            }

            $rowData = array_combine($headers, $row);
            $handle = trim($rowData['Handle']);

            $this->processProduct($handle, $rowData, $productBatches, $productIdMap, $now);
            $this->processVariant($handle, $rowData, $variantData, $productIdMap, $now);

            if (count($productBatches) >= self::CHUNK_SIZE) {
                $this->flushProductBatch($productBatches, $stats);
            }
        }
    }

    /**
     * Inserts any remaining products in the batch.
     *
     * @param \PDOStatement $statement The prepared PDO statement
     * @param array $productBatches The product batches to insert
     * @param array $stats The import statistics
     * @return void
     * @throws \Exception
     */
    private function flushRemainingProducts(\PDOStatement $statement, array &$productBatches, array &$stats): void
    {
        if (!empty($productBatches)) {
            $this->executeBatchInsert($statement, $productBatches, 'product');
            $stats['productsImported'] += count($productBatches);
            $productBatches = [];
        }
    }

    /**
     * Updates the product ID map with actual IDs from the database.
     *
     * @param array $productIdMap The map to update
     * @return void
     * @throws \Exception
     */
    private function updateProductIdMap(array &$productIdMap): void
    {
        if (empty($productIdMap)) {
            Log::warning("No products to insert. Cannot proceed with variant insertion.");
            throw new \Exception("No products to insert.");
        }

        $handles = array_keys($productIdMap);
        $insertedProducts = DB::table('products')
            ->whereIn('handle', $handles)
            ->select('id', 'handle')
            ->get()
            ->keyBy('handle')
            ->map(function ($item) {
                return $item->id;
            })
            ->toArray();
        $productIdMap = array_intersect_key($insertedProducts, $productIdMap);
    }

    /**
     * Processes and inserts variants using the updated product IDs.
     *
     * @param array $variantData The temporary variant data
     * @param \PDOStatement $variantStatement The prepared PDO statement
     * @param array $stats The import statistics
     * @return void
     * @throws \Exception
     */
    private function processVariants(array $variantData, \PDOStatement $variantStatement, array &$stats): void
    {
        $variantBatches = [];
        foreach ($variantData as $variantEntry) {

            $variantBatches[] = (new VariantDto(
                $variantEntry['sku'],
                $variantEntry['product_id'],
                $variantEntry['quantity'],
                $variantEntry['price'],
                $variantEntry['barcode'],
                $variantEntry['created_at'],
                $variantEntry['updated_at']
            ))->toArray();

            if (count($variantBatches) >= self::CHUNK_SIZE) {
                $this->executeBatchInsert($variantStatement, $variantBatches, 'variant');
                $stats['variantsImported'] += count($variantBatches);
                $variantBatches = [];
            }
        }

        if (!empty($variantBatches)) {
            $this->executeBatchInsert($variantStatement, $variantBatches, 'variant');
            $stats['variantsImported'] += count($variantBatches);
        }
    }

    /**
     * Executes a batch insert with the given statement and parameters.
     *
     * @param \PDOStatement $statement The prepared PDO statement
     * @param array $batch The data batch to insert
     * @param string $type The type of data ('product' or 'variant')
     * @return void
     * @throws \Exception
     */
    private function executeBatchInsert(\PDOStatement $statement, array $batch, string $type): void
    {
        if (empty($batch)) {
            return;
        }

        foreach ($batch as $row) {
            // Ensure $row is an associative array
            if (!is_array($row) || array_keys($row) === range(0, count($row) - 1)) {
                Log::error("Expected associative array in batch insert for $type, got: " . json_encode($row));
                throw new \Exception("Invalid data format in batch insert for $type");
            }

            // Map the associative array keys to PDO placeholders (e.g., 'id' => ':id')
            $params = array_combine(
                array_map(fn($key) => ':' . $key, array_keys($row)),
                array_values($row)
            );

            try {
                $statement->execute($params);
                $rowCount = $statement->rowCount();
                if ($rowCount === 0) {
                    Log::warning("No rows affected for $type insert: " . json_encode($params));
                } else {
                    Log::info("Inserted/Updated $rowCount row(s) for $type: " . json_encode($params));
                }
            } catch (\Exception $e) {
                Log::error("Failed to execute batch insert for $type: " . $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Validates a single row against the JSON Schema.
     *
     * @param array $rowData Associative array with keys ['Handle', 'Title', 'Vendor', 'Variant SKU', 'Variant Inventory Qty', 'Variant Price', 'Variant Barcode']
     * @return bool
     */
    protected function validateRow(array $rowData): bool
    {
        $expectedKeys = ['Handle', 'Title', 'Vendor', 'Variant SKU', 'Variant Inventory Qty', 'Variant Price', 'Variant Barcode'];
        if (array_diff($expectedKeys, array_keys($rowData)) !== [] || array_diff(array_keys($rowData), $expectedKeys) !== []) {
            Log::warning("Row validation failed: Invalid keys in row data");
            return false;
        }

        $rowData['Variant Inventory Qty'] = (int) $rowData['Variant Inventory Qty'];
        $rowData['Variant Price']         = (float) $rowData['Variant Price'];
        $rowDataObject = (object) $rowData;
        $this->validator->validate($rowDataObject, $this->schema);

        if (!$this->validator->isValid()) {
            Log::warning("Row validation failed: " . json_encode($this->validator->getErrors()));
            return false;
        }

        return true;
    }

    /**
     * Opens a CSV file and returns the file handle.
     *
     * @param string $csvPath
     * @return resource
     * @throws \Exception
     */
    private function openCsvFile(string $csvPath)
    {
        $csvFile = fopen($csvPath, 'r');
        if (!$csvFile) {
            throw new \Exception("Failed to open CSV file at: {$csvPath}");
        }
        return $csvFile;
    }

    /**
     * Validates the row structure before processing.
     */
    private function isValidRow(array $row, array $headers, array &$stats): bool
    {
        if (count($row) !== self::EXPECTED_HEADER_COUNT) {
            $stats['corruptedRows']++;
            Log::warning("Row invalid: Header count mismatch at row " . ($stats['corruptedRows'] + 1));
            return false;
        }

        $rowData = array_combine($headers, $row);
        if (!$this->validateRow($rowData)) {
            $stats['corruptedRows']++;
            return false;
        }

        return true;
    }

    /**
     * Handles product processing, ensuring a unique product ID is assigned.
     */
    private function processProduct(string $handle, array $rowData, array &$productBatches, array &$productIdMap, string $now): void
    {
        if (!isset($productIdMap[$handle])) {
            $productDto = new ProductDto(
                $handle,
                $rowData['Title'],
                null,
                $rowData['Vendor'],
                $now,
                $now
            );

            $productIdMap[$handle] = $productDto->id;
            $productBatches[] = $productDto->toArray();
        }
    }

    /**
     * Handles variant processing and associates it with the correct product.
     */
    private function processVariant(string $handle, array $rowData, array &$variantData, array $productIdMap, string $now): void
    {
        $variantData[] = [
            'product_id' => $productIdMap[$handle],
            'sku' => $rowData['Variant SKU'],
            'quantity' => (int) $rowData['Variant Inventory Qty'],
            'price' => (float) $rowData['Variant Price'],
            'barcode' => $rowData['Variant Barcode'] ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Flushes and inserts batched products into the database.
     * @throws \Exception
     */
    private function flushProductBatch(array &$productBatches, array &$stats): void
    {
        $this->executeBatchInsert($this->prepareProductStatement(), $productBatches, 'product');
        $stats['productsImported'] += count($productBatches);
        $productBatches = [];
    }
}
