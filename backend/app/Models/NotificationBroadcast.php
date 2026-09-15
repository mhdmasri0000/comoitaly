<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class NotificationBroadcast extends Model
{
    use HasUuid;

    protected $fillable = [
        'id',
        'title',
        'body',
        'type',
        'platform',
        'audience',
        'status',
        'recipients',
        'push_sent',
        'ref_id',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'integer',
            'push_sent' => 'integer',
        ];
    }
}
