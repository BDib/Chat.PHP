<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;

class PromoteCommand extends AbstractCommand
{
    public function getName(): string { return 'promote'; }
    public function getPattern(): string { return '#^/promote ([a-z0-9]+)$#'; }
    public function getRoles(): array { return ['admin', 'moderator', 'user']; }
    public function getUsage(): string { return '/promote <username>'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        if ($currentUser->rank < 1) {
            $this->jsonResponse(['error' => 'You need at least rank 1 to promote.']);
        }
        $username = $args[0];
        $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
        if (!$isMod) {
            $error = $chatService->checkCooldown($currentUser->id, 'promote', 'promote', 3600);
            if ($error) $this->jsonResponse(['error' => $error]);
        }
        $target = $chatService->findUser($username);
        if (!$target) $this->jsonResponse(['error' => 'User not found']);
        if ($target['username'] === $currentUser->username) {
            $this->jsonResponse(['error' => 'You cannot promote yourself.']);
        }
        $newRank = $target['rank'] + 1;
        $maxRank = $currentUser->rank - 1;
        if ($newRank > $maxRank) {
            $this->jsonResponse(['error' => "You can only promote up to rank $maxRank."]);
        }
        Database::get()->prepare('UPDATE users SET rank = ? WHERE id = ?')->execute([$newRank, $target['id']]);
        if (!$isMod) $chatService->recordCooldown($currentUser->id, 'promote');
        $chatService->systemMessage("{$username} was promoted to rank {$newRank} by {$currentUser->username}", $currentUser->username, $ip);
    }
}
