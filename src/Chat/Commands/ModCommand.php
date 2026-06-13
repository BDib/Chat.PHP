<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;

class ModCommand extends AbstractCommand
{
    public function getName(): string { return 'mod'; }
    public function getPattern(): string { return '#^/mod (add|del) ([a-z0-9]+)$#'; }
    public function getRoles(): array { return ['admin']; }
    public function getUsage(): string { return '/mod add|del <username>'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $action = $args[0];
        $username = $args[1];
        $target = $chatService->findUser($username);
        if (!$target) $this->jsonResponse(['error' => 'User not found']);

        if ($target['role'] === 'admin') {
            $this->jsonResponse(['error' => 'Cannot change admin role']);
        }
        $newRole = $action === 'add' ? 'moderator' : 'user';
        Database::get()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $target['id']]);
        $msg = $action === 'add' ? "{$username} is now a moderator" : "{$username} is no longer a moderator";
        $chatService->systemMessage($msg, $currentUser->username, $ip);
    }
}
