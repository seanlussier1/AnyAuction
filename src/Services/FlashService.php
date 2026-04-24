<?php

declare(strict_types=1);

namespace App\Services;

final class FlashService
{
    private const SESSION_KEY = '_flash';

    public function add(string $type, string $message): void
    {
        $_SESSION[self::SESSION_KEY] ??= [];
        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'message' => $message];
    }

    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    public function error(string $message): void
    {
        $this->add('error', $message);
    }

    /**
     * @return array<int, array{type: string, message: string}>
     */
    public function pullAll(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);
        return $messages;
    }
}
