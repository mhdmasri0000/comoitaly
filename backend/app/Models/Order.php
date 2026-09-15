<?php
namespace App\Models;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
class Order extends Model { use HasUuid; protected $fillable = ['user_id','delivery_address','note','cancel_reason','payment_type','payment_proof_image','status','delivery_method','subtotal','delivery_fee','total']; protected function casts(): array { return ['subtotal'=>'decimal:2','delivery_fee'=>'decimal:2','total'=>'decimal:2']; } public function items() { return $this->hasMany(OrderItem::class); } public function user() { return $this->belongsTo(User::class); } }