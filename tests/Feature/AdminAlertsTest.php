<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, ?float $factor = null): User
    {
        static $counter = 0;
        $counter++;

        return User::create([
            'id' => (string) Str::uuid(),
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $role.$counter.'@example.com',
            'phone_number' => '0510000'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
            'password_hash' => Hash::make('1234567890'),
            'role' => $role,
            'dealer_price_factor' => $factor,
        ]);
    }

    private function makeCategory(): Category
    {
        return Category::create([
            'id' => (string) Str::uuid(),
            'name' => ['en' => 'Gear', 'ar' => 'عدة'],
            'image_cover' => 'https://example.com/cat.jpg',
        ]);
    }

    private function makeProduct(Category $category, int $quantity = 50): Product
    {
        return Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Bulk Item', 'ar' => 'منتج جملة'],
            'description' => ['en' => 'desc', 'ar' => 'وصف'],
            'price' => 100,
            'quantity' => $quantity,
            'min_order_quantity' => 1,
            'rating' => 4,
            'is_featured' => false,
            'is_offer' => false,
        ]);
    }

    public function test_new_order_notifies_every_admin(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser('user');
        $product = $this->makeProduct($this->makeCategory());

        $this->actingAs($customer, 'sanctum');
        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $checkout = $this->postJson('/api/orders/checkout', [
            'delivery_address' => 'Damascus',
            'payment_type' => 'cash',
        ]);
        $checkout->assertCreated();

        $orderId = $checkout->json('data.id');
        $this->assertDatabaseHas('admin_notifications', [
            'user_id' => $admin->id,
            'type' => 'order_new',
            'ref_id' => $orderId,
        ]);
        $this->assertDatabaseMissing('admin_notifications', ['type' => 'order_large', 'ref_id' => $orderId]);
    }

    public function test_dealer_order_and_shamcash_create_alerts(): void
    {
        $admin = $this->makeUser('admin');
        $dealer = $this->makeUser('dealer', 0.8);
        $product = $this->makeProduct($this->makeCategory());

        $this->actingAs($dealer, 'sanctum');
        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $checkout = $this->postJson('/api/orders/checkout', [
            'delivery_address' => 'Damascus',
            'payment_type' => 'shamcash',
        ]);
        $checkout->assertCreated();

        $orderId = $checkout->json('data.id');
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $admin->id, 'type' => 'order_large', 'ref_id' => $orderId]);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $admin->id, 'type' => 'payment_proof', 'ref_id' => $orderId]);
    }

    public function test_stock_alerts_are_deduplicated_escalated_and_resolved(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct($this->makeCategory(), quantity: 5);

        AdminNotifier::syncStock($product);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $admin->id, 'type' => 'stock_low', 'ref_id' => $product->id]);

        AdminNotifier::syncStock($product);
        $this->assertSame(1, AdminNotification::where('ref_id', $product->id)->count());

        $product->update(['quantity' => 0]);
        AdminNotifier::syncStock($product->fresh());
        $this->assertSame(1, AdminNotification::where('ref_id', $product->id)->count());
        $this->assertDatabaseHas('admin_notifications', ['type' => 'stock_out', 'ref_id' => $product->id]);

        $product->update(['quantity' => 40]);
        AdminNotifier::syncStock($product->fresh());
        $this->assertSame(0, AdminNotification::where('ref_id', $product->id)->whereNull('resolved_at')->count());
    }

    public function test_inbox_endpoints_are_scoped_to_the_current_admin(): void
    {
        $admin = $this->makeUser('admin');
        $other = $this->makeUser('admin');
        $product = $this->makeProduct($this->makeCategory(), quantity: 2);

        AdminNotifier::syncStock($product);

        $this->actingAs($admin, 'sanctum');

        $list = $this->getJson('/api/admin/alerts');
        $list->assertOk();
        $list->assertJsonPath('data.unread_count', 1);
        $this->assertCount(1, $list->json('data.items'));
        $this->assertSame(1, $list->json('data.meta.total'));

        $alertId = $list->json('data.items.0.id');
        $this->assertSame($admin->id, $list->json('data.items.0.user_id'));

        $this->getJson('/api/admin/alerts/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        // Another admin only sees their own inbox.
        $this->actingAs($other, 'sanctum');
        $this->getJson('/api/admin/alerts')->assertJsonPath('data.meta.total', 1);
        $this->assertNotSame($alertId, $this->getJson('/api/admin/alerts')->json('data.items.0.id'));

        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/admin/alerts/'.$alertId.'/read')->assertOk();
        $this->getJson('/api/admin/alerts/unread-count')->assertJsonPath('data.unread_count', 0);

        AdminNotifier::syncStock($product->fresh());
        $this->postJson('/api/admin/alerts/mark-all-read')->assertOk();
        $this->getJson('/api/admin/alerts/unread-count')->assertJsonPath('data.unread_count', 0);

        $this->deleteJson('/api/admin/alerts/'.$alertId)->assertOk();
        $this->assertDatabaseMissing('admin_notifications', ['id' => $alertId]);
    }
}
