<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasUuid;

    protected $fillable = ['category_id', 'name', 'price', 'quantity', 'min_order_quantity', 'rating', 'description', 'is_featured', 'is_offer'];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'price' => 'decimal:2', 'rating' => 'float', 'quantity' => 'integer', 'min_order_quantity' => 'integer', 'is_featured' => 'boolean', 'is_offer' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(ProductSize::class);
    }

    public function colors(): HasMany
    {
        return $this->hasMany(ProductColor::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(ProductMeasurement::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ProductTag::class);
    }

    public function offers()
    {
        return $this->belongsToMany(Offer::class, 'offer_product')
            ->withPivot('discount_percent')
            ->withTimestamps();
    }
}