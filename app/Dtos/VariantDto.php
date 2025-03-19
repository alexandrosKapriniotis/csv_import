<?php

namespace App\Dtos;

use Illuminate\Support\Str;

class VariantDto
{
    public string $id;
    public string $sku;
    public string $productId;
    public int $quantity;
    public float $price;
    public ?string $barcode;
    public string $status;
    public string $createdAt;
    public string $updatedAt;

    public function __construct(string $sku, string $productId, string $quantity, string $price, ?string $barcode, string $createdAt, string $updatedAt)
    {
        $this->id = (string) Str::uuid();
        $this->sku = $sku;
        $this->productId = $productId;
        $this->quantity = (int) $quantity;
        $this->price = (float) $price;
        $this->barcode = $barcode;
        $this->status = $this->determineStockStatus();
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    private function determineStockStatus(): string
    {
        return match (true) {
            $this->quantity > 10 => 'in_stock',
            $this->quantity > 0 && $this->quantity <= 10 => 'low_stock',
            default => 'out_of_stock',
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'barcode' => $this->barcode,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private function determineStatus(): string
    {
        if ($this->quantity > 10) {
            return 'in_stock';
        } elseif ($this->quantity > 0) {
            return 'low_stock';
        } else {
            return 'out_of_stock';
        }
    }
}
