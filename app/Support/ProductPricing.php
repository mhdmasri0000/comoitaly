<?php

namespace App\Support;

use App\Models\Product;
use App\Models\User;

final class ProductPricing
{
    /**
     * Price a single unit for the given user, applying the best active offer
     * and then the dealer factor. Admins always see the base price.
     */
    public static function priceFor(Product $product, ?User $user): float
    {
        return self::unitPrice($product, $user);
    }

    public static function unitPrice(Product $product, ?User $user, ?array $offer = null): float
    {
        if ($user && $user->role === 'admin') {
            return round((float) $product->price, 2);
        }

        $offer ??= self::activeOffer($product);
        $price = $offer ? $offer['offer_price'] : (float) $product->price;

        return round($price * self::factorFor($user), 2);
    }

    /**
     * Best (lowest) active offer price for a product, or null when no
     * active offer applies. Uses the per-product discount override when set.
     *
     * @return array{offer_id: string, discount_percent: int, offer_price: float}|null
     */
    public static function activeOffer(Product $product): ?array
    {
        $offers = $product->relationLoaded('offers') ? $product->offers : $product->offers()->get();
        $best = null;

        foreach ($offers as $offer) {
            if (! $offer->isCurrentlyActive()) {
                continue;
            }

            $override = $offer->pivot?->discount_percent;
            $percent = $override !== null ? (int) $override : (int) $offer->discount_percent;

            if ($percent <= 0) {
                continue;
            }

            $offerPrice = round((float) $product->price * (1 - $percent / 100), 2);

            if ($best === null || $offerPrice < $best['offer_price']) {
                $best = [
                    'offer_id' => $offer->id,
                    'discount_percent' => $percent,
                    'offer_price' => $offerPrice,
                ];
            }
        }

        return $best;
    }

    /**
     * Returns the multiplier applied to the base price. Non-dealers and
     * dealers without a factor pay the full price (1.0).
     */
    public static function factorFor(?User $user): float
    {
        if (! $user || $user->role !== 'dealer') {
            return 1.0;
        }

        $factor = $user->dealer_price_factor;

        if ($factor === null || $factor === '') {
            return 1.0;
        }

        return (float) $factor;
    }
}
