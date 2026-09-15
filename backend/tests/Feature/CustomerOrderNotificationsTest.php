<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerOrderNotificationsTest extends TestCase
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
            'phone_number' => '0530000'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
            'password_hash' => Hash::make('1234567890'),
            'role' => $role,
        ]);
    }

    private function makeProduct(): Product
    {
        $category = Category::create([
            'id' => (string) Str::uuid(),
            'name' => ['en' => 'Gear', 'ar' => 'عدة'],
            'image_cover' => 'https://example.com/cat.jpg',
        ]);

        return Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Item', 'ar' => 'منتج'],
            'description' => ['en' => 'desc', 'ar' => 'وصف'],
            'price' => 100,
            'quantity' => 20,
            'min_order_quantity' => 1,
            'is_featured' => false,
            'is_offer' => false,
        ]);
    }

    public function test_status_change_notifies_customer_and_cancellation_keeps_the_reason(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser('user');
        $product = $this->makeProduct();

        $this->actingAs($customer, 'sanctum');
        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $checkout = $this->postJson('/api/orders/checkout', [
            'delivery_address' => 'Damascus',
            'payment_type' => 'cash',
        ]);
        $checkout->assertCreated();

        $orderId = $checkout->json('data.id');
        $this->assertDatabaseMissing('notifications', ['user_id' => $customer->id, 'ref_id' => $orderId]);

        $this->actingAs($admin, 'sanctum');

        $this->patchJson('/api/admin/orders/'.$orderId.'/status', ['status' => 'processing'])->assertOk();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'ref_id' => $orderId,
            'type' => 'order_processing',
        ]);

        $this->patchJson('/api/admin/orders/'.$orderId.'/status', ['status' => 'shipped'])->assertOk();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'ref_id' => $orderId,
            'type' => 'order_shipped',
        ]);

        $this->patchJson('/api/admin/orders/'.$orderId.'/status', [
            'status' => 'cancelled',
            'cancel_reason' => 'Customer changed their mind',
        ])->assertOk();

        $order = Order::findOrFail($orderId);
        $this->assertSame('Customer changed their mind', $order->cancel_reason);

        $cancelNotification = UserNotification::where('ref_id', $orderId)
            ->where('type', 'order_cancelled')
            ->first();
        $this->assertNotNull($cancelNotification);
        $this->assertStringContainsString('Customer changed their mind', (string) $cancelNotification->body);

        // Stock is restored on cancellation.
        $this->assertSame(20, $product->fresh()->quantity);
    }

    public function test_customer_notifications_follow_the_customer_language(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser('user');
        $customer->update(['language' => 'en']);
        $product = $this->makeProduct();

        $this->actingAs($customer, 'sanctum');
        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $checkout = $this->postJson('/api/orders/checkout', [
            'delivery_address' => 'Damascus',
            'payment_type' => 'cash',
        ]);
        $checkout->assertCreated();
        $orderId = $checkout->json('data.id');

        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/admin/orders/'.$orderId.'/status', ['status' => 'shipped'])->assertOk();

        $notification = UserNotification::where('ref_id', $orderId)->where('type', 'order_shipped')->first();
        $this->assertNotNull($notification);
        $this->assertSame('Your order is on the way', $notification->title);
    }
}
