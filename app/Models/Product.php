<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Class Product
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string $brand
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Collection|Variant[] $variants
 *
 * @package App\Models
 */
class Product extends Model
{
	protected $table = 'products';
	public $incrementing = false;

	protected $fillable = [
        'id',
        'handle',
		'name',
		'description',
		'brand'
	];

	public function variants()
	{
		return $this->hasMany(Variant::class);
	}
}
