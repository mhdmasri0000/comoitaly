<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\ProductSerializer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FavoriteController extends Controller
{
    private const PRODUCT_WITH = ['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers'];

    public function index(Request $request)
    {
        $favorites = Favorite::with(['product' => fn ($q) => $q->with(self::PRODUCT_WITH)])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Favorite $favorite) => $this->serializeFavorite($favorite, $request->user()));

        return ApiResponse::success('Favorites retrieved successfully', $favorites->values()->all());
    }

    public function store(Request $request)
    {
        $data = $request->validate(['product_id' => ['required', 'uuid', 'exists:products,id']]);

        $favorite = Favorite::firstOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $data['product_id']],
            ['id' => (string) Str::uuid()]
        );

        $favorite->load(['product' => fn ($q) => $q->with(self::PRODUCT_WITH)]);

        return ApiResponse::success(
            $favorite->wasRecentlyCreated ? 'Favorite added successfully' : 'Product already in favorites',
            $this->serializeFavorite($favorite, $request->user()),
            $favorite->wasRecentlyCreated ? 201 : 200
        );
    }

    public function destroy(Request $request, string $productId)
    {
        Favorite::where('user_id', $request->user()->id)->where('product_id', $productId)->firstOrFail()->delete();

        return ApiResponse::success('Favorite removed successfully');
    }

    private function serializeFavorite(Favorite $favorite, ?User $user = null): array
    {
        $payload = $favorite->toArray();
        $payload['product'] = $favorite->product
            ? ProductSerializer::toArray($favorite->product, $user)
            : null;

        return $payload;
    }
}
