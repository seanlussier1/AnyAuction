<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuctionController;
use App\Controllers\AuthController;
use App\Controllers\BrowseController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Controllers\SellController;
use App\Controllers\WatchlistController;
use Slim\App;

return function (App $app): void {
    $app->get('/', [HomeController::class, 'index']);

    $app->get('/auction/{id}', [AuctionController::class, 'show']);
    $app->post('/auction/{id}/bid', [AuctionController::class, 'placeBid']);

    $app->get('/browse',    [BrowseController::class, 'index']);
    $app->get('/sell',      [SellController::class, 'showForm']);
    $app->get('/watchlist', [WatchlistController::class, 'index']);
    $app->get('/profile',   [ProfileController::class, 'index']);
    $app->get('/admin',     [AdminController::class, 'index']);

    $app->get('/register',  [AuthController::class, 'showRegister']);
    $app->post('/register', [AuthController::class, 'register']);
    $app->get('/login',     [AuthController::class, 'showLogin']);
    $app->post('/login',    [AuthController::class, 'login']);
    $app->post('/logout',   [AuthController::class, 'logout']);
};
