<?php

namespace App\Dtos;

use Illuminate\Support\Str;

class ProductDto
{
    public string $id;
    public string $handle;
    public string $name;
    public ?string $description;
    public string $brand;
    public string $createdAt;
    public string $updatedAt;

    public function __construct(string $handle, string $name, ?string $description, string $brand, string $createdAt, string $updatedAt)
    {
        $this->id = (string) Str::uuid();
        $this->handle = $handle;
        $this->name = $name;
        $this->brand = $brand;
        $this->description = $description;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $this->name,
            'description' => null,
            'brand' => $this->brand,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
