<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class Category
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT category_id, name, slug, icon FROM categories ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT category_id, name, slug, icon FROM categories WHERE category_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
