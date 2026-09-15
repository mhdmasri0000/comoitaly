<?php
namespace App\Models;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
class Favorite extends Model { use HasUuid; protected $fillable = ['user_id', 'product_id']; public function product() { return $this->belongsTo(Product::class); } }