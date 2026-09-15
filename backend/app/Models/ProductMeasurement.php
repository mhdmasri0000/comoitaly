<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProductMeasurement extends Model
{
    use HasUuid;

    protected $table = 'product_measurements';

    protected $fillable = ['product_id', 'measurement'];
}
