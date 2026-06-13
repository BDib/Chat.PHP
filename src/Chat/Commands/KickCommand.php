<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;
use App\Config;
use DateTime;
use DateTimeZone;

class KickCommand extends AbstractCommand
{
    public function getName(): string { return 'kick'; }
    public function getPattern(): string { return '#^/kick ([a-z0-9]+) (\d+[smhd])$#'; }
    public function getRoles(): array { return ['admin', 'moderator', 'user']; }
    public function getUsage(): string { return '/kick <username> <duration> (e.g. 5m, 1h, 2d)'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $username = $args[0];
        $duration = $args[1];
        $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
        if (!$isMod && $currentUser->rank < 8) {
            $this->jsonResponse(['error' => 'You need rank 8+ or moderator role to kick.']);
        }
        if (!$isMod) {
            $error = $chatService->checkCooldown($currentUser->id, 'kick', 'kick/unkick');
            if ($error) $this->jsonResponse(['error' => $error]);
        }
        $target = $chatService->findUser($username);
        if (!$target) $this->jsonResponse(['error' => 'User not found']);
        if ($target['role'] === 'admin') $this->jsonResponse(['error' => 'Cannot kick admin.']);
        if ($target['role'] === 'moderator' && $currentUser->role !== 'admin') $this->jsonResponse(['error' => 'Only admin can kick moderators.']);
        if (!$isMod && $target['rank'] >= $currentUser->rank) $this->jsonResponse(['error' => 'You can only kick lower-ranked users.']);

        $seconds = $this->parseDuration($duration);
        if (!$seconds || $seconds > 86400 * 30) $this->jsonResponse(['error' => 'Invalid duration.']);
        if (!$isMod && $seconds > 600) $this->jsonResponse(['error' => 'Rank 8 can only kick for up to 10m.']);

        $until = (new DateTime('now', new DateTimeZone('UTC')))->modify("+{$seconds} seconds")->format(Config::DATE_FORMAT);
        Database::get()->prepare('UPDATE users SET kicked_until = ? WHERE id = ?')->execute([$until, $target['id']]);

        if (!$isMod) $chatService->recordCooldown($currentUser->id, 'kick');
        $chatService->systemMessage("{$username} was kicked for {$duration} by {$currentUser->username}", $currentUser->username, $ip);
    }
}
