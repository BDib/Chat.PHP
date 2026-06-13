<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;
use App\Config;

class SysCommand extends AbstractCommand
{
    public function getName(): string { return 'sys'; }
    public function getPattern(): string { return '/^\/sys\s+(.+)$/'; }
    public function getRoles(): array { return ['admin']; }
    public function getUsage(): string { return '/sys <message>'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $msg = $args[0];
        if ($currentUser->rank < 9) {
            $this->jsonResponse(['error' => 'You need rank 9 to send system messages.']);
        }
        $chatService->systemMessage($msg, $currentUser->username, $ip);
    }
}
