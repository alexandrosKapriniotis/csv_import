<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Class Variant
 *
 * @property string $id
 * @property string $sku
 * @property int $quantity
 * @property float $price
 * @property string $barcode
 * @property string $product_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Product $product
 *
 * @package App\Models
 */
class Variant extends Model
{
	protected $table = 'variants';
	public $incrementing = false;

	protected $casts = [
		'quantity' => 'int',
		'price' => 'float'
	];

	protected $fillable = [
        'id',
		'sku',
		'quantity',
		'price',
		'barcode',
		'product_id',
        'status'
	];

	public function product()
	{
		return $this->belongsTo(Product::class);
	}
}
