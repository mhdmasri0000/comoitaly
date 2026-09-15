<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProductSize extends Model
{
    use HasUuid;

    protected $fillable = ['product_id', 'size_label'];
}