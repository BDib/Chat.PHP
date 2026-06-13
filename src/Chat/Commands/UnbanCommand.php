<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;

class UnbanCommand extends AbstractCommand
{
    public function getName(): string { return 'unban'; }
    public function getPattern(): string { return '#^/unban ([a-z0-9]+)$#'; }
    public function getRoles(): array { return ['admin', 'moderator']; }
    public function getUsage(): string { return '/unban <username>'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $username = $args[0];
        $target = $chatService->findUser($username);
        if (!$target) $this->jsonResponse(['error' => 'User not found']);
        Database::get()->prepare('UPDATE users SET banned = 0 WHERE id = ?')->execute([$target['id']]);
        $chatService->systemMessage("{$username} was unbanned by {$currentUser->username}", $currentUser->username, $ip);
    }
}
