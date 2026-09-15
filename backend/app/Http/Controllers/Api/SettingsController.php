<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HeroBanner;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\BannerSerializer;
use App\Support\ProductSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function appUpdate(Request $request)
    {
        $policy = $this->appUpdatePolicy();
        $active = (bool) ($policy['is_active'] ?? false);
        $platform = $this->detectPlatform($request);
        $language = strtolower(substr((string) $request->header('Accept-Language', 'en'), 0, 2)) === 'ar' ? 'ar' : 'en';
        $platformData = $policy[$platform] ?? ['minimum_version' => null, 'latest_version' => null, 'store_url' => null];
        $messages = $policy['messages'] ?? ['en' => ['title' => null, 'body' => null], 'ar' => ['title' => null, 'body' => null]];

        return response()->json([
            'status' => 'success',
            'message' => null,
            'pages' => 1,
            'data' => [
                'is_active' => $active,
                'force_update' => $active ? (bool) ($policy['force_update'] ?? false) : false,
                'platform' => $platform,
                'minimum_version' => $active ? ($platformData['minimum_version'] ?? null) : null,
                'latest_version' => $active ? ($platformData['latest_version'] ?? null) : null,
                'store_url' => $active ? ($platformData['store_url'] ?? null) : null,
                'message' => $active ? ($policy['message'] ?? $messages[$language]['title'] ?? null) : null,
                'description' => $active ? ($policy['description'] ?? $messages[$language]['body'] ?? null) : null,
                'messages' => $active ? $messages : ['en' => ['title' => null, 'body' => null], 'ar' => ['title' => null, 'body' => null]],
                'ios' => $active ? ($policy['ios'] ?? []) : ['minimum_version' => null, 'latest_version' => null, 'store_url' => null],
                'android' => $active ? ($policy['android'] ?? []) : ['minimum_version' => null, 'latest_version' => null, 'store_url' => null],
                'current_version' => $active ? ($platformData['latest_version'] ?? null) : null,
                'updated_at' => $active ? ($policy['updated_at'] ?? null) : null,
            ],
        ]);
    }

    public function getAppUpdatePolicy()
    {
        return ApiResponse::success('App update policy retrieved successfully', $this->appUpdatePolicy());
    }

    public function setAppUpdatePolicy(Request $request)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'force_update' => ['required', 'boolean'],
            'ios.minimum_version' => ['nullable', 'string', 'max:30'],
            'ios.latest_version' => ['nullable', 'string', 'max:30'],
            'ios.store_url' => ['nullable', 'url', 'max:2048'],
            'android.minimum_version' => ['nullable', 'string', 'max:30'],
            'android.latest_version' => ['nullable', 'string', 'max:30'],
            'android.store_url' => ['nullable', 'url', 'max:2048'],
            'messages.en.title' => ['nullable', 'string', 'max:255'],
            'messages.en.body' => ['nullable', 'string', 'max:2000'],
            'messages.ar.title' => ['nullable', 'string', 'max:255'],
            'messages.ar.body' => ['nullable', 'string', 'max:2000'],
        ]);

        $existing = $this->appUpdatePolicy();
        $policy = [
            'is_active' => (bool) $data['is_active'],
            'force_update' => (bool) $data['force_update'],
            'ios' => $data['ios'] ?? ['minimum_version' => null, 'latest_version' => null, 'store_url' => null],
            'android' => $data['android'] ?? ['minimum_version' => null, 'latest_version' => null, 'store_url' => null],
            'messages' => $data['messages'] ?? ($existing['messages'] ?? ['en' => ['title' => null, 'body' => null], 'ar' => ['title' => null, 'body' => null]]),
            'updated_at' => now()->toIso8601String(),
        ];

        DB::table('settings')->updateOrInsert(
            ['key' => 'app_update_policy'],
            ['value' => json_encode($policy, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]
        );

        return ApiResponse::success('App update policy saved successfully', $policy);
    }

    private function appUpdatePolicy(): array
    {
        $value = DB::table('settings')->where('key', 'app_update_policy')->value('value');
        $policy = is_string($value) ? json_decode($value, true) : null;
        return is_array($policy) ? $policy : [
            'is_active' => false,
            'force_update' => false,
            'ios' => ['minimum_version' => null, 'latest_version' => null, 'store_url' => null],
            'android' => ['minimum_version' => null, 'latest_version' => null, 'store_url' => null],
            'messages' => ['en' => ['title' => null, 'body' => null], 'ar' => ['title' => null, 'body' => null]],
            'updated_at' => null,
        ];
    }

    private function detectPlatform(Request $request): string
    {
        $value = strtolower((string) ($request->header('Device') ?? $request->header('X-Device-Platform') ?? $request->userAgent()));
        return str_contains($value, 'ios') || str_contains($value, 'iphone') || str_contains($value, 'ipad') ? 'ios' : 'android';
    }

    public function banners()
    {
        return $this->listByType(HeroBanner::TYPE_BANNER, 'Banners retrieved successfully');
    }

    public function saveBanner(Request $request)
    {
        return $this->storeByType($request, HeroBanner::TYPE_BANNER);
    }

    public function updateBanner(Request $request, string $id)
    {
        return $this->updateByType($request, $id, HeroBanner::TYPE_BANNER);
    }

    public function destroyBanner(string $id)
    {
        return $this->destroyByType($id, HeroBanner::TYPE_BANNER, 'Banner deleted successfully');
    }

    public function offers()
    {
        return $this->listByType(HeroBanner::TYPE_OFFER, 'Offers retrieved successfully');
    }

    public function saveOffer(Request $request)
    {
        return $this->storeByType($request, HeroBanner::TYPE_OFFER, 'Offer');
    }

    public function updateOffer(Request $request, string $id)
    {
        return $this->updateByType($request, $id, HeroBanner::TYPE_OFFER, 'Offer');
    }

    public function destroyOffer(string $id)
    {
        return $this->destroyByType($id, HeroBanner::TYPE_OFFER, 'Offer deleted successfully');
    }

    public function featured(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'is_featured' => ['required', 'boolean'],
        ]);

        Product::whereKey($data['product_id'])->update(['is_featured' => $data['is_featured']]);

        return ApiResponse::success('Featured status updated successfully', Product::find($data['product_id']));
    }

    public function offerProducts(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'is_offer' => ['required', 'boolean'],
        ]);

        Product::whereKey($data['product_id'])->update(['is_offer' => $data['is_offer']]);

        return ApiResponse::success('Offer product status updated successfully', Product::find($data['product_id']));
    }

    public function deliveryFee()
    {
        return ApiResponse::success('Delivery fee retrieved successfully', $this->deliveryFeeSettings());
    }

    public function publicDeliveryFees()
    {
        $settings = $this->deliveryFeeSettings();

        return ApiResponse::success('Delivery fees retrieved successfully', [
            'usd' => $settings['delivery_fee_usd'],
            'currency' => $settings['currency'],
            'pickup_available' => true,
        ]);
    }

    public function setDeliveryFee(Request $request)
    {
        $data = $request->validate([
            'delivery_fee' => ['sometimes', 'numeric', 'min:0'],
            'delivery_fee_usd' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $usd = $data['delivery_fee_usd'] ?? $data['delivery_fee'] ?? null;

        if ($usd !== null) {
            foreach (['delivery_fee', 'delivery_fee_usd'] as $key) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $usd, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        return ApiResponse::success('Delivery fee updated successfully', $this->deliveryFeeSettings());
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryFeeSettings(): array
    {
        $settings = DB::table('settings')
            ->whereIn('key', ['delivery_fee', 'delivery_fee_usd', 'currency'])
            ->pluck('value', 'key');

        $usd = $settings['delivery_fee_usd'] ?? $settings['delivery_fee'] ?? 0;

        return [
            'delivery_fee' => (float) $usd,
            'delivery_fee_usd' => (float) $usd,
            'currency' => $settings['currency'] ?? 'USD',
        ];
    }

    public function updateEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users,email,'.$request->user()->id],
        ]);
        $request->user()->update($data);

        return ApiResponse::success('Email updated successfully', $request->user()->fresh());
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'new_password' => ['required', 'string', 'min:10', 'max:255', 'same:confirm_password'],
            'confirm_password' => ['required', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $user->update(['password_hash' => Hash::make($data['new_password'])]);
        $user->tokens()->delete();

        return ApiResponse::success('Password updated successfully');
    }

    private function listByType(string $type, string $message)
    {
        $items = HeroBanner::ofType($type)->orderBy('display_order')->orderByDesc('created_at')->get();

        return ApiResponse::success($message, BannerSerializer::collection($items));
    }

    private function storeByType(Request $request, string $type, string $label = 'Banner')
    {
        $data = $this->validatePromo($request);
        $id = $data['id'] ?? (string) Str::uuid();
        unset($data['id']);
        $data['type'] = $type;
        $data = $this->applyLocalizedFields($data);
        $data = $this->applyImage($request, $data);

        $item = HeroBanner::updateOrCreate(['id' => $id], $data + [
            'display_order' => $data['display_order'] ?? ((int) HeroBanner::ofType($type)->max('display_order') + 1),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        $created = $item->wasRecentlyCreated;

        return ApiResponse::success(
            $created ? "{$label} created successfully" : "{$label} updated successfully",
            BannerSerializer::toArray($item->fresh()),
            $created ? 201 : 200
        );
    }

    private function updateByType(Request $request, string $id, string $type, string $label = 'Banner')
    {
        $item = HeroBanner::ofType($type)->whereKey($id)->firstOrFail();
        $data = $this->validatePromo($request, updating: true);
        unset($data['id']);
        $data['type'] = $type;
        $data = $this->applyLocalizedFields($data);
        $data = $this->applyImage($request, $data);

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $data['is_active'];
        }

        $item->update($data);

        return ApiResponse::success("{$label} updated successfully", BannerSerializer::toArray($item->fresh()));
    }

    private function destroyByType(string $id, string $type, string $message)
    {
        HeroBanner::ofType($type)->whereKey($id)->firstOrFail()->delete();

        return ApiResponse::success($message);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyLocalizedFields(array $data): array
    {
        foreach (['subtitle', 'heading', 'title', 'button_text'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $data[$field] = $this->decodeLocalizedField($data[$field]);
        }

        return $data;
    }

    /**
     * @return array{en: string, ar: string}
     */
    private function decodeLocalizedField(mixed $value): array
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyImage(Request $request, array $data): array
    {
        if ($request->hasFile('image_cover')) {
            $data['image_cover'] = $this->storeBannerImage($request->file('image_cover'));
        } elseif (array_key_exists('image_cover', $data) && is_string($data['image_cover']) && $data['image_cover'] !== '') {
            $data['image_cover'] = $this->normalizeImageUrl($data['image_cover']);
        } else {
            unset($data['image_cover']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePromo(Request $request, bool $updating = false): array
    {
        if ($request->hasFile('image_cover')) {
            $file = $request->file('image_cover');
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages([
                    'image_cover' => ['Image upload failed. Check file size and type.'],
                ]);
            }
        }

        $imageRules = $updating ? ['sometimes', 'nullable'] : ['nullable'];

        if ($request->hasFile('image_cover')) {
            $imageRules = ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'];
        } elseif ($request->filled('image_cover')) {
            $imageRules = ['nullable', 'string', 'max:1000'];
        }

        $bool = $request->input('is_active');
        if (is_string($bool)) {
            $request->merge([
                'is_active' => filter_var($bool, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        return $request->validate([
            'id' => ['nullable', 'uuid'],
            'image_cover' => $imageRules,
            'subtitle' => ['nullable'],
            'heading' => ['nullable'],
            'title' => ['nullable'],
            'button_text' => ['nullable'],
            'button_link' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    private function storeBannerImage(UploadedFile $file): string
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
