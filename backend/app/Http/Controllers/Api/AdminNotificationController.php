<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Services\NotificationService;
use App\Support\AdminNotificationResource;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'unread' => ['nullable'],
            'type' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // Accept "1"/"0", "true"/"false", etc. (boolean rule rejects "true").
        if (array_key_exists('unread', $data) && $data['unread'] !== null && $data['unread'] !== '') {
            $data['unread'] = filter_var($data['unread'], FILTER_VALIDATE_BOOLEAN);
        } else {
            unset($data['unread']);
        }

        $paginator = $this->notifications->listForUser(
            $request->user(),
            $data,
            (int) ($data['per_page'] ?? 15)
        );

        return ApiResponse::success('Notifications retrieved successfully', [
            'items' => AdminNotificationResource::collection($paginator->items()),
            'unread_count' => $this->notifications->unreadCount($request->user()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        return ApiResponse::success('Unread count retrieved successfully', [
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    public function show(Request $request, string $id)
    {
        return ApiResponse::success(
            'Notification retrieved successfully',
            AdminNotificationResource::toArray($this->findOwned($request, $id))
        );
    }

    public function read(Request $request, string $id)
    {
        $notification = $this->notifications->markAsRead($this->findOwned($request, $id));

        return ApiResponse::success('Notification marked as read', AdminNotificationResource::toArray($notification));
    }

    public function markAllRead(Request $request)
    {
        $updated = $this->notifications->markAllAsRead($request->user());

        return ApiResponse::success('All notifications marked as read', ['updated' => $updated]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->notifications->delete($this->findOwned($request, $id));

        return ApiResponse::success('Notification deleted successfully');
    }

    private function findOwned(Request $request, string $id): AdminNotification
    {
        return AdminNotification::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
