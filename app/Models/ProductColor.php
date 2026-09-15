<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProductColor extends Model
{
    use HasUuid;

    protected $fillable = ['product_id', 'color_name', 'color_hex'];
}