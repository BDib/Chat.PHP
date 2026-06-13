<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;
use App\Database;
use App\Config;

class IpCommand extends AbstractCommand
{
    public function getName(): string { return 'ip'; }
    public function getPattern(): string { return '#^/ip ([a-z0-9]+)$#'; }
    public function getRoles(): array { return ['admin', 'moderator']; }
    public function getUsage(): string { return '/ip <username>'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $username = $args[0];
        $target = $chatService->findUser($username);
        if (!$target) $this->jsonResponse(['error' => 'User not found']);
        if ($target['role'] === 'admin' && $currentUser->role !== 'admin') {
            $this->jsonResponse(['error' => 'Cannot view admin IP']);
        }
        $db = Database::get();
        $stmt = $db->prepare('SELECT last_ip FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $userIp = $stmt->fetchColumn();
        $stmt = $db->prepare('SELECT DISTINCT ip FROM messages WHERE username = ?');
        $stmt->execute([$username]);
        $allIps = array_column($stmt->fetchAll(), 'ip');
        if ($userIp && !in_array($userIp, $allIps, true)) {
            array_unshift($allIps, $userIp);
        }
        if (!$allIps) $this->jsonResponse(['error' => "{$username}: no IP data"]);

        $placeholders = implode(',', array_fill(0, count($allIps), '?'));
        $stmt = $db->prepare("SELECT DISTINCT username FROM users WHERE last_ip IN ({$placeholders}) AND username != ?");
        $stmt->execute([...$allIps, $username]);
        $sharedUsers = array_column($stmt->fetchAll(), 'username');

        $stmt = $db->prepare("SELECT DISTINCT username FROM messages WHERE ip IN ({$placeholders}) AND username != ?");
        $stmt->execute([...$allIps, $username]);
        $sharedMsgUsers = array_column($stmt->fetchAll(), 'username');

        $alts = array_unique(array_merge($sharedUsers, $sharedMsgUsers));
        sort($alts);
        $info = "{$username}: " . implode(', ', $allIps);
        if ($alts) $info .= "\nAlso used by: " . implode(', ', $alts);
        $this->jsonResponse(['error' => $info]);
    }
}
