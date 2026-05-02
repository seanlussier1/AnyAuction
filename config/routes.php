<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\Auth2FAController;
use App\Controllers\AuctionController;
use App\Controllers\AuthController;
use App\Controllers\BrowseController;
use App\Controllers\CheckoutController;
use App\Controllers\HeartbeatController;
use App\Controllers\HomeController;
use App\Controllers\ListingFeedController;
use App\Controllers\NotificationController;
use App\Controllers\PasswordResetController;
use App\Controllers\PhoneEnrollmentController;
use App\Controllers\ProfileController;
use App\Controllers\PublicProfileController;
use App\Controllers\RatingController;
use App\Controllers\ReportController;
use App\Controllers\SearchController;
use App\Controllers\SellController;
use App\Controllers\TwilioWebhookController;
use App\Controllers\WatchlistController;
use Slim\App;

return function (App $app): void {
    $app->get('/', [HomeController::class, 'index']);

    $app->get('/auction/{id}', [AuctionController::class, 'show']);
    $app->post('/auction/{id}/bid',     [AuctionController::class, 'placeBid']);
    $app->post('/auction/{id}/buy-now', [AuctionController::class, 'buyNow']);

    $app->post('/checkout/start/{id}',  [CheckoutController::class, 'start']);
    $app->get('/checkout/success',      [CheckoutController::class, 'success']);
    $app->get('/checkout/cancel',       [CheckoutController::class, 'cancel']);

    $app->get('/browse',     [BrowseController::class, 'index']);
    $app->get('/api/search',    [SearchController::class, 'suggest']);
    $app->get('/api/heartbeat', [HeartbeatController::class, 'index']);
    $app->get('/api/listings/recent', [ListingFeedController::class, 'recent']);
    $app->post('/api/notifications/mark-read', [NotificationController::class, 'markAllRead']);
    $app->get('/api/notifications/recent',     [NotificationController::class, 'recent']);
    $app->get('/sell',      [SellController::class, 'showForm']);
    $app->post('/sell',     [SellController::class, 'create']);
    $app->get('/watchlist',                 [WatchlistController::class, 'index']);
    $app->post('/watchlist/toggle/{id}',    [WatchlistController::class, 'toggle']);
    $app->get('/profile',          [ProfileController::class, 'index']);
    $app->get('/users/{id}',       [PublicProfileController::class, 'show']);
    $app->post('/rate/{orderId}',  [RatingController::class, 'submit']);

    $app->get('/report/listing/{itemId}',  [ReportController::class, 'showListing']);
    $app->post('/report/listing/{itemId}', [ReportController::class, 'submitListing']);
    $app->post('/report/{id}/resolve',     [ReportController::class, 'resolve']);
    $app->post('/report/{id}/dismiss',     [ReportController::class, 'dismiss']);

    $app->get('/admin',            [AdminController::class, 'index']);

    $app->get('/register',  [AuthController::class, 'showRegister']);
    $app->post('/register', [AuthController::class, 'register']);
    $app->get('/login',     [AuthController::class, 'showLogin']);
    $app->post('/login',    [AuthController::class, 'login']);
    $app->post('/logout',   [AuthController::class, 'logout']);

    $app->get('/verify-2fa',         [Auth2FAController::class, 'showVerify']);
    $app->post('/verify-2fa',        [Auth2FAController::class, 'verify']);
    $app->post('/verify-2fa/resend', [Auth2FAController::class, 'resend']);

    $app->get('/enroll-phone',  [PhoneEnrollmentController::class, 'show']);
    $app->post('/enroll-phone', [PhoneEnrollmentController::class, 'submit']);

    $app->get('/forgot-password',  [PasswordResetController::class, 'showForgot']);
    $app->post('/forgot-password', [PasswordResetController::class, 'requestReset']);
    $app->get('/reset-password',   [PasswordResetController::class, 'showReset']);
    $app->post('/reset-password',  [PasswordResetController::class, 'submitReset']);

    // Twilio inbound SMS — signature-validated, no CSRF (Twilio doesn't send tokens).
    $app->post('/api/twilio/sms', [TwilioWebhookController::class, 'sms']);
};
