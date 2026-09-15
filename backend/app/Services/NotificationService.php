<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Paginated inbox for a single user.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForUser(User $user, array $filters = [], int $perPage = 15)
    {
        $query = AdminNotification::query()->where('user_id', $user->id)->latest();

        if (! empty($filters['unread'])) {
            $query->where('is_read', false);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return AdminNotification::where('user_id', $user->id)->where('is_read', false)->count();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(string $userId, array $payload): AdminNotification
    {
        return AdminNotification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'type' => $payload['type'] ?? 'info',
            'severity' => $payload['severity'] ?? 'info',
            'title' => $payload['title'] ?? 'Notification',
            'body' => $payload['body'] ?? null,
            'data' => $payload['data'] ?? null,
            'ref_type' => $payload['ref_type'] ?? null,
            'ref_id' => $payload['ref_id'] ?? null,
            'is_read' => false,
        ]);
    }

    /**
     * @param  array<int, string>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function createMany(array $userIds, array $payload): int
    {
        $count = 0;

        foreach (array_unique($userIds) as $userId) {
            $this->create($userId, $payload);
            $count++;
        }

        return $count;
    }

    public function markAsRead(AdminNotification $notification): AdminNotification
    {
        if (! $notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return $notification;
    }

    public function markAllAsRead(User $user): int
    {
        return AdminNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    public function delete(AdminNotification $notification): void
    {
        $notification->delete();
    }
}
