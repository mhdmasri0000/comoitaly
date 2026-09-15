<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PublicContentController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\SettingsController;
use App\Mail\OtpMail;

Route::get('/health', fn () => response()->json([
    'success' => true,
    'message' => 'COM-O API is running',
    'data' => null,
]));

// Dev-only preview of the OTP email template (hidden in production).
if (! app()->environment('production')) {
    Route::get('/email-preview/otp', fn () => response(
        (new OtpMail('123456', 'Ali', 10))->render()
    )->withHeaders([
        'Content-Type' => 'text/html; charset=UTF-8',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src 'none'; connect-src 'none'; frame-ancestors 'none';",
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]));
}

Route::middleware('throttle:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');
        Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth');
        Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:auth');
        Route::post('/refresh-token', [AuthController::class, 'refreshToken'])->middleware('throttle:auth');
        Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
    });

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::middleware(['auth:sanctum', 'admin'])->post('/categories', [CategoryController::class, 'store']);
    // POST accepted so multipart image uploads work (PHP does not parse files on PUT)
    Route::middleware(['auth:sanctum', 'admin'])->match(['put', 'post'], '/categories/{category}', [CategoryController::class, 'update']);
    Route::middleware(['auth:sanctum', 'admin'])->delete('/categories/{category}', [CategoryController::class, 'destroy']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::middleware(['auth:sanctum', 'admin'])->post('/products', [ProductController::class, 'store']);
    // POST accepted so multipart image uploads work (PHP does not parse files on PUT)
    Route::middleware(['auth:sanctum', 'admin'])->match(['put', 'post'], '/products/{product}', [ProductController::class, 'update']);
    Route::middleware(['auth:sanctum', 'admin'])->delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user/profile', [AuthController::class, 'profile']);
        Route::match(['put', 'post', 'patch'], '/user/profile', [AuthController::class, 'updateProfile']);
        Route::get('/favorites', [FavoriteController::class, 'index']);
        Route::post('/favorites', [FavoriteController::class, 'store']);
        Route::delete('/favorites/{productId}', [FavoriteController::class, 'destroy']);
        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart', [CartController::class, 'store']);
        Route::put('/cart/{id}', [CartController::class, 'update']);
        Route::delete('/cart/{id}', [CartController::class, 'destroy']);
        Route::post('/orders/checkout', [OrderController::class, 'checkout']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'read']);
        Route::put('/user/fcm-token', [NotificationController::class, 'registerDeviceToken']);
        Route::delete('/user/fcm-token', [NotificationController::class, 'unregisterDeviceToken']);
    });
    Route::get('/search', [PublicContentController::class, 'search']);
    Route::get('/home', [PublicContentController::class, 'home']);
    Route::get('/delivery-fees', [SettingsController::class, 'publicDeliveryFees']);
    Route::get('/offers', [OfferController::class, 'publicIndex']);
    Route::get('/offers/{id}', [OfferController::class, 'publicShow']);
    Route::get('/app-update', [SettingsController::class, 'appUpdate']);
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/app-update/settings', [SettingsController::class, 'getAppUpdatePolicy']);
        Route::put('/app-update/settings', [SettingsController::class, 'setAppUpdatePolicy']);
    });
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::post('/users', [AdminController::class, 'createUser']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}', [AdminController::class, 'showUser']);
        Route::post('/users/{id}/reset-credentials', [AdminController::class, 'resetCredentials']);
        Route::patch('/users/{id}/role', [AdminController::class, 'role']);
        Route::get('/alerts', [AdminNotificationController::class, 'index']);
        Route::get('/alerts/unread-count', [AdminNotificationController::class, 'unreadCount']);
        Route::post('/alerts/mark-all-read', [AdminNotificationController::class, 'markAllRead']);
        Route::get('/alerts/{id}', [AdminNotificationController::class, 'show']);
        Route::post('/alerts/{id}/read', [AdminNotificationController::class, 'read']);
        Route::delete('/alerts/{id}', [AdminNotificationController::class, 'destroy']);
        Route::get('/offers', [OfferController::class, 'index']);
        Route::post('/offers', [OfferController::class, 'store']);
        Route::get('/offers/{id}', [OfferController::class, 'show']);
        Route::match(['put', 'post'], '/offers/{id}', [OfferController::class, 'update']);
        Route::delete('/offers/{id}', [OfferController::class, 'destroy']);
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::patch('/orders/{id}/status', [AdminController::class, 'status']);
        Route::post('/notifications/broadcast', [AdminController::class, 'broadcast']);
        Route::get('/notifications', [AdminController::class, 'notificationCampaigns']);
        Route::get('/notifications/token-stats', [AdminController::class, 'notificationTokenStats']);
        Route::patch('/notifications/read-all', [AdminController::class, 'markAllNotificationsRead']);
        Route::delete('/notifications/{id}', [AdminController::class, 'deleteNotificationCampaign']);
        Route::get('/analytics/stats', [AnalyticsController::class, 'stats']);
        Route::get('/analytics/revenue', [AnalyticsController::class, 'daily'])->defaults('metric', 'revenue');
        Route::get('/analytics/orders-daily', [AnalyticsController::class, 'daily'])->defaults('metric', 'orders');
        Route::get('/analytics/users-daily', [AnalyticsController::class, 'daily'])->defaults('metric', 'users');
        Route::get('/analytics/top-products', [AnalyticsController::class, 'topProducts']);
        Route::get('/analytics/category-sales', [AnalyticsController::class, 'categorySales']);
        Route::get('/orders/recent', [AdminController::class, 'recentOrders']);
        Route::get('/products/top', [AdminController::class, 'topProducts']);
        Route::get('/home-content/banners', [SettingsController::class, 'banners']);
        Route::post('/home-content/banners', [SettingsController::class, 'saveBanner']);
        Route::match(['put', 'post'], '/home-content/banners/{id}', [SettingsController::class, 'updateBanner']);
        Route::delete('/home-content/banners/{id}', [SettingsController::class, 'destroyBanner']);
        Route::get('/home-content/offers', [SettingsController::class, 'offers']);
        Route::post('/home-content/offers', [SettingsController::class, 'saveOffer']);
        Route::match(['put', 'post'], '/home-content/offers/{id}', [SettingsController::class, 'updateOffer']);
        Route::delete('/home-content/offers/{id}', [SettingsController::class, 'destroyOffer']);
        Route::post('/home-content/featured', [SettingsController::class, 'featured']);
        Route::post('/home-content/offer-products', [SettingsController::class, 'offerProducts']);
        Route::get('/settings/delivery-fee', [SettingsController::class, 'deliveryFee']);
        Route::put('/settings/delivery-fee', [SettingsController::class, 'setDeliveryFee']);
        Route::get('/settings/app-update', [SettingsController::class, 'getAppUpdatePolicy']);
        Route::put('/settings/app-update', [SettingsController::class, 'setAppUpdatePolicy']);
        Route::put('/profile/email', [SettingsController::class, 'updateEmail']);
        Route::put('/profile/password', [SettingsController::class, 'updatePassword']);
    });
});