<?php

namespace App\Support;

final class MediaUrl
{
    /**
     * Return a storage path relative to the server root (e.g. "/storage/uploads/x.jpg")
     * for files we host, so clients resolve them against their own base URL.
     * External URLs (Unsplash, CDNs, ...) are returned unchanged.
     */
    public static function toRelative(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (preg_match('#/storage/(.+)$#', $trimmed, $matches)) {
            return '/storage/'.ltrim($matches[1], '/');
        }

        return $trimmed;
    }
}
