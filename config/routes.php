<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuctionController;
use App\Controllers\AuthController;
use App\Controllers\BrowseController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Controllers\PublicProfileController;
use App\Controllers\RatingController;
use App\Controllers\SearchController;
use App\Controllers\SellController;
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
    $app->get('/api/search', [SearchController::class, 'suggest']);
    $app->get('/sell',      [SellController::class, 'showForm']);
    $app->post('/sell',     [SellController::class, 'create']);
    $app->get('/watchlist',                 [WatchlistController::class, 'index']);
    $app->post('/watchlist/toggle/{id}',    [WatchlistController::class, 'toggle']);
    $app->get('/profile',          [ProfileController::class, 'index']);
    $app->get('/users/{id}',       [PublicProfileController::class, 'show']);
    $app->post('/rate/{orderId}',  [RatingController::class, 'submit']);
    $app->get('/admin',            [AdminController::class, 'index']);

    $app->get('/register',  [AuthController::class, 'showRegister']);
    $app->post('/register', [AuthController::class, 'register']);
    $app->get('/login',     [AuthController::class, 'showLogin']);
    $app->post('/login',    [AuthController::class, 'login']);
    $app->post('/logout',   [AuthController::class, 'logout']);
};
