<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    use HasUuid;

    protected $fillable = ['product_id', 'image_url', 'color_name', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}