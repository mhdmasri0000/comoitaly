<?php
namespace App\Models;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
class CartItem extends Model { use HasUuid; protected $fillable = ['user_id','product_id','size','color','quantity','reserved_on','reserved_until','total_price']; protected function casts(): array { return ['quantity'=>'integer','total_price'=>'decimal:2','reserved_on'=>'date','reserved_until'=>'date']; } public function product() { return $this->belongsTo(Product::class); } }