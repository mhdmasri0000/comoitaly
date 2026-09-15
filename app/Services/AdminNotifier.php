<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductSerializer;
use Illuminate\Support\Facades\DB;

class AdminNotifier
{
    public const TYPE_ORDER_NEW = 'order_new';
    public const TYPE_ORDER_LARGE = 'order_large';
    public const TYPE_PAYMENT_PROOF = 'payment_proof';
    public const TYPE_STOCK_LOW = 'stock_low';
    public const TYPE_STOCK_OUT = 'stock_out';

    public static function lowStockThreshold(): int
    {
        $value = DB::table('settings')->where('key', 'low_stock_threshold')->value('value');

        return $value !== null && $value !== '' ? (int) $value : 5;
    }

    public static function largeOrderAmount(): float
    {
        $value = DB::table('settings')->where('key', 'large_order_amount')->value('value');

        return $value !== null && $value !== '' ? (float) $value : 1000.0;
    }

    public static function orderCreated(Order $order, ?User $user): void
    {
        $isDealer = $user?->role === 'dealer';
        $total = (float) $order->total;
        $isLarge = $isDealer || $total >= self::largeOrderAmount();
        $customer = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->email ?? 'Customer');
        $short = strtoupper(substr($order->id, 0, 8));

        $baseData = [
            'module' => 'orders',
            'order_id' => $order->id,
            'total' => $total,
            'user_id' => $order->user_id,
            'customer' => $customer,
            'is_dealer' => $isDealer,
            'payment_type' => $order->payment_type,
        ];

        app(DomainNotificationService::class)->notifyAdmins([
            'type' => self::TYPE_ORDER_NEW,
            'severity' => 'info',
            'title' => 'New order',
            'body' => "#{$short} · {$customer} · ".number_format($total, 2),
            'data' => array_merge($baseData, ['action' => 'order.created', 'event' => self::TYPE_ORDER_NEW]),
            'ref_type' => 'order',
            'ref_id' => $order->id,
        ]);

        if ($isLarge) {
            app(DomainNotificationService::class)->notifyAdmins([
                'type' => self::TYPE_ORDER_LARGE,
                'severity' => 'warning',
                'title' => $isDealer ? 'New dealer order' : 'Large order',
                'body' => "#{$short} · {$customer} · ".number_format($total, 2),
                'data' => array_merge($baseData, ['action' => 'order.large', 'event' => self::TYPE_ORDER_LARGE]),
                'ref_type' => 'order',
                'ref_id' => $order->id,
            ]);
        }

        if ($order->payment_type === 'shamcash') {
            app(DomainNotificationService::class)->notifyAdmins([
                'type' => self::TYPE_PAYMENT_PROOF,
                'severity' => 'warning',
                'title' => 'Payment proof needs review',
                'body' => "#{$short} · {$customer} · ".number_format($total, 2),
                'data' => array_merge($baseData, ['action' => 'order.payment_proof', 'event' => self::TYPE_PAYMENT_PROOF]),
                'ref_type' => 'order',
                'ref_id' => $order->id,
            ]);
        }
    }

    public static function syncStock(Product $product): void
    {
        $threshold = self::lowStockThreshold();
        $quantity = (int) $product->quantity;

        if ($quantity > $threshold) {
            self::resolveStockAlerts($product->id);

            return;
        }

        $type = $quantity <= 0 ? self::TYPE_STOCK_OUT : self::TYPE_STOCK_LOW;
        $severity = $quantity <= 0 ? 'critical' : 'warning';
        $title = $quantity <= 0 ? 'Out of stock' : 'Low stock';
        $name = self::productName($product);
        $body = "{$name} · {$quantity} left";
        $data = [
            'module' => 'products',
            'action' => $quantity <= 0 ? 'stock.out' : 'stock.low',
            'event' => $type,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'threshold' => $threshold,
        ];

        /** @var AdminNotification|null $existing */
        $existing = AdminNotification::where('ref_type', 'product')
            ->where('ref_id', $product->id)
            ->whereIn('type', [self::TYPE_STOCK_LOW, self::TYPE_STOCK_OUT])
            ->whereNull('resolved_at')
            ->latest()
            ->first();

        if ($existing) {
            if ($existing->type !== $type) {
                AdminNotification::where('ref_type', 'product')
                    ->where('ref_id', $product->id)
                    ->whereIn('type', [self::TYPE_STOCK_LOW, self::TYPE_STOCK_OUT])
                    ->whereNull('resolved_at')
                    ->update([
                        'type' => $type,
                        'severity' => $severity,
                        'title' => $title,
                        'body' => $body,
                        'is_read' => false,
                        'read_at' => null,
                    ]);
            }

            return;
        }

        app(DomainNotificationService::class)->notifyAdmins([
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'ref_type' => 'product',
            'ref_id' => $product->id,
        ]);
    }

    public static function resolveStockAlerts(string $productId): void
    {
        AdminNotification::where('ref_type', 'product')
            ->where('ref_id', $productId)
            ->whereIn('type', [self::TYPE_STOCK_LOW, self::TYPE_STOCK_OUT])
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    protected static function productName(Product $product): string
    {
        $name = ProductSerializer::normalizeLocalized($product->name);

        return $name['en'] !== '' ? $name['en'] : ($name['ar'] !== '' ? $name['ar'] : 'Product');
    }
}
