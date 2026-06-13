<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;
use App\Config;
use DateTime;
use DateTimeZone;

class MuteCommand extends AbstractCommand
{
    public function getName(): string { return 'mute'; }
    public function getPattern(): string { return '#^/mute ([a-z0-9]+) (\d+[smhd])$#'; }
    public function getRoles(): array { return ['admin', 'moderator', 'user']; }
    public function getUsage(): string { return '/mute <username> <duration> (e.g. 30s, 5m, 1h)'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $username = $args[0];
        $duration = $args[1];
        $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
        if (!$isMod && $currentUser->rank < 7) {
            $this->jsonResponse(['error' => 'You need rank 7+ to mute.']);
        }
        if (!$isMod) {
            $error = $chatService->checkCooldown($currentUser->id, 'mute', 'mute/unmute');
            if ($error) $this->jsonResponse(['error' => $error]);
        }
        $target = $chatService->findUser($username);
        if (!$target) $this->jsonResponse(['error' => 'User not found.']);
        if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $currentUser->role !== 'admin')) {
            $this->jsonResponse(['error' => 'Cannot mute this user.']);
        }
        if (!$isMod && $target['rank'] >= $currentUser->rank) {
            $this->jsonResponse(['error' => 'You can only mute lower-ranked users.']);
        }

        $seconds = $this->parseDuration($duration);
        if (!$seconds || $seconds > 86400 * 30) $this->jsonResponse(['error' => 'Invalid duration.']);
        if (!$isMod && $seconds > 300) $this->jsonResponse(['error' => 'Max mute duration is 5m.']);

        $until = (new DateTime('now', new DateTimeZone('UTC')))->modify("+{$seconds} seconds")->format(Config::DATE_FORMAT);
        Database::get()->prepare('UPDATE users SET muted_until = ? WHERE id = ?')->execute([$until, $target['id']]);
        if (!$isMod) $chatService->recordCooldown($currentUser->id, 'mute');
        $chatService->systemMessage("{$username} was muted for {$duration} by {$currentUser->username}", $currentUser->username, $ip);
    }
}
