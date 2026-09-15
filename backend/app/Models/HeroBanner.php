<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HeroBanner extends Model
{
    use HasUuid;

    public const TYPE_BANNER = 'banner';

    public const TYPE_OFFER = 'offer';

    protected $fillable = [
        'type',
        'image_cover',
        'subtitle',
        'heading',
        'title',
        'button_text',
        'button_link',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'subtitle' => 'array',
            'heading' => 'array',
            'title' => 'array',
            'button_text' => 'array',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeBanners(Builder $query): Builder
    {
        return $query->ofType(self::TYPE_BANNER);
    }

    public function scopeOffers(Builder $query): Builder
    {
        return $query->ofType(self::TYPE_OFFER);
    }
}
