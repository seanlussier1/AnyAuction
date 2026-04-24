<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class AuthService
{
    public function __construct(
        private readonly PDO $db,
        private readonly FlashService $flash
    ) {
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT user_id, username, email, first_name, last_name, profile_picture, role, is_verified
             FROM users WHERE user_id = :id'
        );
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Attempts login by email + password. Returns true on success, false on failure.
     * On failure, pushes a flash error message.
     */
    public function attempt(string $email, string $password): bool
    {
        $stmt = $this->db->prepare('SELECT user_id, password_hash FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            $this->flash->error('Invalid email or password.');
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        return true;
    }

    /**
     * Register a new user. Returns the new user_id on success, or null and
     * pushes a flash error describing what went wrong.
     */
    public function register(
        string $email,
        string $username,
        string $password,
        string $firstName,
        string $lastName
    ): ?int {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash->error('Please enter a valid email address.');
            return null;
        }

        if (strlen($password) < 8) {
            $this->flash->error('Password must be at least 8 characters long.');
            return null;
        }

        if (strlen($username) < 3 || strlen($username) > 50) {
            $this->flash->error('Username must be between 3 and 50 characters.');
            return null;
        }

        if ($firstName === '' || $lastName === '') {
            $this->flash->error('First name and last name are required.');
            return null;
        }

        $exists = $this->db->prepare(
            'SELECT user_id FROM users WHERE email = :email OR username = :username LIMIT 1'
        );
        $exists->execute(['email' => $email, 'username' => $username]);
        if ($exists->fetch()) {
            $this->flash->error('An account with that email or username already exists.');
            return null;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $insert = $this->db->prepare(
            'INSERT INTO users (username, email, password_hash, first_name, last_name, role)
             VALUES (:username, :email, :hash, :first, :last, \'buyer\')'
        );
        $insert->execute([
            'username' => $username,
            'email'    => $email,
            'hash'     => $hash,
            'first'    => $firstName,
            'last'     => $lastName,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }
}
