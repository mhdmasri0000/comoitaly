<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\HeroBanner;
use App\Models\Offer;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\BannerSerializer;
use App\Support\CategorySerializer;
use App\Support\OfferSerializer;
use App\Support\ProductSerializer;
use Illuminate\Http\Request;

class PublicContentController extends Controller
{
    private const PRODUCT_WITH = ['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers'];

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'gte:min_price'],
            'sort_by' => ['nullable', 'in:newest,price_asc,price_desc,most_popular,highest_rated'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        $query = Product::with(self::PRODUCT_WITH);

        if (! empty($data['q'])) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$data['q'].'%')
                ->orWhere('description', 'like', '%'.$data['q'].'%'));
        }

        if (isset($data['min_price'])) {
            $query->where('price', '>=', $data['min_price']);
        }
        if (isset($data['max_price'])) {
            $query->where('price', '<=', $data['max_price']);
        }

        match ($data['sort_by'] ?? 'newest') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'highest_rated' => $query->orderByDesc('rating'),
            default => $query->latest(),
        };

        $products = $query
            ->limit($data['limit'] ?? 50)
            ->offset($data['offset'] ?? 0)
            ->get();

        return ApiResponse::success('Search results retrieved successfully', ProductSerializer::collection($products, $request->user('sanctum')));
    }

    public function home(Request $request)
    {
        $user = $request->user('sanctum');

        return ApiResponse::success('Home page data retrieved successfully', [
            'featured_products' => ProductSerializer::collection(
                Product::with(self::PRODUCT_WITH)->where('is_featured', true)->latest()->get(),
                $user
            ),
            'offer_products' => ProductSerializer::collection(
                Product::with(self::PRODUCT_WITH)
                    ->whereHas('offers', fn ($q) => $q->currentlyActive())
                    ->latest()
                    ->get(),
                $user
            ),
            'categories' => CategorySerializer::collection(Category::withCount('products')->get()),
            'banners' => BannerSerializer::collection(
                HeroBanner::banners()->where('is_active', true)->orderBy('display_order')->get()
            ),
            'offers' => OfferSerializer::publicCollection(
                Offer::currentlyActive()->orderBy('display_order')->orderByDesc('created_at')->get(),
                $user
            ),
        ]);
    }
}
