<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DealerOrderingTest extends TestCase
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
            'phone_number' => '0500000'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
            'password_hash' => Hash::make('123456'),
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

    private function makeProduct(Category $category, float $price = 100, int $quantity = 10, int $minOrder = 5): Product
    {
        return Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Bulk Item', 'ar' => 'منتج جملة'],
            'description' => ['en' => 'desc', 'ar' => 'وصف'],
            'price' => $price,
            'quantity' => $quantity,
            'min_order_quantity' => $minOrder,
            'rating' => 4,
            'is_featured' => false,
            'is_offer' => false,
        ]);
    }

    public function test_dealer_sees_factor_price_and_customers_see_base_price(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, price: 100, minOrder: 5);

        $dealer = $this->makeUser('dealer', 0.8);
        $customer = $this->makeUser('user');

        $this->actingAs($dealer, 'sanctum');
        $dealerView = $this->getJson('/api/products/'.$product->id);
        $dealerView->assertOk();
        $this->assertSame('80.00', $dealerView->json('data.price'));
        $this->assertSame('100.00', $dealerView->json('data.base_price'));
        $this->assertSame(5, $dealerView->json('data.min_order_quantity'));

        $this->actingAs($customer, 'sanctum');
        $customerView = $this->getJson('/api/products/'.$product->id);
        $customerView->assertOk();
        $this->assertSame('100.00', $customerView->json('data.price'));
        $this->assertNull($customerView->json('data.base_price'));
    }

    public function test_dealer_minimum_order_quantity_is_enforced_in_cart(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, price: 100, quantity: 50, minOrder: 5);

        $dealer = $this->makeUser('dealer', 0.8);
        $this->actingAs($dealer, 'sanctum');

        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(400);

        $ok = $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 5]);
        $ok->assertCreated();
        $this->assertSame('400.00', $ok->json('data.total_price'));
    }

    public function test_customer_is_not_bound_by_minimum_order_quantity(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, price: 100, quantity: 50, minOrder: 5);

        $customer = $this->makeUser('user');
        $this->actingAs($customer, 'sanctum');

        $response = $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2]);
        $response->assertCreated();
        $this->assertSame('200.00', $response->json('data.total_price'));
    }

    public function test_cart_rejects_quantity_above_stock(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, price: 100, quantity: 3, minOrder: 1);

        $customer = $this->makeUser('user');
        $this->actingAs($customer, 'sanctum');

        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 5])
            ->assertStatus(400);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_checkout_decrements_stock_uses_dealer_price_and_cancel_restores_stock(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, price: 100, quantity: 10, minOrder: 5);

        $dealer = $this->makeUser('dealer', 0.8);
        $admin = $this->makeUser('admin');

        $this->actingAs($dealer, 'sanctum');
        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 5])->assertCreated();

        $checkout = $this->postJson('/api/orders/checkout', [
            'delivery_address' => 'Damascus, Street 1',
            'payment_type' => 'cash',
        ]);
        $checkout->assertCreated();
        $this->assertSame('400.00', $checkout->json('data.subtotal'));
        $this->assertSame('80.00', $checkout->json('data.items.0.unit_price'));
        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertDatabaseCount('cart_items', 0);

        $orderId = $checkout->json('data.id');

        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/admin/orders/'.$orderId.'/status', ['status' => 'cancelled'])
            ->assertOk();
        $this->assertSame(10, $product->fresh()->quantity);
    }
}
