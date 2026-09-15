<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationBroadcast;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\FirebaseCloudMessaging;
use App\Support\ApiResponse;
use App\Support\OrderSerializer;
use App\Rules\SyrianPhoneNumber;
use App\Services\AdminNotifier;
use App\Services\CustomerNotifier;
use App\Services\PaymentProofCleaner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function createUser(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => ['required', 'string', 'max:30', 'unique:users,phone_number', new SyrianPhoneNumber()],
            'password' => ['nullable', 'string', 'min:10', 'max:255', 'same:confirm_password'],
            'confirm_password' => ['nullable', 'string', 'max:255', 'required_with:password'],
            'role' => ['sometimes', 'in:admin,user,dealer'],
            'dealer_price_factor' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $generatedEmail = empty($data['email']);
        if ($generatedEmail) {
            $data['email'] = $this->generateUniqueEmail($data['first_name'].' '.$data['last_name']);
        }

        $generatedPassword = empty($data['password']);
        if ($generatedPassword) {
            $data['password'] = Str::random(12);
        }

        $plainPassword = $data['password'];

        $data['id'] = (string) Str::uuid();
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password'], $data['confirm_password']);

        $data['credentials_generated'] = $generatedEmail || $generatedPassword;
        $user = User::create($data + ['role' => 'user']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return ApiResponse::success('User created successfully', [
            'user' => $user,
            'generated' => $generatedEmail || $generatedPassword,
            'credentials' => [
                'email' => $user->email,
                'password' => $plainPassword,
            ],
        ], 201);
    }

    public function resetCredentials(string $id)
    {
        $user = User::findOrFail($id);

        if (! $user->credentials_generated) {
            return ApiResponse::error('This account does not use system-generated credentials.', null, 400);
        }

        $password = Str::random(12);
        $user->email = $this->generateUniqueEmail($user->first_name.' '.$user->last_name, $user->id);
        $user->password_hash = Hash::make($password);
        $user->credentials_generated = true;
        $user->save();
        $user->tokens()->delete();

        return ApiResponse::success('User credentials reset successfully', [
            'user' => $user->fresh(),
            'generated' => true,
            'credentials' => [
                'email' => $user->email,
                'password' => $password,
            ],
        ]);
    }

    protected function generateUniqueEmail(string $name, ?string $ignoreUserId = null): string
    {
        $base = Str::slug($name, '.') ?: 'user';
        $domain = 'como.app';
        $email = $base.'@'.$domain;
        $suffix = 1;

        while (User::where('email', $email)->when($ignoreUserId !== null, fn ($q) => $q->where('id', '!=', $ignoreUserId))->exists()) {
            $suffix++;
            $email = $base.$suffix.'@'.$domain;
        }

        return $email;
    }

    public function users(Request $request)
    {
        $data = $request->validate(['role'=>['nullable','in:admin,user,dealer'],'search'=>['nullable','string','max:100'],'limit'=>['nullable','integer','min:1','max:100'],'offset'=>['nullable','integer','min:0']]);
        $query = User::query()->when(isset($data['role']), fn ($q) => $q->where('role', $data['role']))->when(isset($data['search']), fn ($q) => $q->where(fn ($x) => $x->where('email','like','%'.$data['search'].'%')->orWhere('first_name','like','%'.$data['search'].'%')));
        return ApiResponse::success('Users retrieved successfully', $query->offset($data['offset'] ?? 0)->limit($data['limit'] ?? 50)->get());
    }

    public function showUser(string $id)
    {
        return ApiResponse::success('User retrieved successfully', User::findOrFail($id));
    }

    public function role(Request $request, string $id)
    {
        $data = $request->validate(['role'=>['sometimes','in:dealer'],'dealer_price_factor'=>['nullable','numeric','min:0','max:1']]);
        $user = User::findOrFail($id); $user->update(['role'=>'dealer','dealer_price_factor'=>$data['dealer_price_factor'] ?? null]);
        return ApiResponse::success('User role updated successfully', $user);
    }

    public function orders(Request $request)
    {
        $orders = Order::with(['user', 'items.product.images'])->latest()->get();

        return ApiResponse::success(
            'Orders retrieved successfully',
            OrderSerializer::collection($orders, $request->user(), false)
        );
    }

    public function recentOrders(Request $request)
    {
        $limit = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']])['limit'] ?? 5;
        $orders = Order::latest()->limit($limit)->get()->each(function (Order $order) {
            $order->setAttribute('payment_proof_image', \App\Support\MediaUrl::toRelative($order->payment_proof_image));
        });

        return ApiResponse::success('Recent orders retrieved successfully', $orders);
    }

    public function status(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,approved,confirmed,processing,shipped,delivered,cancelled'],
            'cancel_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $order = Order::with('items')->findOrFail($id);
        $previousStatus = $order->status;

        DB::transaction(function () use ($order, $data, $previousStatus) {
            if ($data['status'] === 'cancelled' && $previousStatus !== 'cancelled') {
                foreach ($order->items as $item) {
                    if ($item->product_id) {
                        Product::whereKey($item->product_id)->increment('quantity', (int) $item->quantity);
                    }
                }
            }

            $order->status = $data['status'];
            $order->cancel_reason = $data['status'] === 'cancelled'
                ? (($data['cancel_reason'] ?? null) ?: $order->cancel_reason)
                : null;
            if ($data['status'] === 'delivered' && ! $order->delivered_at) {
                $order->delivered_at = now();
            }
            $order->save();
        });

        if ($data['status'] === 'cancelled') {
            foreach ($order->fresh()->items as $item) {
                if ($item->product) {
                    AdminNotifier::syncStock($item->product);
                }
            }
        }

        $order->refresh();

        if (in_array($data['status'], ['delivered', 'cancelled'], true)) {
            app(PaymentProofCleaner::class)->clear($order);
        }

        app(CustomerNotifier::class)->orderStatusChanged($order, $previousStatus);

        return ApiResponse::success('Order status updated successfully', $order->fresh());
    }

    public function broadcast(Request $request, FirebaseCloudMessaging $fcm)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'type' => ['nullable', 'string', 'max:50'],
            'ref_id' => ['nullable', 'uuid'],
            'audience' => ['nullable', 'in:customers,dealers,all'],
            'platform' => ['nullable', 'in:all,ios,android'],
        ]);

        $audience = $data['audience'] ?? 'customers';
        $platform = $data['platform'] ?? 'all';
        $type = $data['type'] ?? 'broadcast';
        $refId = $data['ref_id'] ?? null;

        $roles = match ($audience) {
            'dealers' => ['dealer'],
            'all' => ['user', 'dealer', 'admin'],
            default => ['user', 'dealer'],
        };

        $usersQuery = User::query()->whereIn('role', $roles);
        $recipientCount = 0;
        $usersQuery->orderBy('id')->chunkById(200, function ($users) use ($data, $type, $refId, &$recipientCount) {
            foreach ($users as $user) {
                UserNotification::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'title' => $data['title'],
                    'body' => $data['body'],
                    'type' => $type,
                    'ref_id' => $refId,
                    'is_read' => false,
                ]);
                $recipientCount++;
            }
        });

        $push = [
            'configured' => false,
            'sent' => 0,
            'failed' => 0,
            'topic_sent' => false,
            'invalid_tokens_cleared' => 0,
            'error' => null,
        ];

        try {
            if ($fcm->isConfigured()) {
                $push['configured'] = true;

                $tokenQuery = User::query()
                    ->whereIn('role', $roles)
                    ->whereNotNull('fcm_token')
                    ->where('fcm_token', '!=', '');

                if ($platform === 'ios' || $platform === 'android') {
                    $tokenQuery->where('fcm_platform', $platform);
                }

                $tokens = $tokenQuery->pluck('fcm_token')->all();

                $result = $fcm->sendToTokens(
                    $tokens,
                    $data['title'],
                    $data['body'],
                    array_filter([
                        'type' => $type,
                        'ref_id' => $refId,
                    ], fn ($value) => $value !== null && $value !== '')
                );

                $push['sent'] = $result['sent'];
                $push['failed'] = $result['failed'];

                if ($result['invalid_tokens'] !== []) {
                    $cleared = User::query()
                        ->whereIn('fcm_token', $result['invalid_tokens'])
                        ->update(['fcm_token' => null, 'fcm_platform' => null]);
                    $push['invalid_tokens_cleared'] = $cleared;
                }

                $topic = (string) config('firebase.broadcast_topic');
                if ($topic !== '' && $platform === 'all') {
                    $topicResult = $fcm->sendToTopic(
                        $topic,
                        $data['title'],
                        $data['body'],
                        array_filter([
                            'type' => $type,
                            'ref_id' => $refId,
                        ], fn ($value) => $value !== null && $value !== '')
                    );
                    $push['topic_sent'] = (bool) ($topicResult['success'] ?? false);
                    if (! ($topicResult['success'] ?? false)) {
                        $push['error'] = $topicResult['error'] ?? $push['error'];
                    }
                }
            } else {
                $push['error'] = 'Firebase credentials are not configured';
            }
        } catch (\Throwable $e) {
            Log::error('Notification broadcast push failed', [
                'message' => $e->getMessage(),
            ]);
            $push['error'] = 'Push delivery failed. In-app notifications were still created.';
        }

        $campaign = NotificationBroadcast::create([
            'id' => (string) Str::uuid(),
            'title' => $data['title'],
            'body' => $data['body'],
            'type' => $type,
            'platform' => $platform,
            'audience' => $audience,
            'status' => 'active',
            'recipients' => $recipientCount,
            'push_sent' => (int) ($push['sent'] ?? 0),
            'ref_id' => $refId,
        ]);

        return ApiResponse::success('Notification broadcast sent', [
            'campaign' => $campaign,
            'recipients' => $recipientCount,
            'audience' => $audience,
            'platform' => $platform,
            'push' => $push,
        ]);
    }

    public function notificationTokenStats()
    {
        $base = User::query()->whereNotNull('fcm_token')->where('fcm_token', '!=', '');

        return ApiResponse::success('Notification token stats retrieved', [
            'all' => (clone $base)->count(),
            'ios' => (clone $base)->whereRaw('LOWER(COALESCE(fcm_platform, ?)) = ?', ['', 'ios'])->count(),
            'android' => (clone $base)->whereRaw('LOWER(COALESCE(fcm_platform, ?)) = ?', ['', 'android'])->count(),
            'unknown' => (clone $base)->where(function ($q) {
                $q->whereNull('fcm_platform')->orWhere('fcm_platform', '')->orWhereRaw('LOWER(COALESCE(fcm_platform, ?)) = ?', ['', 'web']);
            })->count(),
        ]);
    }

    public function notificationCampaigns(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'in:all,ios,android'],
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = NotificationBroadcast::query()->latest();

        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (! empty($data['platform'])) {
            $query->where('platform', $data['platform']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $search = $data['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('body', 'like', '%'.$search.'%');
            });
        }

        $items = $query
            ->offset($data['offset'] ?? 0)
            ->limit($data['limit'] ?? 50)
            ->get();

        return ApiResponse::success('Notification campaigns retrieved', $items);
    }

    public function markAllNotificationsRead()
    {
        $updated = UserNotification::query()->where('is_read', false)->update(['is_read' => true]);

        return ApiResponse::success('All notifications marked as read', [
            'updated' => $updated,
        ]);
    }

    public function deleteNotificationCampaign(string $id)
    {
        $campaign = NotificationBroadcast::findOrFail($id);
        $campaign->delete();

        return ApiResponse::success('Notification campaign deleted');
    }

    public function topProducts(Request $request) { $limit = $request->validate(['limit'=>['sometimes','integer','min:1','max:100']])['limit'] ?? 5; return ApiResponse::success('Top products retrieved successfully', OrderItem::select('product_id', DB::raw('SUM(quantity) as sold_quantity'))->groupBy('product_id')->orderByDesc('sold_quantity')->limit($limit)->with('product.images')->get()); }
}
