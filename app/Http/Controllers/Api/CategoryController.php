<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\ApiResponse;
use App\Support\CategorySerializer;
use App\Support\ProductSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->latest()->get();

        return ApiResponse::success(
            'Categories retrieved successfully',
            CategorySerializer::collection($categories)
        );
    }

    public function show(Request $request, Category $category)
    {
        $category->loadCount('products');
        $category->load([
            'products' => fn ($q) => $q
                ->with(['category', 'images', 'sizes', 'colors', 'measurements', 'tags', 'offers'])
                ->latest(),
        ]);

        return ApiResponse::success(
            'Category retrieved successfully',
            CategorySerializer::toArray($category, withProducts: true, user: $request->user('sanctum'))
        );
    }

    public function store(Request $request)
    {
        $this->assertValidImageUpload($request, 'image_cover', required: true);

        $data = $request->validate([
            'name' => ['required'],
            'image_cover' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ]);

        $localizedName = $this->decodeLocalizedField($data['name']);
        if ($localizedName['en'] === '' || $localizedName['ar'] === '') {
            throw ValidationException::withMessages([
                'name' => ['Category name requires both Arabic and English values.'],
            ]);
        }

        $category = Category::create([
            'id' => (string) Str::uuid(),
            'name' => $localizedName,
            'image_cover' => $this->storeCoverImage($request->file('image_cover')),
        ]);

        return ApiResponse::success(
            'Category created successfully',
            CategorySerializer::toArray($category->loadCount('products')),
            201
        );
    }

    public function update(Request $request, Category $category)
    {
        if ($request->hasFile('image_cover') || $request->exists('image_cover')) {
            $this->assertValidImageUpload($request, 'image_cover', required: false);
        }

        $data = $request->validate([
            'name' => ['sometimes'],
            'image_cover' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ]);

        $payload = [];

        if (array_key_exists('name', $data)) {
            $localizedName = $this->decodeLocalizedField($data['name']);
            if ($localizedName['en'] === '' || $localizedName['ar'] === '') {
                throw ValidationException::withMessages([
                    'name' => ['Category name requires both Arabic and English values.'],
                ]);
            }
            $payload['name'] = $localizedName;
        }

        if ($request->hasFile('image_cover')) {
            $payload['image_cover'] = $this->storeCoverImage($request->file('image_cover'));
        }

        if ($payload !== []) {
            $category->update($payload);
        }

        return ApiResponse::success(
            'Category updated successfully',
            CategorySerializer::toArray($category->fresh()->loadCount('products'))
        );
    }

    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return ApiResponse::error(
                'Cannot delete a category that still contains products.',
                ['category' => ['Move or delete the products in this category first.']],
                409
            );
        }

        $category->delete();

        return ApiResponse::success('Category deleted successfully');
    }

    /**
     * @return array{en: string, ar: string}
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

    protected function storeCoverImage(UploadedFile $file): string
    {
        $relativePath = 'uploads/'.$file->hashName();
        $file->storeAs('uploads', basename($relativePath), 'public');

        return Storage::disk('public')->url($relativePath);
    }

    protected function assertValidImageUpload(Request $request, string $field, bool $required): void
    {
        if (! $request->hasFile($field)) {
            if ($required) {
                throw ValidationException::withMessages([
                    $field => 'A cover image file is required (jpg, jpeg, png, webp). Max 10MB.',
                ]);
            }

            return;
        }

        $file = $request->file($field);
        if ($file && $file->isValid()) {
            return;
        }

        $errorCode = $file?->getError() ?? UPLOAD_ERR_NO_FILE;
        $message = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The cover image is too large. Max allowed is 10MB (check PHP upload limits).',
            UPLOAD_ERR_PARTIAL => 'The cover image was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'A cover image file is required.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder is missing. Cannot save uploaded image.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded image to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the image upload.',
            default => 'The cover image failed to upload. Use jpg/png/webp under 10MB.',
        };

        throw ValidationException::withMessages([$field => $message]);
    }
}
