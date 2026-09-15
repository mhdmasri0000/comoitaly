<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\ProductPricing;
use App\Support\ProductSerializer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    private const PRODUCT_WITH = ['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers'];

    public function index(Request $request)
    {
        $items = CartItem::with(['product' => fn ($q) => $q->with(self::PRODUCT_WITH)])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (CartItem $item) => $this->serializeCartItem($item));

        return ApiResponse::success('Cart retrieved successfully', $items->values()->all());
    }

    public function store(Request $request)
    {
        $request->merge(['product_id' => $request->input('product_id', $request->input('productId'))]);
        $data = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'size' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reserved_on' => ['nullable', 'date'],
            'reserved_until' => ['nullable', 'date', 'after_or_equal:reserved_on'],
        ]);

        $product = Product::with(self::PRODUCT_WITH)->findOrFail($data['product_id']);
        $user = $request->user();
        $userId = $user->id;
        $size = isset($data['size']) ? trim((string) $data['size']) : null;
        $color = isset($data['color']) ? trim((string) $data['color']) : null;

        $existingItem = CartItem::where('user_id', $userId)
            ->where('product_id', $product->id)
            ->when($size !== null && $size !== '', fn ($query) => $query->where('size', $size))
            ->when($color !== null && $color !== '', fn ($query) => $query->where('color', $color))
            ->first();

        $targetQuantity = ($existingItem?->quantity ?? 0) + (int) $data['quantity'];
        $this->assertQuantityAllowed($user, $product, $targetQuantity);
        $unitPrice = ProductPricing::priceFor($product, $user);

        if ($existingItem) {
            $existingItem->quantity = $targetQuantity;
            $existingItem->total_price = $unitPrice * $targetQuantity;
            $existingItem->save();
            $existingItem->setRelation('product', $product);

            return ApiResponse::success('Cart item quantity updated successfully', $this->serializeCartItem($existingItem, $user), 200);
        }

        $data += [
            'user_id' => $userId,
            'id' => (string) Str::uuid(),
            'total_price' => $unitPrice * $targetQuantity,
        ];

        $item = CartItem::create($data);
        $item->setRelation('product', $product);

        return ApiResponse::success('Cart item added successfully', $this->serializeCartItem($item, $user), 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $item = CartItem::with(['product' => fn ($q) => $q->with(self::PRODUCT_WITH)])
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->firstOrFail();

        $data = $request->validate([
            'size' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ]);

        $targetQuantity = isset($data['quantity']) ? (int) $data['quantity'] : (int) $item->quantity;

        if ($item->product) {
            $this->assertQuantityAllowed($user, $item->product, $targetQuantity);
        }

        if (isset($data['quantity'])) {
            $data['total_price'] = ProductPricing::priceFor($item->product, $user) * $targetQuantity;
        }

        $item->update($data);
        $item->load(['product' => fn ($q) => $q->with(self::PRODUCT_WITH)]);

        return ApiResponse::success('Cart item updated successfully', $this->serializeCartItem($item, $user));
    }

    public function destroy(Request $request, string $id)
    {
        CartItem::where('user_id', $request->user()->id)->whereKey($id)->firstOrFail()->delete();

        return ApiResponse::success('Cart item removed successfully');
    }

    private function assertQuantityAllowed(User $user, Product $product, int $quantity): void
    {
        $stock = (int) $product->quantity;

        if ($quantity > $stock) {
            throw ValidationException::withMessages([
                'quantity' => ["Requested quantity ({$quantity}) exceeds available stock ({$stock})."],
            ]);
        }

        if ($user->role === 'dealer') {
            $minimum = max(1, (int) $product->min_order_quantity);

            if ($quantity < $minimum) {
                throw ValidationException::withMessages([
                    'quantity' => ["Minimum order quantity for this product is {$minimum}."],
                ]);
            }
        }
    }

    private function serializeCartItem(CartItem $item, ?User $user = null): array
    {
        $payload = $item->toArray();
        $payload['product'] = $item->product
            ? ProductSerializer::toArray($item->product, $user)
            : null;

        return $payload;
    }
}
