<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0', 'max:100000']]);
        return ApiResponse::success('Notifications retrieved successfully', UserNotification::where('user_id', $request->user()->id)->latest()->limit($data['limit'] ?? 50)->offset($data['offset'] ?? 0)->get());
    }

    public function read(Request $request, string $id)
    {
        UserNotification::where('user_id', $request->user()->id)->whereKey($id)->firstOrFail()->update(['is_read' => true]);

        return ApiResponse::success('Notification marked as read');
    }

    public function readAll(Request $request)
    {
        UserNotification::where('user_id', $request->user()->id)->update(['is_read' => true]);

        return ApiResponse::success('All notifications marked as read');
    }

    /**
     * Mobile apps call this after Firebase Messaging getToken().
     */
    public function registerDeviceToken(Request $request)
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'min:20', 'max:4096'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        $token = trim($data['fcm_token']);
        $platform = $data['platform'] ?? null;
        if ($platform !== null) {
            $platform = strtolower(trim((string) $platform));
            if (! in_array($platform, ['ios', 'android', 'web'], true)) {
                $platform = null;
            }
        }

        $request->user()->newQuery()
            ->where('fcm_token', $token)
            ->where('id', '!=', $request->user()->id)
            ->update(['fcm_token' => null, 'fcm_platform' => null]);

        $request->user()->update([
            'fcm_token' => $token,
            'fcm_platform' => $platform,
        ]);

        return ApiResponse::success('Device token registered');
    }

    public function unregisterDeviceToken(Request $request)
    {
        $request->user()->update(['fcm_token' => null, 'fcm_platform' => null]);

        return ApiResponse::success('Device token removed');
    }
}
