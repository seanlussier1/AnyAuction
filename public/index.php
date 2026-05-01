<?php

declare(strict_types=1);

use App\Services\AuthService;
use App\Services\DbSessionHandler;
use App\Services\FlashService;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
(require __DIR__ . '/../config/container.php')($containerBuilder);
$container = $containerBuilder->build();

// Persistent DB-backed sessions so users stay logged in across container
// redeploys (Watchtower wipes /tmp on image swap, killing file-based PHP
// sessions). Fall back to the default file handler if the DB is briefly
// unreachable rather than 500ing the page.
try {
    session_set_save_handler(
        new DbSessionHandler($container->get(PDO::class)),
        true
    );
} catch (\Throwable) {
    // DB not available yet — leave the default file handler in place.
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();

$displayErrors = $container->get('settings')['app']['display_error_details'];
$app->addErrorMiddleware($displayErrors, true, true);

$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

$app->add(function (Request $request, RequestHandler $handler) use ($container): Response {
    /** @var Twig $twig */
    $twig = $container->get(Twig::class);
    /** @var AuthService $auth */
    $auth = $container->get(AuthService::class);
    /** @var FlashService $flash */
    $flash = $container->get(FlashService::class);

    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }

    $watchlistIds = [];
    $unreadNotifs = 0;
    if ($auth->isLoggedIn()) {
        $pdo          = $container->get(\PDO::class);
        $watchlistIds = (new \App\Models\Watchlist($pdo))->idsForUser((int)$_SESSION['user_id']);
        $unreadNotifs = (new \App\Models\Notification($pdo))->unreadCountForUser((int)$_SESSION['user_id']);
    }

    $versionPath = __DIR__ . '/../.version';
    $appVersion  = is_file($versionPath) ? trim((string)@file_get_contents($versionPath)) : 'dev';
    if ($appVersion === '') {
        $appVersion = 'dev';
    }

    $env = $twig->getEnvironment();
    $env->addGlobal('current_user',          $auth->currentUser());
    $env->addGlobal('flash',                 $flash->pullAll());
    $env->addGlobal('request_path',          $request->getUri()->getPath());
    $env->addGlobal('csrf_token',            $_SESSION['_csrf']);
    $env->addGlobal('watchlist_ids',         $watchlistIds);
    $env->addGlobal('unread_notifications',  $unreadNotifs);
    $env->addGlobal('app_version',           $appVersion);

    return $handler->handle($request);
});

(require __DIR__ . '/../config/routes.php')($app);

$app->run();
