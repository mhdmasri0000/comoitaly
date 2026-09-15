<?php

namespace Database\Seeders;

use App\Models\Offer;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Clothing offers (campaigns with product discounts) for the dashboard/app.
 */
class DemoOffersSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
                'subtitle' => ['en' => 'LIMITED TIME', 'ar' => 'لفترة محدودة'],
                'heading' => ['en' => 'UP TO 25% OFF', 'ar' => 'خصم حتى 25%'],
                'button_text' => ['en' => 'Shop now', 'ar' => 'تسوق الآن'],
                'image_cover' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=1200&q=80',
                'discount_percent' => 20,
                'display_order' => 1,
                'ends_in_days' => 30,
                'products' => [
                    ['name' => 'Floral Summer Dress', 'discount' => null],
                    ['name' => 'Classic White T-Shirt', 'discount' => null],
                    ['name' => 'Running Sneakers', 'discount' => 15],
                ],
            ],
            [
                'title' => ['en' => 'Men Essentials', 'ar' => 'أساسيات رجالية'],
                'subtitle' => ['en' => 'WARDROBE UPGRADE', 'ar' => 'طوّر خزانتك'],
                'heading' => ['en' => '15% OFF', 'ar' => 'خصم 15%'],
                'button_text' => ['en' => 'Discover', 'ar' => 'اكتشف'],
                'image_cover' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1200&q=80',
                'discount_percent' => 15,
                'display_order' => 2,
                'ends_in_days' => null,
                'products' => [
                    ['name' => 'Slim Fit Jeans', 'discount' => null],
                    ['name' => 'Oversized Hoodie', 'discount' => null],
                    ['name' => 'Leather Belt', 'discount' => null],
                ],
            ],
            [
                'title' => ['en' => 'Kids Deal', 'ar' => 'عرض الأطفال'],
                'subtitle' => ['en' => 'FOR THE LITTLE ONES', 'ar' => 'لأصغر الصغار'],
                'heading' => ['en' => '10% OFF', 'ar' => 'خصم 10%'],
                'button_text' => ['en' => 'Shop now', 'ar' => 'تسوق الآن'],
                'image_cover' => 'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=1200&q=80',
                'discount_percent' => 10,
                'display_order' => 3,
                'ends_in_days' => null,
                'products' => [
                    ['name' => 'Kids Cotton Set', 'discount' => null],
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            if (Offer::where('title->en', $definition['title']['en'])->exists()) {
                continue;
            }

            $offer = Offer::create([
                'id' => (string) Str::uuid(),
                'title' => $definition['title'],
                'subtitle' => $definition['subtitle'],
                'heading' => $definition['heading'],
                'button_text' => $definition['button_text'],
                'image_cover' => $definition['image_cover'],
                'button_link' => null,
                'discount_percent' => $definition['discount_percent'],
                'starts_at' => null,
                'ends_at' => $definition['ends_in_days'] ? now()->addDays($definition['ends_in_days']) : null,
                'is_active' => true,
                'display_order' => $definition['display_order'],
            ]);

            $sync = [];

            foreach ($definition['products'] as $entry) {
                $product = Product::where('name->en', $entry['name'])->first();

                if ($product) {
                    $sync[$product->id] = ['discount_percent' => $entry['discount'] ?? null];
                }
            }

            $offer->products()->sync($sync);
        }

        $this->command?->info('Demo clothing offers seeded.');
    }
}
