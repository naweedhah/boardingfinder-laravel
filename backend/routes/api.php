<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoardingController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RoommateController;
use App\Http\Controllers\SearchAlertController;
use App\Http\Controllers\SupportChatController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\ParseMultipartBody;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefixed with /api)
|--------------------------------------------------------------------------
| `auth.token` = a valid API token for an active account: the web client's httpOnly `token`
| cookie, or the mobile app's `Authorization: Bearer` header.
*/

// ---- Authentication --------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('logout', [AuthController::class, 'logout']);

    // Mobile app: the token comes back in the body and is sent as a bearer token.
    Route::post('mobile/login', [MobileAuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware('auth.token')->group(function () {
        Route::get('me', [MobileAuthController::class, 'me']);
        Route::post('mobile/logout', [MobileAuthController::class, 'logout']);
    });
});

// ---- Public listing browsing ----------------------------------------------
Route::get('posts', [PostController::class, 'index']);
Route::get('posts/demand/overview', [PostController::class, 'demandOverview']);
Route::get('posts/{id}', [PostController::class, 'show']);
Route::get('posts/{post}/reviews', [ReviewController::class, 'index']);

Route::middleware('auth.token')->group(function () {
    // ---- Listings ----------------------------------------------------------
    Route::post('posts', [PostController::class, 'store'])->middleware('role:boardingOwner,admin');
    Route::put('posts/{post}', [PostController::class, 'update']);
    Route::delete('posts/{post}', [PostController::class, 'destroy']);
    Route::post('posts/{post}/book', [PostController::class, 'book']);
    Route::post('posts/{post}/reviews', [ReviewController::class, 'upsert']);
    Route::delete('posts/{post}/reviews', [ReviewController::class, 'destroy']);

    // ---- Users, notifications, alerts, roommates --------------------------
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('notification', [NotificationController::class, 'unreadCount']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
        Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences']);
        Route::put('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::put('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/test-email', [NotificationController::class, 'testEmail']);

        Route::get('watchlists/searches', [SearchAlertController::class, 'index']);
        Route::post('watchlists/searches', [SearchAlertController::class, 'store']);
        Route::delete('watchlists/searches/{id}', [SearchAlertController::class, 'destroy']);

        Route::get('roommate-profile', [RoommateController::class, 'show']);
        Route::put('roommate-profile', [RoommateController::class, 'upsert']);
        Route::get('roommate-matches', [RoommateController::class, 'matches']);

        Route::post('push-tokens', [PushTokenController::class, 'store']);
        Route::delete('push-tokens', [PushTokenController::class, 'destroy']);

        Route::post('save', [UserController::class, 'savePost']);
        Route::get('profilePosts', [UserController::class, 'profilePosts']);
        Route::put('{user}', [UserController::class, 'update']);
        Route::delete('{user}', [UserController::class, 'destroy']);
    });

    // ---- Bookings ----------------------------------------------------------
    Route::prefix('bookings')->group(function () {
        Route::get('/', [BookingController::class, 'index']);
        Route::put('{booking}/approve', [BookingController::class, 'approve']);
        Route::put('{booking}/reject', [BookingController::class, 'reject']);
        Route::put('{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('{booking}/chat', [BookingController::class, 'chat']);
    });

    // ---- Chat --------------------------------------------------------------
    Route::get('chats', [ChatController::class, 'index']);
    Route::post('chats', [ChatController::class, 'store']);
    Route::put('chats/read/{id}', [ChatController::class, 'read']);
    Route::get('chats/{id}', [ChatController::class, 'show']);
    Route::post('messages/{chatId}', [MessageController::class, 'store']);

    Route::prefix('support-chat')->group(function () {
        Route::post('create', [SupportChatController::class, 'create']);
        Route::post('send', [SupportChatController::class, 'send']);
        Route::get('{chatId}', [SupportChatController::class, 'messages']);
    });

    // ---- Inquiries ---------------------------------------------------------
    Route::prefix('inquiry')->group(function () {
        Route::post('/', [InquiryController::class, 'store']);
        Route::get('/', [InquiryController::class, 'index']);
        Route::patch('{inquiry}', [InquiryController::class, 'updateStatus']);
        Route::patch('{inquiry}/accept', [InquiryController::class, 'accept']);
    });

    // ---- Reports & moderation ---------------------------------------------
    Route::prefix('report')->group(function () {
        Route::post('/', [ReportController::class, 'store']);
        Route::get('/', [ReportController::class, 'index']);
        Route::post('dev-seed', [ReportController::class, 'devSeed']);

        Route::middleware('role:admin')->group(function () {
            Route::get('admin/overview', [ReportController::class, 'adminOverview']);
            Route::post('user-state', [ReportController::class, 'setUserState']);
            Route::post('warn', [ReportController::class, 'warn']);
            Route::patch('{id}', [ReportController::class, 'resolve']);
        });
    });

    // ---- Owner dashboard (listings, photos, AI helpers, waitlist) ---------
    Route::prefix('boardings')->middleware(ParseMultipartBody::class)->group(function () {
        Route::get('/', [BoardingController::class, 'index']);
        Route::post('{post}/waitlist', [BoardingController::class, 'joinWaitlist']);

        Route::middleware('role:boardingOwner,admin')->group(function () {
            Route::post('generate-description', [BoardingController::class, 'generateDescription']);
            Route::post('verify-image', [BoardingController::class, 'verifyImage']);
            Route::post('add', [BoardingController::class, 'store']);
            // `POST` + `_method=PUT` also works for PHP < 8.4 (see ParseMultipartBody).
            Route::put('{post}', [BoardingController::class, 'update']);
            Route::patch('{post}/status', [BoardingController::class, 'updateStatus']);
            Route::get('{post}/waitlist', [BoardingController::class, 'waitlist']);
            Route::delete('{post}', [BoardingController::class, 'destroy']);
        });
    });

    // ---- Connectivity checks ----------------------------------------------
    Route::get('test/should-be-logged-in', fn () => response()->json(['message' => 'You are Authenticated']));
    Route::get('test/should-be-admin', fn () => response()->json(['message' => 'You are Authenticated']))
        ->middleware('role:admin');
});
