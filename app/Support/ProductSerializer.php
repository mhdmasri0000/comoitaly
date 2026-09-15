<?php

namespace App\Support;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

final class ProductSerializer
{
    public static function toArray(Product $product, ?User $user = null): array
    {
        $product->loadMissing(['category', 'images', 'sizes', 'colors', 'measurements', 'tags']);

        $images = $product->images;
        $cover = $images->firstWhere('is_primary', true)?->image_url
            ?? $images->first()?->image_url;

        $gallery = $images
            ->reject(fn ($image) => (bool) $image->is_primary)
            ->pluck('image_url')
            ->values()
            ->all();

        // If nothing marked primary but we already used first as cover, drop it from gallery
        if ($cover && ! $images->contains(fn ($image) => (bool) $image->is_primary)) {
            $gallery = array_values(array_filter($gallery, fn ($url) => $url !== $cover));
        }

        $payload = $product->toArray();
        $payload['name'] = self::normalizeLocalized($product->name);

        $basePrice = (float) $product->price;
        $factor = ProductPricing::factorFor($user);
        $offer = $user && $user->role === 'admin' ? null : ProductPricing::activeOffer($product);

        $payload['price'] = self::formatPrice(ProductPricing::unitPrice($product, $user, $offer));
        $payload['on_offer'] = $offer !== null;

        if ($offer !== null) {
            $payload['offer_id'] = $offer['offer_id'];
            $payload['discount_percent'] = $offer['discount_percent'];
            $payload['offer_price'] = self::formatPrice($offer['offer_price']);
            $payload['base_price'] = self::formatPrice($basePrice);
        }

        if ($factor !== 1.0) {
            $payload['base_price'] = self::formatPrice($basePrice);
            $payload['price_factor'] = $factor;
        }
        $payload['description'] = self::normalizeLocalized($product->description);
        if ($product->relationLoaded('category') && $product->category) {
            $payload['category'] = array_merge(
                $product->category->toArray(),
                ['name' => self::normalizeLocalized($product->category->name)]
            );
        }
        $payload['cover'] = MediaUrl::toRelative($cover);
        $payload['gallery'] = array_values(array_map(
            fn ($url) => MediaUrl::toRelative((string) $url),
            $gallery
        ));
        $payload['images'] = $images->map(fn ($image) => [
            'id' => $image->id,
            'image_url' => MediaUrl::toRelative($image->image_url),
            'color_name' => $image->color_name,
            'is_primary' => (bool) $image->is_primary,
        ])->values()->all();
        $payload['sizes'] = $product->sizes->map(fn ($size) => [
            'size_label' => $size->size_label,
        ])->values()->all();
        $payload['colors'] = $product->colors->map(function ($color) use ($images) {
            $colorName = trim((string) $color->color_name);
            $colorImages = $colorName === ''
                ? []
                : $images
                    ->filter(fn ($image) => strcasecmp(trim((string) $image->color_name), $colorName) === 0)
                    ->map(fn ($image) => MediaUrl::toRelative($image->image_url))
                    ->values()
                    ->all();

            return [
                'color_name' => $color->color_name,
                'color_hex' => $color->color_hex,
                'images' => $colorImages,
            ];
        })->values()->all();
        $payload['measurements'] = $product->measurements->map(function ($measurement) {
            $raw = trim((string) ($measurement->measurement ?? ''));

            if ($raw === '') {
                return ['label' => '', 'value' => ''];
            }

            if (str_contains($raw, ':')) {
                [$label, $value] = array_map('trim', explode(':', $raw, 2));
                $label = $label !== '' ? $label : 'measurement';
                return [
                    'label' => $label,
                    'value' => $value !== '' ? $value : $raw,
                ];
            }

            return [
                'label' => $raw,
                'value' => $raw,
            ];
        })->values()->all();
        $payload['tags'] = $product->tags->pluck('tag')->values()->all();

        return $payload;
    }

    public static function collection(iterable $products, ?User $user = null): array
    {
        return Collection::make($products)
            ->map(fn (Product $product) => self::toArray($product, $user))
            ->values()
            ->all();
    }

    public static function formatPrice(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    public static function normalizeLocalized(mixed $value): array
    {
        $guard = 0;
        while (is_string($value) && $guard < 5) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['en' => $value, 'ar' => $value];
            }
            $value = $decoded;
            $guard++;
        }

        if (! is_array($value)) {
            return ['en' => '', 'ar' => ''];
        }

        $en = $value['en'] ?? '';
        $ar = $value['ar'] ?? '';

        if (is_array($en)) {
            $en = $en['en'] ?? $en['ar'] ?? '';
        }
        if (is_array($ar)) {
            $ar = $ar['ar'] ?? $ar['en'] ?? '';
        }

        if (is_string($en) && str_starts_with(trim($en), '{')) {
            $decodedEn = json_decode($en, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedEn)) {
                $en = $decodedEn['en'] ?? $decodedEn['ar'] ?? $en;
            }
        }
        if (is_string($ar) && str_starts_with(trim($ar), '{')) {
            $decodedAr = json_decode($ar, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAr)) {
                $ar = $decodedAr['ar'] ?? $decodedAr['en'] ?? $ar;
            }
        }

        return [
            'en' => is_scalar($en) ? trim((string) $en) : '',
            'ar' => is_scalar($ar) ? trim((string) $ar) : '',
        ];
    }
}
