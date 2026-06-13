<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;

class BanCommand extends AbstractCommand
{
    public function getName(): string { return 'ban'; }
    public function getPattern(): string { return '#^/ban ([a-z0-9,\s]+)$#'; }
    public function getRoles(): array { return ['admin', 'moderator']; }
    public function getUsage(): string { return '/ban <username>[, username2, ...]'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $input = $args[0];
        $usernames = array_unique(array_filter(array_map('trim', explode(',', $input))));
        if (empty($usernames)) $this->jsonResponse(['error' => 'No usernames provided']);
        foreach ($usernames as $username) {
            if (!preg_match('/^[a-z0-9]+$/', $username)) $this->jsonResponse(['error' => "Invalid username: {$username}"]);
        }
        $banned = [];
        foreach ($usernames as $username) {
            $target = $chatService->findUser($username);
            if (!$target) continue;
            if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $currentUser->role !== 'admin')) {
                continue;
            }
            Database::get()->prepare('UPDATE users SET banned = 1, rank = 0 WHERE id = ?')->execute([$target['id']]);
            $banned[] = $username;
        }
        if (empty($banned)) $this->jsonResponse(['error' => 'No users could be banned']);
        $list = implode(', ', $banned);
        $chatService->systemMessage("{$list} banned by {$currentUser->username}", $currentUser->username, $ip);
    }
}
