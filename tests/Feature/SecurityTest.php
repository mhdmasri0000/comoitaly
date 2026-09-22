<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_returns_access_token(): void
    {
        User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'phone_number' => '0500000001',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => '123456',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.role', 'admin');
        $response->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');
    }

    public function test_mutating_catalog_routes_require_admin_access(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([['POST', 'api/categories'], ['POST', 'api/products']] as [$method, $uri]) {
            $route = $routes->match(Request::create($uri, $method));

            $this->assertContains('auth:sanctum', $route->middleware());
            $this->assertContains('admin', $route->middleware());
        }
    }

    public function test_admin_cannot_delete_category_with_products(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'catalog-category-delete@example.com',
            'phone_number' => '0500000012',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
        ]);
        $category = Category::create([
            'id' => (string) Str::uuid(),
            'name' => ['en' => 'Category', 'ar' => 'فئة'],
        ]);
        Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Product', 'ar' => 'منتج'],
            'description' => ['en' => 'Description', 'ar' => 'وصف'],
        ]);

        $this->actingAs($admin, 'sanctum');

        $this->deleteJson('/api/categories/'.$category->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_admin_cannot_delete_product_in_order_history(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'catalog-product-delete@example.com',
            'phone_number' => '0500000013',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
        ]);
        $customer = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Customer',
            'last_name' => 'User',
            'email' => 'catalog-product-customer@example.com',
            'phone_number' => '0500000014',
            'password_hash' => Hash::make('123456'),
            'role' => 'user',
        ]);
        $category = Category::create([
            'id' => (string) Str::uuid(),
            'name' => ['en' => 'Category', 'ar' => 'فئة'],
        ]);
        $product = Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Product', 'ar' => 'منتج'],
            'description' => ['en' => 'Description', 'ar' => 'وصف'],
        ]);
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'delivery_address' => 'Test address',
            'payment_type' => 'cash',
            'status' => 'pending',
            'subtotal' => 10,
            'delivery_fee' => 0,
            'total' => 10,
        ]);
        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Product',
            'unit_price' => 10,
            'total_price' => 10,
            'quantity' => 1,
        ]);

        $this->actingAs($admin, 'sanctum');

        $this->deleteJson('/api/products/'.$product->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_lang_query_sets_locale_without_breaking_api_requests(): void
    {
        $response = $this->get('/api/health?lang=ar');

        $response->assertOk();
        $this->assertSame('ar', app()->getLocale());
    }

    public function test_device_token_registration_accepts_android_platform_case_insensitively(): void
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Android',
            'last_name' => 'User',
            'email' => 'android-user@example.com',
            'phone_number' => '0500000011',
            'password_hash' => Hash::make('123456'),
            'role' => 'user',
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->putJson('/api/user/fcm-token', [
            'fcm_token' => 'test_android_token_value_1234567890',
            'platform' => 'Android',
        ]);

        $response->assertOk();
        $this->assertSame('android', $user->fresh()->fcm_platform);
    }

    public function test_user_cart_avoids_duplicate_product_rows_when_added_repeatedly(): void
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Cart',
            'last_name' => 'User',
            'email' => 'cart-user@example.com',
            'phone_number' => '0500000010',
            'password_hash' => Hash::make('123456'),
            'role' => 'user',
        ]);

        $category = Category::create([
            'id' => (string) Str::uuid(),
            'name' => 'Accessories',
            'image_cover' => 'https://example.com/cat.jpg',
        ]);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Classic Watch', 'ar' => 'ساعة كلاسيك'],
            'description' => ['en' => 'A watch', 'ar' => 'ساعة'],
            'price' => 100,
            'quantity' => 10,
            'rating' => 4,
            'is_featured' => false,
            'is_offer' => false,
        ]);

        $this->actingAs($user, 'sanctum');

        $first = $this->postJson('/api/cart', [
            'product_id' => $product->id,
            'size' => 'M',
            'color' => 'Black',
            'quantity' => 1,
        ]);
        $first->assertCreated();

        $second = $this->postJson('/api/cart', [
            'product_id' => $product->id,
            'size' => 'M',
            'color' => 'Black',
            'quantity' => 2,
        ]);

        $second->assertOk();
        $this->assertSame(1, CartItem::where('user_id', $user->id)->where('product_id', $product->id)->count());
        $this->assertSame(3, CartItem::where('user_id', $user->id)->where('product_id', $product->id)->first()->quantity);
    }

    public function test_admin_analytics_revenue_uses_total_value_and_returns_daily_series(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin2@example.com',
            'phone_number' => '0500000004',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Ali',
            'last_name' => 'User',
            'email' => 'user2@example.com',
            'phone_number' => '0500000005',
            'password_hash' => Hash::make('123456'),
            'role' => 'user',
        ]);

        Order::forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'delivery_address' => 'Riyadh',
            'payment_type' => 'cash_on_delivery',
            'status' => 'delivered',
            'subtotal' => 150.00,
            'delivery_fee' => 20.00,
            'total' => 170.00,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/admin/analytics/revenue?from=' . now()->subDays(2)->toDateString() . '&to=' . now()->toDateString());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data'));
        $this->assertTrue(collect($response->json('data'))->contains(fn ($row) => ($row['date'] ?? null) === now()->subDay()->toDateString() && (float) $row['count'] === 170.0));
    }

    public function test_admin_analytics_returns_zero_filled_data_when_no_records_exist(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin3@example.com',
            'phone_number' => '0500000006',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/admin/analytics/users-daily?from=' . now()->subDays(2)->toDateString() . '&to=' . now()->toDateString());

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertSame(3, count($response->json('data')));
        $this->assertTrue(collect($response->json('data'))->every(fn ($row) => isset($row['count']) && is_numeric($row['count'])));
    }

    public function test_admin_can_update_product_with_nested_data_and_follow_up_get_returns_it(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'Editor',
            'email' => 'admin4@example.com',
            'phone_number' => '0500000007',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
        ]);

        $category = Category::create([
            'id' => (string) Str::uuid(),
            'name' => 'Electronics',
            'image_cover' => 'https://example.com/cat.jpg',
        ]);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Old name', 'ar' => 'اسم قديم'],
            'description' => ['en' => 'Old description', 'ar' => 'وصف قديم'],
            'price' => 50,
            'quantity' => 2,
            'rating' => 4,
            'is_featured' => false,
            'is_offer' => false,
        ]);

        $this->actingAs($admin, 'sanctum');

        $payload = [
            'category_id' => $category->id,
            'name' => ['en' => 'Updated Product', 'ar' => 'منتج محدث'],
            'description' => ['en' => 'Updated description', 'ar' => 'وصف محدث'],
            'price' => 123,
            'quantity' => 10,
            'is_featured' => true,
            'is_offer' => false,
            'sizes' => [['size_label' => 'M'], ['size_label' => 'L']],
            'measurements' => [['label' => 'chest', 'value' => '95cm']],
            'colors' => [['color_name' => 'Red', 'color_hex' => '#ff0000']],
            'tags' => ['new', 'popular'],
            'cover' => 'https://example.com/new-cover.jpg',
            'gallery' => ['https://example.com/gallery-1.jpg', 'https://example.com/gallery-2.jpg'],
        ];

        $response = $this->putJson('/api/products/' . $product->id, $payload);

        $response->assertOk();

        $followUp = $this->getJson('/api/products/' . $product->id);
        $followUp->assertOk();
        $this->assertSame('Updated Product', $followUp->json('data.name.en'));
        $this->assertSame('https://example.com/new-cover.jpg', $followUp->json('data.cover'));
        $this->assertEqualsCanonicalizing(['new', 'popular'], $followUp->json('data.tags'));
        $this->assertTrue(collect($followUp->json('data.colors'))->contains(fn ($color) => ($color['color_name'] ?? null) === 'Red'));
        $this->assertTrue(collect($followUp->json('data.sizes'))->contains(fn ($size) => ($size['size_label'] ?? null) === 'M'));
        $this->assertTrue(collect($followUp->json('data.measurements'))->contains(fn ($measurement) => ($measurement['label'] ?? null) === 'chest' && ($measurement['value'] ?? null) === '95cm'));
        $this->assertEqualsCanonicalizing(['https://example.com/gallery-1.jpg', 'https://example.com/gallery-2.jpg'], $followUp->json('data.gallery'));
    }

    public function test_admin_can_update_product_with_uploaded_cover_and_gallery_files(): void
    {
        Storage::fake('public');

        $admin = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'Uploader',
            'email' => 'admin-upload@example.com',
            'phone_number' => '0500000008',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
        ]);

        $category = Category::create([
            'id' => (string) Str::uuid(),
            'name' => 'Upload category',
            'image_cover' => 'https://example.com/cat.jpg',
        ]);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'category_id' => $category->id,
            'name' => ['en' => 'Upload product'],
            'description' => ['en' => 'Product with uploaded images'],
            'price' => 50,
        ]);

        $this->actingAs($admin, 'sanctum');
        $cover = UploadedFile::fake()->create('new-cover.jpg', 100, 'image/jpeg');
        $gallery = UploadedFile::fake()->create('new-gallery.jpg', 100, 'image/jpeg');

        $response = $this->post('/api/products/'.$product->id, [
            '_method' => 'PUT',
            'name' => ['en' => 'Updated upload product'],
            'cover' => $cover,
            'gallery' => [$gallery],
        ]);

        $response->assertOk();
        $coverUrl = $response->json('data.cover');
        $galleryUrls = $response->json('data.gallery');

        $this->assertStringStartsWith('/storage/uploads/', $coverUrl);
        $this->assertCount(1, $galleryUrls);
        $uploadedGalleryUrl = collect($galleryUrls)->first(fn ($url) => str_starts_with($url, '/storage/uploads/'));
        $this->assertNotNull($uploadedGalleryUrl);
        Storage::disk('public')->assertExists(str_replace('/storage/uploads/', 'uploads/', $coverUrl));
        Storage::disk('public')->assertExists(str_replace('/storage/uploads/', 'uploads/', $uploadedGalleryUrl));
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id, 'image_url' => $coverUrl, 'is_primary' => true]);
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id, 'image_url' => $uploadedGalleryUrl, 'is_primary' => false]);

        $followUp = $this->getJson('/api/products/'.$product->id);
        $followUp->assertOk();
        $this->assertSame($coverUrl, $followUp->json('data.cover'));
        $this->assertSame($galleryUrls, $followUp->json('data.gallery'));
    }
}
