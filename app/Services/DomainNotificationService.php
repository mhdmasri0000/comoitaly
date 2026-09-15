<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\User;

/**
 * Domain-side entry point used by internal code to raise dashboard
 * notifications. The dashboard never calls these directly.
 */
class DomainNotificationService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function notify(string $userId, array $payload): AdminNotification
    {
        return $this->notifications->create($userId, $payload);
    }

    /**
     * @param  array<int, string>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function notifyMany(array $userIds, array $payload): int
    {
        return $this->notifications->createMany($userIds, $payload);
    }

    /**
     * Fan out to every admin account (the per-admin dashboard inbox).
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifyAdmins(array $payload): int
    {
        return $this->notifications->createMany($this->adminIds(), $payload);
    }

    /**
     * @return array<int, string>
     */
    public function adminIds(): array
    {
        return User::query()->where('role', 'admin')->pluck('id')->all();
    }

    /**
     * Guard against repeating the same reminder for the same user on the same
     * day by matching keys inside `data`.
     *
     * @param  array<string, mixed>  $dataKeys
     */
    public function alreadyNotifiedToday(string $userId, array $dataKeys): bool
    {
        return AdminNotification::query()
            ->where('user_id', $userId)
            ->whereDate('created_at', now()->toDateString())
            ->get()
            ->contains(function (AdminNotification $notification) use ($dataKeys) {
                $data = $notification->data ?? [];

                foreach ($dataKeys as $key => $value) {
                    if (($data[$key] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            });
    }

    /**
     * @param  array<string, mixed>  $dataKeys
     * @param  array<string, mixed>  $payload
     */
    public function notifyOnceToday(string $userId, array $dataKeys, array $payload): ?AdminNotification
    {
        if ($this->alreadyNotifiedToday($userId, $dataKeys)) {
            return null;
        }

        return $this->notify($userId, $payload);
    }
}
