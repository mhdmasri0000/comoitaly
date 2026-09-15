<?php

namespace Database\Seeders;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\HeroBanner;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            'order_items',
            'orders',
            'cart_items',
            'favorites',
            'offer_product',
            'offers',
            'product_colors',
            'product_sizes',
            'product_images',
            'products',
            'categories',
            'notifications',
            'hero_banners',
            'settings',
            'password_resets',
            'personal_access_tokens',
            'users',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $admin = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'phone_number' => '0500000001',
            'password_hash' => Hash::make('123456'),
            'role' => 'admin',
            'profile_image' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=300&q=80',
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Ali',
            'last_name' => 'Hassan',
            'email' => 'user@example.com',
            'phone_number' => '0500000002',
            'password_hash' => Hash::make('123456'),
            'role' => 'user',
            'profile_image' => 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?auto=format&fit=crop&w=300&q=80',
        ]);

        $dealer = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Samir',
            'last_name' => 'Store',
            'email' => 'dealer@example.com',
            'phone_number' => '0500000003',
            'password_hash' => Hash::make('123456'),
            'role' => 'dealer',
            'profile_image' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=300&q=80',
        ]);

        $user->forceFill(['created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4)])->save();
        $dealer->forceFill(['created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)])->save();

        $admin->forceFill(['email_verified_at' => now()])->save();
        $user->forceFill(['email_verified_at' => now()])->save();
        $dealer->forceFill(['email_verified_at' => now()])->save();

        $categories = [
            ['name' => ['en' => 'Men', 'ar' => 'رجالي'], 'image_cover' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1200&q=80'],
            ['name' => ['en' => 'Women', 'ar' => 'نسائي'], 'image_cover' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=1200&q=80'],
            ['name' => ['en' => 'Kids', 'ar' => 'أطفال'], 'image_cover' => 'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=1200&q=80'],
            ['name' => ['en' => 'Shoes', 'ar' => 'أحذية'], 'image_cover' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=1200&q=80'],
            ['name' => ['en' => 'Accessories', 'ar' => 'إكسسوارات'], 'image_cover' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=1200&q=80'],
        ];

        $categoryModels = [];
        foreach ($categories as $category) {
            $categoryModels[] = Category::create([
                'id' => (string) Str::uuid(),
                'name' => $category['name'],
                'image_cover' => $category['image_cover'],
                'items_count' => 0,
            ]);
        }

        $products = [];

        $productData = [
            [
                'category' => $categoryModels[0],
                'name' => ['en' => 'Classic White T-Shirt', 'ar' => 'تي شيرت أبيض كلاسيكي'],
                'description' => ['en' => 'Soft cotton crew-neck tee, a wardrobe staple.', 'ar' => 'تي شيرت قطني ناعم بأكمام قصيرة، أساسي في خزانتك.'],
                'price' => 39.99,
                'quantity' => 60,
                'rating' => 4.7,
                'is_featured' => true,
                'is_offer' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=900&q=80',
                    'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['S', 'M', 'L', 'XL'],
                'colors' => [['name' => 'White', 'hex' => '#F8FAFC'], ['name' => 'Black', 'hex' => '#111827']],
            ],
            [
                'category' => $categoryModels[0],
                'name' => ['en' => 'Slim Fit Jeans', 'ar' => 'بنطال جينز ضيق'],
                'description' => ['en' => 'Stretch denim jeans with a modern slim cut.', 'ar' => 'جينز مطاطي بقَصّة عصرية ضيقة.'],
                'price' => 79.99,
                'quantity' => 40,
                'rating' => 4.5,
                'is_featured' => false,
                'is_offer' => false,
                'images' => [
                    'https://images.unsplash.com/photo-1542272604-787c3835535d?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['30', '32', '34', '36'],
                'colors' => [['name' => 'Blue', 'hex' => '#1D4ED8'], ['name' => 'Black', 'hex' => '#111827']],
            ],
            [
                'category' => $categoryModels[0],
                'name' => ['en' => 'Oversized Hoodie', 'ar' => 'هودي واسع'],
                'description' => ['en' => 'Warm oversized hoodie with a relaxed fit.', 'ar' => 'هودي دافئ بقَصّة واسعة مريحة.'],
                'price' => 69.99,
                'quantity' => 35,
                'rating' => 4.6,
                'is_featured' => false,
                'is_offer' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1556821840-3a63f95609a7?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['S', 'M', 'L', 'XL'],
                'colors' => [['name' => 'Grey', 'hex' => '#94A3B8'], ['name' => 'Black', 'hex' => '#111827']],
            ],
            [
                'category' => $categoryModels[1],
                'name' => ['en' => 'Floral Summer Dress', 'ar' => 'فستان صيفي مزهر'],
                'description' => ['en' => 'Light floral dress, perfect for warm days.', 'ar' => 'فستان مزهر خفيف مثالي للأيام الدافئة.'],
                'price' => 89.99,
                'quantity' => 30,
                'rating' => 4.8,
                'is_featured' => true,
                'is_offer' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=900&q=80',
                    'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['XS', 'S', 'M', 'L'],
                'colors' => [['name' => 'Red', 'hex' => '#DC2626'], ['name' => 'Blue', 'hex' => '#2563EB']],
            ],
            [
                'category' => $categoryModels[1],
                'name' => ['en' => 'Wool Blend Coat', 'ar' => 'معطف صوف'],
                'description' => ['en' => 'Elegant wool-blend coat for chilly weather.', 'ar' => 'معطف أنيق من الصوف المخلوط للطقس البارد.'],
                'price' => 149.99,
                'quantity' => 18,
                'rating' => 4.7,
                'is_featured' => false,
                'is_offer' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['S', 'M', 'L'],
                'colors' => [['name' => 'Beige', 'hex' => '#D6C7A1'], ['name' => 'Black', 'hex' => '#111827']],
            ],
            [
                'category' => $categoryModels[2],
                'name' => ['en' => 'Kids Cotton Set', 'ar' => 'طقم قطني للأطفال'],
                'description' => ['en' => 'Comfortable two-piece cotton set for kids.', 'ar' => 'طقم قطني مريح من قطعتين للأطفال.'],
                'price' => 34.99,
                'quantity' => 50,
                'rating' => 4.4,
                'is_featured' => false,
                'is_offer' => false,
                'images' => [
                    'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['2Y', '4Y', '6Y', '8Y'],
                'colors' => [['name' => 'Blue', 'hex' => '#2563EB'], ['name' => 'Pink', 'hex' => '#F9A8D4']],
            ],
            [
                'category' => $categoryModels[3],
                'name' => ['en' => 'Running Sneakers', 'ar' => 'حذاء رياضي'],
                'description' => ['en' => 'Lightweight sneakers with cushioned soles.', 'ar' => 'حذاء خفيف بنعل مبطّن مريح.'],
                'price' => 99.99,
                'quantity' => 45,
                'rating' => 4.6,
                'is_featured' => true,
                'is_offer' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['40', '41', '42', '43', '44'],
                'colors' => [['name' => 'White', 'hex' => '#F8FAFC'], ['name' => 'Black', 'hex' => '#111827']],
            ],
            [
                'category' => $categoryModels[4],
                'name' => ['en' => 'Leather Belt', 'ar' => 'حزام جلدي'],
                'description' => ['en' => 'Classic genuine leather belt.', 'ar' => 'حزام جلد طبيعي كلاسيكي.'],
                'price' => 29.99,
                'quantity' => 70,
                'rating' => 4.3,
                'is_featured' => false,
                'is_offer' => false,
                'images' => [
                    'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=80',
                ],
                'sizes' => ['One Size'],
                'colors' => [['name' => 'Brown', 'hex' => '#7C2D12'], ['name' => 'Black', 'hex' => '#111827']],
            ],
        ];

        foreach ($productData as $item) {
            $product = Product::create([
                'id' => (string) Str::uuid(),
                'category_id' => $item['category']->id,
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'rating' => $item['rating'],
                'description' => $item['description'],
                'is_featured' => $item['is_featured'],
                'is_offer' => $item['is_offer'],
            ]);

            $products[] = $product;

            $colorNames = array_values(array_filter(array_map(
                fn ($color) => $color['name'] ?? null,
                $item['colors'] ?? []
            )));

            foreach ($item['images'] as $index => $imageUrl) {
                ProductImage::create([
                    'id' => (string) Str::uuid(),
                    'product_id' => $product->id,
                    'image_url' => $imageUrl,
                    'color_name' => $colorNames[$index % max(1, count($colorNames))] ?? null,
                    'is_primary' => $index === 0,
                ]);
            }

            foreach ($item['sizes'] ?? ['S', 'M', 'L'] as $sizeLabel) {
                ProductSize::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'size_label' => $sizeLabel]);
            }

            foreach ($item['colors'] ?? [['name' => 'Black', 'hex' => '#000000']] as $color) {
                ProductColor::create([
                    'id' => (string) Str::uuid(),
                    'product_id' => $product->id,
                    'color_name' => $color['name'],
                    'color_hex' => $color['hex'],
                ]);
            }
        }

        foreach ($categoryModels as $category) {
            $category->update(['items_count' => $category->products()->count()]);
        }

        Favorite::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'product_id' => $products[0]->id]);
        Favorite::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'product_id' => $products[2]->id]);

        CartItem::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'product_id' => $products[0]->id,
            'size' => 'M',
            'color' => 'Black',
            'quantity' => 2,
            'total_price' => ($products[0]->price * 2),
        ]);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'delivery_address' => 'Riyadh, Saudi Arabia',
            'note' => 'Please deliver after 5 PM',
            'payment_type' => 'cash_on_delivery',
            'status' => 'pending',
            'subtotal' => 599.99,
            'delivery_fee' => 20,
            'total' => 619.99,
        ]);

        OrderItem::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => $products[0]->id,
            'product_name' => 'Smartphone',
            'unit_price' => 599.99,
            'total_price' => 599.99,
            'quantity' => 1,
            'size' => 'M',
            'color' => 'Black',
        ]);

        // Create a useful 12-day history for the analytics endpoints.
        foreach (range(1, 12) as $day) {
            $ordersForDay = ($day % 4) + 1;

            foreach (range(1, $ordersForDay) as $orderNumber) {
                $product = $products[($day + $orderNumber) % count($products)];
                $quantity = (($day + $orderNumber) % 3) + 1;
                $unitPrice = (float) $product->price;
                $subtotal = $unitPrice * $quantity;

                $historyOrder = Order::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'delivery_address' => 'Riyadh, Saudi Arabia',
                    'note' => 'Seeded analytics order',
                    'payment_type' => 'cash_on_delivery',
                    'status' => 'processing',
                    'subtotal' => $subtotal,
                    'delivery_fee' => 20,
                    'total' => $subtotal + 20,
                ]);

                $historyOrder->forceFill([
                    'created_at' => now()->subDays($day)->setTime(10 + $orderNumber, 0),
                    'updated_at' => now()->subDays($day)->setTime(10 + $orderNumber, 0),
                ])->save();

                OrderItem::create([
                    'id' => (string) Str::uuid(),
                    'order_id' => $historyOrder->id,
                    'product_id' => $product->id,
                    'product_name' => is_array($product->name) ? ($product->name['en'] ?? '') : (string) $product->name,
                    'unit_price' => $unitPrice,
                    'total_price' => $subtotal,
                    'quantity' => $quantity,
                    'size' => 'M',
                    'color' => 'Black',
                ]);
            }
        }

        UserNotification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'Welcome',
            'body' => 'Your account is ready and your first order is waiting.',
            'type' => 'system',
            'ref_id' => null,
            'is_read' => false,
        ]);

        UserNotification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'title' => 'New Order',
            'body' => 'A new order was placed by a customer.',
            'type' => 'order',
            'ref_id' => $order->id,
            'is_read' => false,
        ]);

        $banners = [
            [
                'type' => 'banner',
                'image_cover' => 'https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=1200&q=80',
                'subtitle' => ['en' => 'New Collection', 'ar' => 'مجموعة جديدة'],
                'heading' => ['en' => 'Big discounts', 'ar' => 'خصومات كبيرة'],
                'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
                'button_text' => ['en' => 'Shop Now', 'ar' => 'تسوق الآن'],
                'is_active' => true,
                'display_order' => 1,
            ],
            [
                'type' => 'banner',
                'image_cover' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=1200&q=80',
                'subtitle' => ['en' => 'Trending now', 'ar' => 'الأكثر رواجاً'],
                'heading' => ['en' => 'Style upgrade', 'ar' => 'تحديث أسلوبك'],
                'title' => ['en' => 'Fresh arrivals', 'ar' => 'وصل حديثاً'],
                'button_text' => ['en' => 'Discover', 'ar' => 'اكتشف'],
                'is_active' => true,
                'display_order' => 2,
            ],
            [
                'type' => 'offer',
                'image_cover' => 'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=1200&q=80',
                'subtitle' => ['en' => 'SUMMER SALE', 'ar' => 'تخفيضات الصيف'],
                'heading' => ['en' => 'UP TO 40% OFF', 'ar' => 'خصم حتى 40٪'],
                'title' => ['en' => 'Limited time', 'ar' => 'لفترة محدودة'],
                'button_text' => ['en' => 'SHOP NOW', 'ar' => 'تسوق الآن'],
                'is_active' => true,
                'display_order' => 1,
            ],
        ];

        foreach ($banners as $index => $banner) {
            HeroBanner::create([
                'id' => (string) Str::uuid(),
                ...$banner,
            ]);
        }

        DB::table('settings')->upsert([
            ['key' => 'delivery_fee', 'value' => '5', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'delivery_fee_usd', 'value' => '5', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payment_proof_retention_days', 'value' => '10', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency', 'value' => 'USD', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'store_name', 'value' => 'COM-O Store', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'support_phone', 'value' => '+966500000000', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'app_update_policy', 'value' => json_encode([
                'is_active' => false,
                'force_update' => false,
                'ios' => [
                    'minimum_version' => '1.0.25',
                    'latest_version' => '1.0.27',
                    'store_url' => 'https://apps.apple.com/ae/app/varx/id6751514408',
                ],
                'android' => [
                    'minimum_version' => '1.0.25',
                    'latest_version' => '1.0.27',
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.syrianfootballscorers.app',
                ],
                'messages' => [
                    'en' => ['title' => 'Please update', 'body' => 'New features and bug fixes.'],
                    'ar' => ['title' => 'الرجاء التحديث', 'body' => 'ميزات جديدة وإصلاحات للأخطاء.'],
                ],
                'updated_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()],
        ], ['key']);

        DB::table('password_resets')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token' => hash('sha256', 'demo-reset-token'),
            'expires_at' => now()->addHours(2),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->call(DemoOffersSeeder::class);
    }
}
