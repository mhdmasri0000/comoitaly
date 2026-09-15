<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProductTag extends Model
{
    use HasUuid;

    protected $table = 'product_tags';

    protected $fillable = ['product_id', 'tag'];
}
