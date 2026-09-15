<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

final class OfferSerializer
{
    /**
     * Admin shape: content plus a compact product list with effective discounts
     * (used to prefill the dashboard editor).
     */
    public static function toArray(Offer $offer): array
    {
        $payload = $offer->toArray();
        $payload['image_cover'] = MediaUrl::toRelative($offer->image_cover);

        foreach (['title', 'subtitle', 'heading', 'button_text'] as $field) {
            $payload[$field] = ProductSerializer::normalizeLocalized($offer->$field);
        }

        $payload['is_currently_active'] = $offer->isCurrentlyActive();

        $offer->loadMissing('products');
        $payload['products'] = $offer->products->map(function (Product $product) use ($offer) {
            return [
                'id' => $product->id,
                'name' => ProductSerializer::normalizeLocalized($product->name),
                'price' => ProductSerializer::formatPrice((float) $product->price),
                'discount_percent' => self::percentFor($offer, $product),
                'offer_price' => ProductSerializer::formatPrice(self::offerPrice($offer, $product)),
            ];
        })->values()->all();
        $payload['products_count'] = count($payload['products']);

        return $payload;
    }

    public static function collection(iterable $offers): array
    {
        return Collection::make($offers)
            ->map(fn (Offer $offer) => self::toArray($offer))
            ->values()
            ->all();
    }

    /**
     * Public shape: content plus full product cards (prices already reflect the
     * offer and the requesting user's dealer factor).
     */
    public static function toPublicArray(Offer $offer, ?User $user = null): array
    {
        $payload = self::toArray($offer);

        $offer->loadMissing([
            'products.category', 'products.images', 'products.sizes',
            'products.colors', 'products.measurements', 'products.tags', 'products.offers',
        ]);
        $payload['products'] = ProductSerializer::collection($offer->products, $user);

        return $payload;
    }

    public static function publicCollection(iterable $offers, ?User $user = null): array
    {
        return Collection::make($offers)
            ->map(fn (Offer $offer) => self::toPublicArray($offer, $user))
            ->values()
            ->all();
    }

    public static function percentFor(Offer $offer, Product $product): int
    {
        $override = $product->pivot?->discount_percent;

        return $override !== null ? (int) $override : (int) $offer->discount_percent;
    }

    public static function offerPrice(Offer $offer, Product $product): float
    {
        $percent = self::percentFor($offer, $product);

        if ($percent <= 0) {
            return round((float) $product->price, 2);
        }

        return round((float) $product->price * (1 - $percent / 100), 2);
    }
}
