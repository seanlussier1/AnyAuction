<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);

if (is_file($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->load();
}

return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'prod',
        'display_error_details' => filter_var(
            $_ENV['DISPLAY_ERROR_DETAILS'] ?? 'false',
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'name' => $_ENV['DB_NAME'] ?? 'anyauction',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'stripe' => [
        'publishable_key' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '',
        'secret_key'      => $_ENV['STRIPE_SECRET_KEY']      ?? '',
        'base_url'        => $_ENV['APP_BASE_URL']           ?? 'http://localhost:8000',
    ],
    'twilio' => [
        'account_sid' => $_ENV['TWILIO_ACCOUNT_SID'] ?? '',
        'auth_token'  => $_ENV['TWILIO_AUTH_TOKEN']  ?? '',
        'from_number' => $_ENV['TWILIO_FROM_NUMBER'] ?? '',
    ],
    'cloudinary' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? '',
        'api_key'    => $_ENV['CLOUDINARY_API_KEY']    ?? '',
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? '',
        'folder'     => $_ENV['CLOUDINARY_FOLDER']     ?? 'anyauction/listings',
    ],
    'paths' => [
        'root' => $rootPath,
        'templates' => $rootPath . '/templates',
        'locales' => $rootPath . '/locales',
    ],
];
