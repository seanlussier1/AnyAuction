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
            'SELECT user_id, username, email, first_name, last_name, profile_picture, role,
                    is_verified, account_status, warning_note, banned_at, created_at
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
     * against username or email. Includes phone_verified_at so the admin
     * UI can show users as verified once they've completed phone enrollment,
     * not just when the seed `is_verified` flag is set.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminAll(?string $search = null): array
    {
        if ($search !== null && $search !== '') {
            $stmt = $this->db->prepare(
                'SELECT user_id, username, email, first_name, last_name,
                        profile_picture, role, is_verified, phone_verified_at,
                        account_status, warning_note, banned_at, created_at
                 FROM users
                 WHERE username LIKE :q OR email LIKE :q
                 ORDER BY created_at DESC'
            );
            $stmt->execute(['q' => '%' . $search . '%']);
        } else {
            $stmt = $this->db->query(
                'SELECT user_id, username, email, first_name, last_name,
                        profile_picture, role, is_verified, phone_verified_at,
                        account_status, warning_note, banned_at, created_at
                 FROM users ORDER BY created_at DESC'
            );
        }
        return $stmt->fetchAll();
    }

    /**
     * Flag a user as warned with an admin-supplied note. Refuses to act on
     * admin accounts. Returns true if a row was updated.
     */
    public function warn(int $userId, string $note = 'Flagged by admin'): bool
    {
        $cleanNote = trim($note);
        if ($cleanNote === '') {
            $cleanNote = 'Flagged by admin';
        }

        $stmt = $this->db->prepare(
            "UPDATE users
                SET account_status = 'warned',
                    warning_note = :note,
                    banned_at = NULL
              WHERE user_id = :id AND role <> 'admin'"
        );
        $stmt->execute([
            'id'   => $userId,
            'note' => mb_substr($cleanNote, 0, 255),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a user as banned. Refuses to act on admin accounts.
     * Returns true if a row was updated.
     */
    public function ban(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users
                SET account_status = 'banned',
                    banned_at = NOW()
              WHERE user_id = :id AND role <> 'admin'"
        );
        $stmt->execute(['id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Restore a previously warned/banned user to active. Clears any
     * warning note and banned_at timestamp. Refuses admins.
     * Returns true if a row was updated.
     */
    public function unban(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users
                SET account_status = 'active',
                    warning_note = NULL,
                    banned_at = NULL
              WHERE user_id = :id AND role <> 'admin'"
        );
        $stmt->execute(['id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function countAll(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
