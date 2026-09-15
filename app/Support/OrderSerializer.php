<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Collection;

final class OrderSerializer
{
    /**
     * @param  bool  $fullProducts  true => full product card (mobile), false => light product (dashboard list)
     */
    public static function toArray(Order $order, ?User $user = null, bool $fullProducts = true): array
    {
        $payload = $order->toArray();
        $payload['payment_proof_image'] = MediaUrl::toRelative($order->payment_proof_image);
        $payload['items'] = $order->items->map(function (OrderItem $item) use ($user, $fullProducts) {
            $row = $item->toArray();
            $row['product_name'] = ProductSerializer::normalizeLocalized($item->product_name);

            if (! $item->relationLoaded('product') || ! $item->product) {
                $row['product'] = null;

                return $row;
            }

            if ($fullProducts) {
                $row['product'] = ProductSerializer::toArray($item->product, $user);

                return $row;
            }

            $images = $item->product->relationLoaded('images') ? $item->product->images : collect();
            $cover = $images->firstWhere('is_primary', true)?->image_url ?? $images->first()?->image_url;

            $row['product'] = [
                'id' => $item->product->id,
                'name' => ProductSerializer::normalizeLocalized($item->product->name),
                'cover' => MediaUrl::toRelative($cover),
            ];

            return $row;
        })->values()->all();

        return $payload;
    }

    /**
     * @param  iterable<int, Order>  $orders
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $orders, ?User $user = null, bool $fullProducts = true): array
    {
        return Collection::make($orders)
            ->map(fn (Order $order) => self::toArray($order, $user, $fullProducts))
            ->values()
            ->all();
    }
}
