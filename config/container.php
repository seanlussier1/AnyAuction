<?php

declare(strict_types=1);

use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\StripeService;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;

return function (ContainerBuilder $builder): void {
    $settings = require __DIR__ . '/settings.php';

    $builder->addDefinitions([
        'settings' => $settings,

        PDO::class => function (ContainerInterface $c): PDO {
            $db = $c->get('settings')['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'],
                $db['port'],
                $db['name']
            );

            return new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        },

        Twig::class => function (ContainerInterface $c): Twig {
            $settings = $c->get('settings');
            $twig = Twig::create($settings['paths']['templates'], [
                'cache' => false,
                'debug' => $settings['app']['display_error_details'],
            ]);

            // Renders "2d 4h" or "23m" from a DATETIME string.
            $twig->getEnvironment()->addFilter(new \Twig\TwigFilter('aa_timeleft', function ($endTime): string {
                if (!$endTime) {
                    return 'Ended';
                }
                $diff = strtotime((string)$endTime) - time();
                if ($diff <= 0) {
                    return 'Ended';
                }
                $days    = intdiv($diff, 86400);
                $hours   = intdiv($diff % 86400, 3600);
                $minutes = intdiv($diff % 3600, 60);
                if ($days > 0) {
                    return $days . 'd ' . $hours . 'h';
                }
                if ($hours > 0) {
                    return $hours . 'h ' . $minutes . 'm';
                }
                return $minutes . 'm';
            }));

            return $twig;
        },

        FlashService::class => fn () => new FlashService(),

        AuthService::class => fn (ContainerInterface $c) => new AuthService(
            $c->get(PDO::class),
            $c->get(FlashService::class)
        ),

        StripeService::class => fn (ContainerInterface $c) => new StripeService(
            secretKey: (string)$c->get('settings')['stripe']['secret_key'],
            baseUrl:   (string)$c->get('settings')['stripe']['base_url']
        ),
    ]);
};
