<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes ShamCash payment-proof files once they are no longer needed, so they
 * do not keep consuming disk space in production.
 */
class PaymentProofCleaner
{
    public const SETTING_KEY = 'payment_proof_retention_days';

    public static function retentionDays(): int
    {
        $value = DB::table('settings')->where('key', self::SETTING_KEY)->value('value');

        return $value !== null && $value !== '' ? max(0, (int) $value) : 10;
    }

    /**
     * Delete the proof file (when stored locally) and clear the DB reference.
     */
    public function clear(Order $order): bool
    {
        $url = trim((string) ($order->payment_proof_image ?? ''));

        if ($url === '') {
            return false;
        }

        $this->deleteFile($url);
        $order->forceFill(['payment_proof_image' => null])->saveQuietly();

        return true;
    }

    public function deleteFile(string $url): void
    {
        $path = $this->storagePath($url);

        if ($path !== null && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Purge proofs of orders created before the retention window.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = now()->subDays(max(0, $days));
        $count = 0;

        Order::query()
            ->whereNotNull('payment_proof_image')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    if ($this->clear($order)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function storagePath(string $url): ?string
    {
        if (preg_match('#/storage/(.+)$#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
