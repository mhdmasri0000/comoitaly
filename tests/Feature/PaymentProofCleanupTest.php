<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentProofCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        static $counter = 0;
        $counter++;

        return User::create([
            'id' => (string) Str::uuid(),
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $role.$counter.'@example.com',
            'phone_number' => '0540000'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
            'password_hash' => Hash::make('1234567890'),
            'role' => $role,
        ]);
    }

    private function makeOrder(User $user, string $proofPath, ?string $createdAt = null): Order
    {
        Storage::disk('public')->put($proofPath, 'fake-proof');

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'delivery_address' => 'Damascus',
            'payment_type' => 'shamcash',
            'payment_proof_image' => Storage::disk('public')->url($proofPath),
            'status' => 'pending',
            'subtotal' => 100,
            'delivery_fee' => 0,
            'total' => 100,
        ]);

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $order;
    }

    public function test_delivering_an_order_deletes_the_payment_proof(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser('user');
        $order = $this->makeOrder($customer, 'uploads/proof-delivered.png');

        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'delivered'])->assertOk();

        $this->assertNull($order->fresh()->payment_proof_image);
        $this->assertFalse(Storage::disk('public')->exists('uploads/proof-delivered.png'));
    }

    public function test_cancelling_an_order_deletes_the_payment_proof(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser('user');
        $order = $this->makeOrder($customer, 'uploads/proof-cancelled.png');

        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/admin/orders/'.$order->id.'/status', [
            'status' => 'cancelled',
            'cancel_reason' => 'Out of stock',
        ])->assertOk();

        $this->assertNull($order->fresh()->payment_proof_image);
        $this->assertFalse(Storage::disk('public')->exists('uploads/proof-cancelled.png'));
    }

    public function test_purge_command_removes_proofs_older_than_retention(): void
    {
        $customer = $this->makeUser('user');
        $old = $this->makeOrder($customer, 'uploads/proof-old.png', now()->subDays(11)->toDateTimeString());
        $recent = $this->makeOrder($customer, 'uploads/proof-recent.png', now()->subDays(2)->toDateTimeString());

        $this->artisan('orders:purge-payment-proofs')->assertExitCode(0);

        $this->assertNull($old->fresh()->payment_proof_image);
        $this->assertFalse(Storage::disk('public')->exists('uploads/proof-old.png'));

        $this->assertNotNull($recent->fresh()->payment_proof_image);
        $this->assertTrue(Storage::disk('public')->exists('uploads/proof-recent.png'));
    }
}
