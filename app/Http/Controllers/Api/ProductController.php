<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\ProductMeasurement;
use App\Models\ProductSize;
use App\Models\ProductTag;
use App\Models\OrderItem;
use App\Support\ApiResponse;
use App\Support\ProductSerializer;
use App\Services\AdminNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'category_id' => ['sometimes', 'uuid', 'exists:categories,id'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_offer' => ['sometimes', 'boolean'],
            'min_price' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99', 'gte:min_price'],
            'sort_by' => ['sometimes', 'in:price_asc,price_desc,highest_rated'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
        $query = Product::with(['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers']);
        foreach (['category_id', 'is_featured', 'is_offer'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        if (array_key_exists('min_price', $filters)) $query->where('price', '>=', $filters['min_price']);
        if (array_key_exists('max_price', $filters)) $query->where('price', '<=', $filters['max_price']);
        match ($filters['sort_by'] ?? null) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'highest_rated' => $query->orderByDesc('rating'),
            default => $query->latest(),
        };

        return ApiResponse::success(
            'Products retrieved successfully',
            ProductSerializer::collection($query->limit($filters['limit'] ?? 50)->offset($filters['offset'] ?? 0)->get(), $request->user('sanctum'))
        );
    }

    public function show(Request $request, Product $product)
    {
        return ApiResponse::success(
            'Product retrieved successfully',
            ProductSerializer::toArray($product->load(['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers']), $request->user('sanctum'))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'name' => ['required'],
            'description' => ['required'],
            'price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'min_order_quantity' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_offer' => ['sometimes', 'boolean'],
            'cover' => ['sometimes', 'nullable'],
            'gallery' => ['sometimes', 'nullable', 'array'],
            'sizes' => ['sometimes', 'array'],
            'colors' => ['sometimes', 'array'],
            'measurements' => ['sometimes', 'array'],
            'tags' => ['sometimes', 'array'],
        ]);

        $data['name'] = $this->decodeLocalizedField($data['name']);
        $data['description'] = $this->decodeLocalizedField($data['description']);
        $data['id'] = (string) Str::uuid();

        $product = Product::create(collect($data)->only([
            'id', 'category_id', 'name', 'description', 'price', 'quantity', 'min_order_quantity', 'rating', 'is_featured', 'is_offer',
        ])->all());

        $this->syncProductRelations($product, $request->all(), $request);

        AdminNotifier::syncStock($product->fresh());

        return ApiResponse::success(
            'Product created successfully',
            ProductSerializer::toArray($product->fresh()->load(['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers']), $request->user('sanctum')),
            201
        );
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'category_id' => ['sometimes', 'uuid', 'exists:categories,id'],
            'name' => ['sometimes'],
            'description' => ['sometimes', 'nullable'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'min_order_quantity' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_offer' => ['sometimes', 'boolean'],
            'cover' => ['sometimes', 'nullable'],
            'gallery' => ['sometimes', 'nullable', 'array'],
            'sizes' => ['sometimes', 'array'],
            'colors' => ['sometimes', 'array'],
            'measurements' => ['sometimes', 'array'],
            'tags' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->decodeLocalizedField($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = $this->decodeLocalizedField($data['description']);
        }

        // Only persist real product columns — never merge raw multipart payload
        // (raw `name` JSON string was overwriting the decoded array and double-encoding on every edit)
        $product->update(collect($data)->only([
            'category_id', 'name', 'description', 'price', 'quantity', 'min_order_quantity', 'rating', 'is_featured', 'is_offer',
        ])->all());

        $this->syncProductRelations($product, $request->all(), $request);

        AdminNotifier::syncStock($product->fresh());

        return ApiResponse::success(
            'Product updated successfully',
            ProductSerializer::toArray($product->fresh()->load(['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers']), $request->user('sanctum'))
        );
    }

    public function destroy(Product $product)
    {
        if (OrderItem::where('product_id', $product->id)->exists()) {
            return ApiResponse::error(
                'Cannot delete a product that is included in an order.',
                ['product' => ['The product is part of order history and must be kept.']],
                409
            );
        }

        AdminNotifier::resolveStockAlerts($product->id);
        $product->delete();
        return ApiResponse::success('Product deleted successfully');
    }

    /**
     * Decode multipart JSON strings / plain strings into ['en' => ..., 'ar' => ...].
     */
    protected function decodeLocalizedField(mixed $value): array
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

    protected function syncProductRelations(Product $product, array $payload, Request $request): void
    {
        if (array_key_exists('sizes', $payload)) {
            $product->sizes()->delete();
            $seen = [];
            foreach ($this->normalizeRelationItems($payload['sizes'] ?? []) as $size) {
                $sizeLabel = $this->extractString($size, 'size_label', 'label', 'name');
                if ($sizeLabel === null || $sizeLabel === '' || isset($seen[$sizeLabel])) {
                    continue;
                }
                $seen[$sizeLabel] = true;
                $product->sizes()->create(['size_label' => $sizeLabel]);
            }
        }

        if (array_key_exists('colors', $payload)) {
            $product->colors()->delete();
            $seen = [];
            foreach ($this->normalizeRelationItems($payload['colors'] ?? []) as $color) {
                $colorName = $this->extractString($color, 'color_name', 'name');
                $colorHex = $this->extractString($color, 'color_hex', 'hex');
                if ($colorName === null && $colorHex === null) {
                    continue;
                }
                $key = strtolower(($colorName ?? '') . '|' . ($colorHex ?? ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $product->colors()->create([
                    'color_name' => $colorName ?: 'Custom',
                    'color_hex' => $colorHex,
                ]);
            }
        }

        if (array_key_exists('measurements', $payload)) {
            $product->measurements()->delete();
            $seen = [];
            foreach ($this->normalizeRelationItems($payload['measurements'] ?? []) as $measurement) {
                $measurementValue = $this->normalizeMeasurementValue($measurement);
                if ($measurementValue === null || $measurementValue === '' || isset($seen[$measurementValue])) {
                    continue;
                }
                $seen[$measurementValue] = true;
                $product->measurements()->create(['measurement' => $measurementValue]);
            }
        }

        if (array_key_exists('tags', $payload)) {
            $product->tags()->delete();
            $seen = [];
            foreach ($this->normalizeRelationItems($payload['tags'] ?? []) as $tag) {
                $tagValue = $this->extractString($tag, 'tag', 'name', 'label');
                if ($tagValue === null || $tagValue === '' || isset($seen[$tagValue])) {
                    continue;
                }
                $seen[$tagValue] = true;
                $product->tags()->create(['tag' => $tagValue]);
            }
        }

        $coverFile = $request->file('cover');
        $galleryFiles = $request->file('gallery', []);
        $hasImageUpload = $coverFile !== null || !empty($galleryFiles);

        if ($hasImageUpload || $request->exists('cover') || $request->exists('gallery')) {
            $product->images()->delete();

            $coverValue = $coverFile ?? $request->input('cover');
            $coverPath = $this->storeImageValue($request, 'cover', $coverValue);
            $coverColor = $request->input('cover_color');
            $coverColor = is_string($coverColor) && trim($coverColor) !== '' ? trim($coverColor) : null;
            $seenUrls = [];
            if ($coverPath !== null) {
                $product->images()->create(['image_url' => $coverPath, 'is_primary' => true, 'color_name' => $coverColor]);
                $seenUrls[$coverPath] = true;
            }

            $galleryInput = $request->input('gallery', []);
            $galleryInput = is_array($galleryInput) ? $galleryInput : [$galleryInput];
            $galleryFileInput = $request->file('gallery', []);
            $galleryFileInput = is_array($galleryFileInput) ? $galleryFileInput : [$galleryFileInput];

            $keys = array_values(array_unique(array_merge(array_keys($galleryInput), array_keys($galleryFileInput))));
            sort($keys);

            foreach ($keys as $key) {
                $input = $galleryInput[$key] ?? null;
                $file = $galleryFileInput[$key] ?? null;

                if (is_array($file)) {
                    $file = $file['image'] ?? $file['file'] ?? null;
                }

                if ($file instanceof UploadedFile) {
                    $rawValue = $file;
                } elseif (is_array($input)) {
                    $rawValue = $input['image_url'] ?? $input['url'] ?? $input['path'] ?? $input['value'] ?? null;
                } else {
                    $rawValue = $input;
                }

                $colorName = is_array($input) ? $this->extractString($input, 'color_name', 'color') : null;

                $imagePath = $this->storeImageValue($request, 'gallery', $rawValue, true);
                if ($imagePath === null || isset($seenUrls[$imagePath])) {
                    continue;
                }
                $seenUrls[$imagePath] = true;
                $product->images()->create([
                    'image_url' => $imagePath,
                    'is_primary' => false,
                    'color_name' => $colorName,
                ]);
            }
        }
    }

    /**
     * Keep associative relation rows intact (e.g. color_name/color_hex) instead of flattening them.
     */
    protected function normalizeRelationItems(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if ($value instanceof UploadedFile) {
            return [$value];
        }

        if (!is_array($value)) {
            return [$value];
        }

        if ($value === []) {
            return [];
        }

        // Associative object row: { size_label: "M" } or { color_name, color_hex }
        if (!array_is_list($value)) {
            return [$value];
        }

        $items = [];
        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            if ($item instanceof UploadedFile || is_string($item) || is_numeric($item)) {
                $items[] = $item;
                continue;
            }
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    protected function normalizeArrayValues(mixed $value): array
    {
        return $this->normalizeRelationItems($value);
    }

    protected function normalizeMeasurementValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $label = $this->extractString($value, 'label', 'name');
            $numericValue = $this->extractString($value, 'value', 'measurement');

            if ($label !== null && $numericValue !== null && $numericValue !== '') {
                return $label . ':' . $numericValue;
            }

            if ($label !== null && $label !== '') {
                return $label;
            }

            return $numericValue;
        }

        $raw = $this->extractString($value, 'measurement', 'label', 'name', 'value');
        if ($raw === null) {
            return null;
        }

        if (str_contains($raw, ':')) {
            return $raw;
        }

        return $raw;
    }

    protected function extractString(mixed $value, string ...$keys): ?string
    {
        if (is_array($value)) {
            foreach ($keys as $key) {
                if (isset($value[$key]) && (is_string($value[$key]) || is_numeric($value[$key]))) {
                    $trimmed = trim((string) $value[$key]);
                    return $trimmed === '' ? null : $trimmed;
                }
            }

            return null;
        }

        if (is_string($value) || is_numeric($value)) {
            $trimmed = trim((string) $value);
            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    protected function storeImageValue(Request $request, string $fieldName, mixed $value, bool $isGallery = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof UploadedFile) {
            $relativePath = 'uploads/' . $value->hashName();
            $value->storeAs('uploads', basename($relativePath), 'public');
            return Storage::disk('public')->url($relativePath);
        }

        if (is_string($value)) {
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                return $value;
            }

            return Storage::disk('public')->url(ltrim($value, '/'));
        }

        return null;
    }
}