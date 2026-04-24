<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class User
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT user_id, username, email, first_name, last_name, profile_picture, role, is_verified, created_at
             FROM users WHERE user_id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Admin users-table view. Optionally filter by a search string matched
     * against username or email.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminAll(?string $search = null): array
    {
        if ($search !== null && $search !== '') {
            $stmt = $this->db->prepare(
                'SELECT user_id, username, email, first_name, last_name,
                        profile_picture, role, is_verified, created_at
                 FROM users
                 WHERE username LIKE :q OR email LIKE :q
                 ORDER BY created_at DESC'
            );
            $stmt->execute(['q' => '%' . $search . '%']);
        } else {
            $stmt = $this->db->query(
                'SELECT user_id, username, email, first_name, last_name,
                        profile_picture, role, is_verified, created_at
                 FROM users ORDER BY created_at DESC'
            );
        }
        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
