<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo data to preview dashboard notifications and a ShamCash order with a
 * payment-proof file. Safe to run when DatabaseSeeder data already exists.
 */
class DemoDashboardSeeder extends Seeder
{
    private const ORDER_MARKER = 'DEMO-SHAMCASH-ORDER';

    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'id' => (string) Str::uuid(),
                'first_name' => 'Admin',
                'last_name' => 'User',
                'phone_number' => '0500000901',
                'password_hash' => Hash::make('123456'),
                'role' => 'admin',
            ]
        );

        if ($admin->role !== 'admin') {
            $admin->update(['role' => 'admin']);
        }

        $dealer = User::firstOrCreate(
            ['email' => 'dealer@example.com'],
            [
                'id' => (string) Str::uuid(),
                'first_name' => 'Samir',
                'last_name' => 'Store',
                'phone_number' => '0500000902',
                'password_hash' => Hash::make('123456'),
                'role' => 'dealer',
                'dealer_price_factor' => 0.8,
            ]
        );

        if ($dealer->dealer_price_factor === null) {
            $dealer->update(['dealer_price_factor' => 0.8]);
        }

        // Payment-proof file (SVG works without the GD extension).
        $proofPath = 'uploads/demo-shamcash-proof.svg';
        Storage::disk('public')->put($proofPath, $this->demoProofSvg());
        $proofUrl = Storage::disk('public')->url($proofPath);

        $existing = Order::where('note', self::ORDER_MARKER)->first();
        if ($existing) {
            $existing->update(['payment_proof_image' => $proofUrl]);
            $this->command?->info('Demo ShamCash order already exists — refreshed proof image.');

            return;
        }

        $productA = Product::where('name->en', 'Classic White T-Shirt')->first()
            ?? Product::orderBy('id')->first();

        $productB = Product::where('name->en', 'Slim Fit Jeans')->first()
            ?? Product::orderBy('id')->skip(1)->first()
            ?? $productA;

        abort_if(! $productA || ! $productB, 500, 'Run DatabaseSeeder first: no products found.');

        // Keep the second item low so the order pushes it into the low-stock alert.
        $productB->update(['quantity' => 4]);

        $factor = (float) ($dealer->dealer_price_factor ?? 1);
        $unitA = round((float) $productA->price * $factor, 2);
        $unitB = round((float) $productB->price * $factor, 2);
        $qtyA = 2;
        $qtyB = 1;
        $subtotal = $unitA * $qtyA + $unitB * $qtyB;
        $deliveryFee = (float) (DB::table('settings')->where('key', 'delivery_fee')->value('value') ?: 0);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'user_id' => $dealer->id,
            'delivery_address' => 'Damascus, Syria — Demo Street 12',
            'note' => self::ORDER_MARKER,
            'payment_type' => 'shamcash',
            'payment_proof_image' => $proofUrl,
            'status' => 'pending',
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $subtotal + $deliveryFee,
        ]);

        foreach ([[$productA, $unitA, $qtyA], [$productB, $unitB, $qtyB]] as [$product, $unit, $qty]) {
            OrderItem::create([
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => json_encode($product->name),
                'unit_price' => $unit,
                'total_price' => $unit * $qty,
                'quantity' => $qty,
                'size' => 'Standard',
                'color' => 'Default',
            ]);
        }

        // Mirror the checkout side effects: decrement stock, then raise alerts.
        $productA->decrement('quantity', $qtyA);
        $productB->decrement('quantity', $qtyB);

        AdminNotifier::orderCreated($order, $dealer);
        AdminNotifier::syncStock($productA->fresh());
        AdminNotifier::syncStock($productB->fresh());

        $this->command?->info('Demo ShamCash order created with admin alerts (order_new, order_large, payment_proof, stock_low).');
    }

    private function demoProofSvg(): string
    {
        $ref = 'DEMO-'.date('Ymd-His');
        $total = '540.00';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="720" height="420" viewBox="0 0 720 420" role="img">
  <rect width="720" height="420" fill="#eef2f7"/>
  <rect width="720" height="72" fill="#0d9488"/>
  <text x="24" y="46" font-family="Arial, Helvetica, sans-serif" font-size="22" font-weight="bold" fill="#ffffff">SHAM CASH — TRANSFER RECEIPT</text>
  <text x="32" y="140" font-family="Arial, Helvetica, sans-serif" font-size="22" fill="#0f172a">Amount: {$total} SYP</text>
  <text x="32" y="182" font-family="Arial, Helvetica, sans-serif" font-size="18" fill="#0f172a">Reference: {$ref}</text>
  <line x1="32" y1="210" x2="688" y2="210" stroke="#cbd5e1" stroke-width="1"/>
  <text x="32" y="248" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#334155">From: Samir Store (dealer@example.com)</text>
  <text x="32" y="280" font-family="Arial, Helvetica, sans-serif" font-size="16" fill="#334155">To: COM-O Store</text>
  <text x="32" y="330" font-family="Arial, Helvetica, sans-serif" font-size="14" fill="#64748b">Demo payment proof — generated for dashboard preview.</text>
</svg>
SVG;
    }
}
