<?php

namespace App\Support;

use App\Models\Category;
use App\Models\User;

final class CategorySerializer
{
    public static function toArray(Category $category, bool $withProducts = false, ?User $user = null): array
    {
        $payload = $category->toArray();
        $payload['image_cover'] = MediaUrl::toRelative($category->image_cover);
        $payload['name'] = ProductSerializer::normalizeLocalized($category->name);
        $payload['products_count'] = (int) ($category->products_count ?? $category->products()->count());

        if ($withProducts) {
            $category->loadMissing(['products.category', 'products.images', 'products.sizes', 'products.colors', 'products.measurements', 'products.tags', 'products.offers']);
            $payload['products'] = ProductSerializer::collection($category->products, $user);
        } else {
            unset($payload['products']);
        }

        return $payload;
    }

    /**
     * @param  iterable<int, Category>  $categories
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $categories, ?User $user = null): array
    {
        $out = [];
        foreach ($categories as $category) {
            $out[] = self::toArray($category, false, $user);
        }

        return $out;
    }
}
