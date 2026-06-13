<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;

abstract class AbstractCommand implements CommandInterface
{
    protected function jsonResponse(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function requireRole(object $user, string ...$allowed): void
    {
        if (!in_array($user->role, $allowed, true)) {
            $this->jsonResponse(['error' => 'Permission denied']);
        }
    }

    protected function parseDuration(string $s): ?int
    {
        if (preg_match('/^(\d+)([smhd])$/', $s, $m)) {
            return (int)$m[1] * match ($m[2]) {
                's' => 1,
                'm' => 60,
                'h' => 3600,
                'd' => 86400
            };
        }
        return null;
    }
}
