<?php
namespace App\Models;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
class OrderItem extends Model { use HasUuid; protected $fillable = ['order_id','product_id','product_name','unit_price','total_price','quantity','size','color']; protected function casts(): array { return ['unit_price'=>'decimal:2','total_price'=>'decimal:2','quantity'=>'integer']; } public function product() { return $this->belongsTo(Product::class); } }