<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;
use DateTime;
use DateTimeZone;

class InfoCommand extends AbstractCommand
{
    public function getName(): string { return 'info'; }
    public function getPattern(): string { return '#^/info ([a-z0-9]+)$#'; }
    public function getRoles(): array { return ['admin', 'moderator', 'user']; }
    public function getUsage(): string { return '/info <username>'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $username = $args[0];
        $db = Database::get();
        $stmt = $db->prepare('SELECT rank, created_at FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user) $this->jsonResponse(['error' => 'User not found']);

        $created = new DateTime($user['created_at'], new DateTimeZone('UTC'));
        $diff = (new DateTime('now', new DateTimeZone('UTC')))->diff($created);
        if ($diff->days === 0) $ago = 'today';
        elseif ($diff->days === 1) $ago = '1 day ago';
        else $ago = "{$diff->days} days ago";
        $this->jsonResponse(['error' => "{$username}: rank {$user['rank']}, joined {$ago}"]);
    }
}
