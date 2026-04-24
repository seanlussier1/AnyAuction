<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Services\AuthService;
use App\Services\FlashService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SellController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash
    ) {
    }

    public function showForm(Request $request, Response $response): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Please log in to list an item.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $categories = (new Category($this->db))->all();

        return $this->view->render($response, 'pages/sell.twig', [
            'categories' => $categories,
        ]);
    }
}
