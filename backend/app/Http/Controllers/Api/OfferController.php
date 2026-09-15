<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\OfferSerializer;
use App\Support\ProductSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OfferController extends Controller
{
    public function index()
    {
        $offers = Offer::withCount('products')
            ->orderBy('display_order')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success('Offers retrieved successfully', OfferSerializer::collection($offers));
    }

    public function show(string $id)
    {
        $offer = Offer::with('products')->findOrFail($id);

        return ApiResponse::success('Offer retrieved successfully', OfferSerializer::toArray($offer));
    }

    public function store(Request $request)
    {
        $data = $this->validateOffer($request);
        $offer = Offer::create($this->offerFields($request, $data));
        $this->syncProducts($offer, $request->input('products'));
        $offer->load('products');

        return ApiResponse::success('Offer created successfully', OfferSerializer::toArray($offer), 201);
    }

    public function update(Request $request, string $id)
    {
        $offer = Offer::findOrFail($id);
        $data = $this->validateOffer($request, true);
        $offer->update($this->offerFields($request, $data));

        if ($request->has('products')) {
            $this->syncProducts($offer, $request->input('products'));
        }

        $offer->load('products');

        return ApiResponse::success('Offer updated successfully', OfferSerializer::toArray($offer));
    }

    public function destroy(string $id)
    {
        Offer::findOrFail($id)->delete();

        return ApiResponse::success('Offer deleted successfully');
    }

    public function publicIndex(Request $request)
    {
        $offers = Offer::currentlyActive()
            ->orderBy('display_order')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(
            'Offers retrieved successfully',
            OfferSerializer::publicCollection($offers, $request->user('sanctum'))
        );
    }

    public function publicShow(Request $request, string $id)
    {
        $offer = Offer::currentlyActive()->findOrFail($id);

        return ApiResponse::success(
            'Offer retrieved successfully',
            OfferSerializer::toPublicArray($offer, $request->user('sanctum'))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOffer(Request $request, bool $updating = false): array
    {
        $request->merge(['products' => $this->normalizeProducts($request->input('products'))]);

        if ($request->hasFile('image_cover')) {
            $file = $request->file('image_cover');
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages([
                    'image_cover' => ['Image upload failed. Check file size and type.'],
                ]);
            }
        }

        return $request->validate([
            'title' => ['nullable'],
            'subtitle' => ['nullable'],
            'heading' => ['nullable'],
            'button_text' => ['nullable'],
            'image_cover' => ['nullable'],
            'button_link' => ['nullable', 'string', 'max:500'],
            'discount_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'products' => ['sometimes', 'array'],
            'products.*.id' => ['required', 'uuid', 'exists:products,id'],
            'products.*.discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function offerFields(Request $request, array $data): array
    {
        $fields = [
            'title' => $this->decodeLocalized($request->input('title')),
            'subtitle' => $this->decodeLocalized($request->input('subtitle')),
            'heading' => $this->decodeLocalized($request->input('heading')),
            'button_text' => $this->decodeLocalized($request->input('button_text')),
            'button_link' => $data['button_link'] ?? null,
            'discount_percent' => $data['discount_percent'] ?? 0,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'display_order' => $data['display_order'] ?? 0,
        ];

        if ($request->hasFile('image_cover')) {
            $fields['image_cover'] = $this->storeImage($request->file('image_cover'));
        } elseif ($request->filled('image_cover')) {
            $fields['image_cover'] = $this->normalizeImageUrl((string) $request->input('image_cover'));
        }

        return $fields;
    }

    /**
     * @param  mixed  $products
     */
    private function syncProducts(Offer $offer, $products): void
    {
        if (! is_array($products)) {
            return;
        }

        $sync = [];

        foreach ($products as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;

            if (! $id) {
                continue;
            }

            $percent = is_array($item) ? ($item['discount_percent'] ?? null) : null;
            $sync[$id] = [
                'discount_percent' => $percent === null || $percent === '' ? null : (int) $percent,
            ];
        }

        $offer->products()->sync($sync);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProducts(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return array{en: string, ar: string}
     */
    private function decodeLocalized(mixed $value): array
    {
        if (is_array($value)) {
            return ProductSerializer::normalizeLocalized($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return ['en' => '', 'ar' => ''];
            }

            $decoded = json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return ProductSerializer::normalizeLocalized($decoded);
            }

            return ['en' => $trimmed, 'ar' => $trimmed];
        }

        return ['en' => '', 'ar' => ''];
    }

    private function storeImage(UploadedFile $file): string
    {
        $relativePath = 'uploads/'.$file->hashName();
        $file->storeAs('uploads', basename($relativePath), 'public');

        return Storage::disk('public')->url($relativePath);
    }

    private function normalizeImageUrl(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        return Storage::disk('public')->url(ltrim($trimmed, '/'));
    }
}
