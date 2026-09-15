<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    use HasUuid;

    protected $fillable = [
        'id', 'user_id', 'type', 'severity', 'title', 'body', 'data',
        'ref_type', 'ref_id', 'is_read', 'read_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
}
