<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;
use App\Config;

class WorldCommand extends AbstractCommand
{
    public function getName(): string { return 'world'; }
    public function getPattern(): string { return '#^/world (shake|flip|blur|disco|matrix|confetti|flash|wave|reload|blackout|rain)$#'; }
    public function getRoles(): array { return ['admin']; }
    public function getUsage(): string { return '/world <effect>'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $effect = $args[0];
        $now = date(Config::DATE_FORMAT);
        $stmt = Database::get()->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$currentUser->username, $effect, $now, $ip, 'effect']);
    }
}
