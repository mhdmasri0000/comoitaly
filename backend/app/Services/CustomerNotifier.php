<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Str;

/**
 * Sends in-app notifications (and an FCM push when possible) to the customer
 * who owns an order, written in the customer's preferred language.
 */
class CustomerNotifier
{
    public function __construct(private FirebaseCloudMessaging $fcm)
    {
    }

    public function orderStatusChanged(Order $order, ?string $previousStatus): void
    {
        $status = $order->status;

        if ($status === $previousStatus) {
            return;
        }

        $customer = User::find($order->user_id);

        if (! $customer) {
            return;
        }

        $language = in_array($customer->language, ['ar', 'en'], true) ? $customer->language : 'ar';
        $message = $this->messageFor($order, $status, $language);

        if ($message === null) {
            return;
        }

        [$title, $body, $type] = $message;

        UserNotification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'ref_id' => $order->id,
            'is_read' => false,
        ]);

        if ($customer->fcm_token && $this->fcm->isConfigured()) {
            $result = $this->fcm->sendToToken($customer->fcm_token, $title, $body, [
                'type' => $type,
                'ref_id' => $order->id,
                'status' => $status,
                'lang' => $language,
            ]);

            if (! empty($result['invalid_token'])) {
                $customer->update(['fcm_token' => null, 'fcm_platform' => null]);
            }
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function messageFor(Order $order, string $status, string $language): ?array
    {
        $short = strtoupper(substr($order->id, 0, 8));
        $reason = trim((string) ($order->cancel_reason ?? ''));

        if ($language === 'en') {
            return match ($status) {
                'processing' => ['Order processing started', "Your order #{$short} is now being processed.", 'order_processing'],
                'shipped' => ['Your order is on the way', "Your order #{$short} is out for delivery.", 'order_shipped'],
                'delivered' => ['Order delivered', "Your order #{$short} has been delivered. Thank you!", 'order_delivered'],
                'cancelled' => [
                    'Order cancelled',
                    $reason !== ''
                        ? "Your order #{$short} was cancelled. Reason: {$reason}"
                        : "Your order #{$short} was cancelled.",
                    'order_cancelled',
                ],
                default => null,
            };
        }

        return match ($status) {
            'processing' => ['تم بدء معالجة طلبك', "طلبك #{$short} قيد المعالجة الآن.", 'order_processing'],
            'shipped' => ['طلبك في الطريق', "طلبك #{$short} قيد التوصيل إليك.", 'order_shipped'],
            'delivered' => ['تم تسليم طلبك', "تم تسليم طلبك #{$short}. شكراً لك!", 'order_delivered'],
            'cancelled' => [
                'تم إلغاء طلبك',
                $reason !== ''
                    ? "تم إلغاء طلبك #{$short}. السبب: {$reason}"
                    : "تم إلغاء طلبك #{$short}.",
                'order_cancelled',
            ],
            default => null,
        };
    }
}
