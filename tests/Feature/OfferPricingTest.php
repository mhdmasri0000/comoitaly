<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferPricingTest extends TestCase
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
            'phone_number' => '0520000'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
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

    private function makeProduct(Category $category, float $price, int $quantity = 50): Product
    {
        return Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Item', 'ar' => 'منتج'],
            'description' => ['en' => 'desc', 'ar' => 'وصف'],
            'price' => $price,
            'quantity' => $quantity,
            'min_order_quantity' => 1,
            'rating' => 4,
            'is_featured' => false,
            'is_offer' => false,
        ]);
    }

    private function createOffer(array $products, int $discount = 20, array $extra = []): array
    {
        $response = $this->postJson('/api/admin/offers', array_merge([
            'title' => ['en' => 'Sale', 'ar' => 'تخفيض'],
            'subtitle' => ['en' => 'SUMMER', 'ar' => 'صيف'],
            'heading' => ['en' => 'SALE', 'ar' => 'تخفيض'],
            'button_text' => ['en' => 'SHOP', 'ar' => 'تسوق'],
            'discount_percent' => $discount,
            'is_active' => true,
            'products' => $products,
        ], $extra));

        $response->assertCreated();

        return $response->json('data');
    }

    public function test_admin_creates_offer_with_general_and_per_product_discount(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum');
        $category = $this->makeCategory();
        $a = $this->makeProduct($category, 100);
        $b = $this->makeProduct($category, 200);

        $offer = $this->createOffer([
            ['id' => $a->id],
            ['id' => $b->id, 'discount_percent' => 40],
        ], 20);

        $this->assertSame(20, $offer['discount_percent']);
        $prices = collect($offer['products'])->keyBy('id');
        $this->assertSame('80.00', $prices[$a->id]['offer_price']);
        $this->assertSame(20, $prices[$a->id]['discount_percent']);
        $this->assertSame('120.00', $prices[$b->id]['offer_price']);
        $this->assertSame(40, $prices[$b->id]['discount_percent']);
        $this->assertDatabaseHas('offer_product', ['offer_id' => $offer['id'], 'product_id' => $a->id]);
    }

    public function test_customer_dealer_and_admin_prices_reflect_active_offer(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 100);

        Offer::create([
            'id' => (string) Str::uuid(),
            'title' => ['en' => 'Sale', 'ar' => 'تخفيض'],
            'discount_percent' => 25,
            'is_active' => true,
        ])->products()->attach($product->id, ['discount_percent' => null]);

        $this->actingAs($this->makeUser('user'), 'sanctum');
        $customer = $this->getJson('/api/products/'.$product->id);
        $customer->assertOk();
        $this->assertSame('75.00', $customer->json('data.price'));
        $this->assertSame('100.00', $customer->json('data.base_price'));
        $this->assertSame(25, $customer->json('data.discount_percent'));
        $this->assertTrue($customer->json('data.on_offer'));

        $this->actingAs($this->makeUser('dealer', 0.8), 'sanctum');
        $dealer = $this->getJson('/api/products/'.$product->id);
        $this->assertSame('60.00', $dealer->json('data.price'));
        $this->assertSame('75.00', $dealer->json('data.offer_price'));
        $this->assertSame(0.8, $dealer->json('data.price_factor'));

        $this->actingAs($this->makeUser('admin'), 'sanctum');
        $admin = $this->getJson('/api/products/'.$product->id);
        $this->assertSame('100.00', $admin->json('data.price'));
        $this->assertFalse($admin->json('data.on_offer'));
    }

    public function test_inactive_and_expired_offers_are_not_applied(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 100);

        Offer::create([
            'id' => (string) Str::uuid(),
            'title' => ['en' => 'Off', 'ar' => 'موقوف'],
            'discount_percent' => 50,
            'is_active' => false,
        ])->products()->attach($product->id, ['discount_percent' => null]);

        Offer::create([
            'id' => (string) Str::uuid(),
            'title' => ['en' => 'Expired', 'ar' => 'منتهي'],
            'discount_percent' => 50,
            'is_active' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ])->products()->attach($product->id, ['discount_percent' => null]);

        $this->actingAs($this->makeUser('user'), 'sanctum');
        $response = $this->getJson('/api/products/'.$product->id);
        $response->assertOk();
        $this->assertSame('100.00', $response->json('data.price'));
        $this->assertFalse($response->json('data.on_offer'));
    }

    public function test_checkout_charges_the_offer_price(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 100, 20);

        Offer::create([
            'id' => (string) Str::uuid(),
            'title' => ['en' => 'Sale', 'ar' => 'تخفيض'],
            'discount_percent' => 25,
            'is_active' => true,
        ])->products()->attach($product->id, ['discount_percent' => null]);

        $this->actingAs($this->makeUser('user'), 'sanctum');
        $cart = $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2]);
        $cart->assertCreated();
        $this->assertSame('150.00', $cart->json('data.total_price'));

        $checkout = $this->postJson('/api/orders/checkout', [
            'delivery_address' => 'Damascus',
            'payment_type' => 'cash',
        ]);
        $checkout->assertCreated();
        $this->assertSame('75.00', $checkout->json('data.items.0.unit_price'));
        $this->assertSame('150.00', $checkout->json('data.subtotal'));
    }

    public function test_public_offers_endpoint_returns_only_active_offers(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 100);

        Offer::create([
            'id' => (string) Str::uuid(),
            'title' => ['en' => 'Active', 'ar' => 'فعّال'],
            'discount_percent' => 10,
            'is_active' => true,
        ])->products()->attach($product->id, ['discount_percent' => null]);

        Offer::create([
            'id' => (string) Str::uuid(),
            'title' => ['en' => 'Hidden', 'ar' => 'مخفي'],
            'discount_percent' => 10,
            'is_active' => false,
        ])->products()->attach($product->id, ['discount_percent' => null]);

        $response = $this->getJson('/api/offers');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Active', $response->json('data.0.title.en'));
    }
}
