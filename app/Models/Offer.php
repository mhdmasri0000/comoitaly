<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    use HasUuid;

    protected $fillable = [
        'id', 'title', 'subtitle', 'heading', 'button_text', 'image_cover',
        'button_link', 'discount_percent', 'starts_at', 'ends_at', 'is_active', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'subtitle' => 'array',
            'heading' => 'array',
            'button_text' => 'array',
            'discount_percent' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'offer_product')
            ->withPivot('discount_percent')
            ->withTimestamps();
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
    }

    public function scopeCurrentlyActive($query)
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
