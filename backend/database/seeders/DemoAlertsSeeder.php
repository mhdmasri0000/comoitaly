<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates a fresh batch of unread dashboard notifications on every run, so
 * the alert bell can be previewed. Each run creates new orders (unique marker).
 */
class DemoAlertsSeeder extends Seeder
{
    public function run(): void
    {
        $dealer = User::where('email', 'dealer@example.com')->first()
            ?? User::where('role', 'dealer')->first();
        $customer = User::where('email', 'user@example.com')->first()
            ?? User::where('role', 'user')->first();

        $product = Product::query()->inRandomOrder()->first();

        abort_if(! $dealer || ! $customer || ! $product, 500, 'Run DatabaseSeeder first (need a dealer, a user, and a product).');

        $proofPath = 'uploads/demo-shamcash-proof.svg';
        if (! Storage::disk('public')->exists($proofPath)) {
            Storage::disk('public')->put($proofPath, $this->demoProofSvg());
        }
        $proofUrl = Storage::disk('public')->url($proofPath);
        $deliveryFee = (float) (DB::table('settings')->where('key', 'delivery_fee')->value('value') ?: 0);

        // 1) Dealer ShamCash order -> order_new + order_large + payment_proof
        $this->createOrder(
            user: $dealer,
            product: $product,
            quantity: 2,
            paymentType: 'shamcash',
            proofUrl: $proofUrl,
            deliveryFee: $deliveryFee,
        );

        // 2) Regular customer cash order -> order_new
        $this->createOrder(
            user: $customer,
            product: $product,
            quantity: 1,
            paymentType: 'cash',
            proofUrl: null,
            deliveryFee: $deliveryFee,
        );

        // 3) Stock alert -> resolve the previous one, drop stock, re-alert.
        $low = Product::where('name->en', 'Oversized Hoodie')->first()
            ?? Product::orderBy('id')->first();

        abort_if(! $low, 500, 'Run DatabaseSeeder first: no products found.');

        AdminNotifier::resolveStockAlerts($low->id);
        $low->update(['quantity' => 2]);
        AdminNotifier::syncStock($low->fresh());

        $this->command?->info('New dashboard notifications created (orders + stock). Refresh the bell.');
    }

    private function createOrder(
        User $user,
        Product $product,
        int $quantity,
        string $paymentType,
        ?string $proofUrl,
        float $deliveryFee,
    ): Order {
        $factor = $user->role === 'dealer' ? (float) ($user->dealer_price_factor ?? 1) : 1.0;
        $unitPrice = round((float) $product->price * $factor, 2);
        $subtotal = $unitPrice * $quantity;

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'delivery_address' => 'Damascus, Syria — Notifications Demo',
            'note' => 'DEMO-ALERTS-'.now()->format('YmdHis'),
            'payment_type' => $paymentType,
            'payment_proof_image' => $proofUrl,
            'status' => 'pending',
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $subtotal + $deliveryFee,
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => json_encode($product->name),
            'unit_price' => $unitPrice,
            'total_price' => $subtotal,
            'quantity' => $quantity,
            'size' => 'Standard',
            'color' => 'Default',
        ]);

        AdminNotifier::orderCreated($order, $user);

        return $order;
    }

    private function demoProofSvg(): string
    {
        $ref = 'DEMO-'.date('Ymd-His');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="720" height="420" viewBox="0 0 720 420" role="img">
  <rect width="720" height="420" fill="#eef2f7"/>
  <rect width="720" height="72" fill="#0d9488"/>
  <text x="24" y="46" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="bold" fill="#ffffff">SHAM CASH — TRANSFER RECEIPT</text>
  <text x="32" y="140" font-family="Arial, Helvetica, sans-serif" font-size="22" fill="#0f172a">Amount: 540.00 SYP</text>
  <text x="32" y="182" font-family="Arial, Helvetica, sans-serif" font-size="18" fill="#0f172a">Reference: {$ref}</text>
  <line x1="32" y1="210" x2="688" y2="210" stroke="#cbd5e1" stroke-width="1"/>
  <text x="32" y="248" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#334155">From: Samir Store (dealer@example.com)</text>
  <text x="32" y="280" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#334155">To: COM-O Store</text>
</svg>
SVG;
    }
}
