<?php

namespace App\Support;

use App\Models\HeroBanner;

final class BannerSerializer
{
    public static function toArray(HeroBanner $banner): array
    {
        $payload = $banner->toArray();
        $payload['image_cover'] = MediaUrl::toRelative($banner->image_cover);
        $payload['subtitle'] = ProductSerializer::normalizeLocalized($banner->subtitle);
        $payload['heading'] = ProductSerializer::normalizeLocalized($banner->heading);
        $payload['title'] = ProductSerializer::normalizeLocalized($banner->title);
        $payload['button_text'] = ProductSerializer::normalizeLocalized($banner->button_text);

        return $payload;
    }

    /**
     * @param  iterable<int, HeroBanner>  $banners
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $banners): array
    {
        $out = [];
        foreach ($banners as $banner) {
            $out[] = self::toArray($banner);
        }

        return $out;
    }
}
