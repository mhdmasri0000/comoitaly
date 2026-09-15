<?php

namespace App\Support;

use App\Models\AdminNotification;
use Illuminate\Support\Collection;

final class AdminNotificationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(AdminNotification $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'user_id' => $notification->user_id,
            'type' => $notification->type,
            'severity' => $notification->severity,
            'title' => $notification->title,
            'title_translated' => $notification->title,
            'body' => $notification->body,
            'data' => $data,
            'module' => $data['module'] ?? null,
            'action' => $data['action'] ?? null,
            'ref_type' => $notification->ref_type,
            'ref_id' => $notification->ref_id,
            'is_read' => (bool) $notification->is_read,
            'read_at' => optional($notification->read_at)->toISOString(),
            'resolved_at' => optional($notification->resolved_at)->toISOString(),
            'created_at' => optional($notification->created_at)->toISOString(),
            'updated_at' => optional($notification->updated_at)->toISOString(),
        ];
    }

    /**
     * @param  iterable<int, AdminNotification>  $notifications
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $notifications): array
    {
        return Collection::make($notifications)
            ->map(fn (AdminNotification $notification) => self::toArray($notification))
            ->values()
            ->all();
    }
}
