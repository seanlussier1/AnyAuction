<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\FlashService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash
    ) {
    }

    public function showRegister(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $this->view->render($response, 'auth/register.twig', ['old' => []]);
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        $email     = trim((string)($body['email']     ?? ''));
        $username  = trim((string)($body['username']  ?? ''));
        $firstName = trim((string)($body['first_name'] ?? ''));
        $lastName  = trim((string)($body['last_name']  ?? ''));
        $password  = (string)($body['password']         ?? '');
        $confirm   = (string)($body['password_confirm'] ?? '');

        if ($password !== $confirm) {
            $this->flash->error('Passwords do not match.');
            return $this->view->render($response, 'auth/register.twig', [
                'old' => compact('email', 'username', 'firstName', 'lastName'),
            ]);
        }

        $newId = $this->auth->register($email, $username, $password, $firstName, $lastName);

        if ($newId === null) {
            return $this->view->render($response, 'auth/register.twig', [
                'old' => compact('email', 'username', 'firstName', 'lastName'),
            ]);
        }

        $this->flash->success('Account created. Please log in.');
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $this->view->render($response, 'auth/login.twig', ['old' => []]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body     = (array)$request->getParsedBody();
        $email    = trim((string)($body['email']    ?? ''));
        $password = (string)($body['password']       ?? '');

        if ($email === '' || $password === '') {
            $this->flash->error('Please enter both your email and password.');
            return $this->view->render($response, 'auth/login.twig', ['old' => ['email' => $email]]);
        }

        if (!$this->auth->attempt($email, $password)) {
            return $this->view->render($response, 'auth/login.twig', ['old' => ['email' => $email]]);
        }

        $this->flash->success('Welcome back!');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
