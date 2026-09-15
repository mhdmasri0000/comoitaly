<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\OrderSerializer;
use App\Support\ProductPricing;
use App\Support\ProductSerializer;
use App\Services\AdminNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    private const PRODUCT_WITH = ['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers'];

    public function checkout(Request $request)
    {
        $request->merge(['delivery_method' => $request->input('delivery_method', 'delivery')]);

        $data = $request->validate([
            'delivery_address' => ['nullable', 'string', 'max:1000', 'required_if:delivery_method,delivery'],
            'note' => ['nullable', 'string', 'max:2000'],
            'payment_type' => ['required', 'string', 'in:cash,shamcash'],
            'delivery_method' => ['required', 'string', 'in:delivery,pickup'],
            'payment_proof_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $userId = $user->id;

        $order = DB::transaction(function () use ($data, $request, $userId, $user) {
            $items = CartItem::where('user_id', $userId)
                ->lockForUpdate()
                ->get();

            abort_if($items->isEmpty(), 400, 'Cart is empty');

            $products = Product::with(self::PRODUCT_WITH)
                ->whereIn('id', $items->pluck('product_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0.0;
            $lines = [];

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'cart' => ['A product in your cart is no longer available.'],
                    ]);
                }

                $quantity = (int) $item->quantity;

                if ($quantity > (int) $product->quantity) {
                    throw ValidationException::withMessages([
                        'cart' => ["Only {$product->quantity} left in stock for {$this->productLabel($product)}."],
                    ]);
                }

                if ($user->role === 'dealer' && $quantity < max(1, (int) $product->min_order_quantity)) {
                    throw ValidationException::withMessages([
                        'cart' => ["Minimum order quantity for {$this->productLabel($product)} is {$product->min_order_quantity}."],
                    ]);
                }

                $unitPrice = ProductPricing::priceFor($product, $user);
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $lines[] = [
                    'item' => $item,
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                ];
            }

            $deliveryMethod = ($data['delivery_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
            $deliveryAddress = $data['delivery_address'] ?? null;
            if ($deliveryMethod === 'pickup' && ($deliveryAddress === null || trim((string) $deliveryAddress) === '')) {
                $deliveryAddress = 'Pickup from store';
            }

            $feeUsd = DB::table('settings')->where('key', 'delivery_fee_usd')->value('value');
            if ($feeUsd === null || $feeUsd === '') {
                $feeUsd = DB::table('settings')->where('key', 'delivery_fee')->value('value');
            }
            $deliveryFee = $deliveryMethod === 'pickup' ? 0.0 : (float) ($feeUsd ?? 0);

            $orderData = collect($data)->except('payment_proof_image')->all();
            $orderData['delivery_address'] = $deliveryAddress;
            $orderData += [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'subtotal' => $subtotal,
                'delivery_method' => $deliveryMethod,
                'delivery_fee' => $deliveryFee,
                'total' => 0,
                'status' => 'pending',
            ];

            if ($request->hasFile('payment_proof_image')) {
                $path = $request->file('payment_proof_image')->store('uploads', 'public');
                $orderData['payment_proof_image'] = Storage::disk('public')->url($path);
            }

            $orderData['total'] = $orderData['subtotal'] + $orderData['delivery_fee'];
            $order = Order::create($orderData);

            foreach ($lines as $line) {
                OrderItem::create([
                    'id' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'product_name' => json_encode($line['product']->name),
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                    'quantity' => $line['quantity'],
                    'size' => $line['item']->size,
                    'color' => $line['item']->color,
                ]);

                $line['product']->decrement('quantity', $line['quantity']);
            }

            CartItem::where('user_id', $userId)->delete();

            return $order->load(['items.product' => fn ($q) => $q->with(self::PRODUCT_WITH)]);
        });

        AdminNotifier::orderCreated($order, $user);
        foreach ($order->items as $item) {
            if ($item->product) {
                AdminNotifier::syncStock($item->product);
            }
        }

        return ApiResponse::success('Order created successfully', OrderSerializer::toArray($order, $user), 201);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        $orders = Order::with(['items.product' => fn ($q) => $q->with(self::PRODUCT_WITH)])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit($data['limit'] ?? 50)
            ->offset($data['offset'] ?? 0)
            ->get()
            ->map(fn (Order $order) => OrderSerializer::toArray($order, $request->user()));

        return ApiResponse::success('Orders retrieved successfully', $orders->values()->all());
    }

    public function show(Request $request, string $id)
    {
        $order = Order::with(['items.product' => fn ($q) => $q->with(self::PRODUCT_WITH)])
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        return ApiResponse::success('Order retrieved successfully', OrderSerializer::toArray($order, $request->user()));
    }

    private function productLabel(Product $product): string
    {
        $name = ProductSerializer::normalizeLocalized($product->name);

        return $name['en'] !== '' ? $name['en'] : ($name['ar'] !== '' ? $name['ar'] : 'this product');
    }
}
