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
            'SELECT user_id, username, email, first_name, last_name, profile_picture,
                    role, is_verified, account_status, warning_note, locale
             FROM users
             WHERE user_id = :id'
        );

        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }

        if (($user['account_status'] ?? 'active') === 'banned') {
            unset($_SESSION['user_id']);
            return null;
        }

        return $user;
    }
    /**
     * Attempts login by email + password. Returns true on success, false on failure.
     * On failure, pushes a flash error message.
     *
     * NOTE: With SMS 2FA enforced, the login flow now uses
     * verifyPassword() + completeLogin() instead. attempt() is kept for
     * back-compat with anything still calling it directly.
     */
    public function attempt(string $email, string $password): bool
    {
        $row = $this->verifyPassword($email, $password);
        if ($row === null) {
            return false;
        }

        $this->completeLogin((int)$row['user_id']);
        return true;
    }

    /**
     * Validate credentials WITHOUT touching the session. Returns the
     * user row on success, null on failure. On failure, pushes a flash
     * error message.
     *
     * @return array<string, mixed>|null
     */
   public function verifyPassword(string $email, string $password): ?array
{
    $stmt = $this->db->prepare(
        'SELECT user_id, password_hash, phone, phone_verified_at, locale, account_status
         FROM users
         WHERE email = :email'
    );

    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        $this->flash->error('Invalid email or password.');
        return null;
    }

    if (($row['account_status'] ?? 'active') === 'banned') {
        $this->flash->error('This account has been banned.');
        return null;
    }

    return $row;
}

    /**
     * Promote a verified user_id into a logged-in session. Regenerates
     * the session id to prevent fixation. Used by the 2FA verify and
     * phone-enrollment flows once a code has been confirmed.
     */
    public function completeLogin(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    /**
     * Register a new user. Returns the new user_id on success, or null and
     * pushes a flash error describing what went wrong.
     *
     * If $phoneE164 is supplied it's stored on the row but phone_verified_at
     * is left NULL — the caller is expected to push the user through the
     * /enroll-phone flow to confirm ownership before the number is treated
     * as verified.
     */
    public function register(
        string $email,
        string $username,
        string $password,
        string $firstName,
        string $lastName,
        ?string $phoneE164 = null
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

        if ($phoneE164 !== null) {
            $phoneCheck = $this->db->prepare(
                'SELECT user_id FROM users WHERE phone = :phone LIMIT 1'
            );
            $phoneCheck->execute(['phone' => $phoneE164]);
            if ($phoneCheck->fetch()) {
                $this->flash->error('This phone is already linked to another account.');
                return null;
            }
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $insert = $this->db->prepare(
            'INSERT INTO users (username, email, phone, password_hash, first_name, last_name, role)
             VALUES (:username, :email, :phone, :hash, :first, :last, \'buyer\')'
        );
        $insert->execute([
            'username' => $username,
            'email'    => $email,
            'phone'    => $phoneE164,
            'hash'     => $hash,
            'first'    => $firstName,
            'last'     => $lastName,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Fetch a user row by id including 2FA-relevant columns. Returns
     * null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT user_id, username, email, phone, phone_verified_at,
                    first_name, last_name, role, locale
               FROM users WHERE user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Fetch a user row by email. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT user_id, username, email, phone, phone_verified_at, locale
               FROM users WHERE email = :email'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Returns true if any OTHER user (besides $excludeUserId) already
     * has the given phone on file. Used by the enrollment flow to
     * surface a clear "already linked" error instead of leaving rows
     * with duplicate numbers.
     */
    public function isPhoneLinkedToOtherUser(string $phoneE164, int $excludeUserId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT user_id FROM users
              WHERE phone = :phone AND user_id <> :uid LIMIT 1'
        );
        $stmt->execute(['phone' => $phoneE164, 'uid' => $excludeUserId]);
        return (bool)$stmt->fetch();
    }

    public function updatePhone(int $userId, string $phoneE164): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET phone = :phone, phone_verified_at = NULL
              WHERE user_id = :uid'
        );
        $stmt->execute(['phone' => $phoneE164, 'uid' => $userId]);
    }

    public function markPhoneVerified(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET phone_verified_at = NOW() WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
    }

    public function updatePassword(int $userId, string $newPlaintext): void
    {
        $hash = password_hash($newPlaintext, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare(
            'UPDATE users SET password_hash = :hash WHERE user_id = :uid'
        );
        $stmt->execute(['hash' => $hash, 'uid' => $userId]);
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
