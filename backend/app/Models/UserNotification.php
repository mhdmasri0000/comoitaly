<?php
namespace App\Models;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
class UserNotification extends Model { use HasUuid; protected $table = 'notifications'; protected $fillable = ['id','user_id','title','body','type','ref_id','is_read']; protected function casts(): array { return ['is_read'=>'boolean']; } }