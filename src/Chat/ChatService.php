<?php

namespace App\Chat;

use App\Database;
use App\Config;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

class ChatService
{
    private PDO $db;
    private PDO $filesDb;

    public function __construct()
    {
        $this->db = Database::get();
        $this->filesDb = Database::getFiles();
    }

    public function systemMessage(string $msg, string $username, string $ip): void
    {
        $now = date(Config::DATE_FORMAT);
        $stmt = $this->db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $msg, $now, $ip, 'system']);
    }

    public function checkCooldown(int $userId, string $action, string $label, int $seconds = 1800): ?string
    {
        $cutoff = (new DateTime('now', new DateTimeZone('UTC')))->modify("-{$seconds} seconds")->format(Config::DATE_FORMAT);
        $stmt = $this->db->prepare('SELECT used_at FROM cooldowns WHERE user_id = ? AND action = ? AND used_at > ?');
        $stmt->execute([$userId, $action, $cutoff]);

        if ($stmt->fetch()) {
            $mins = intdiv($seconds, 60);
            $unit = $seconds >= 3600 ? intdiv($seconds, 3600) . 'h' : $mins . 'm';
            return "You can only {$label} once per {$unit}.";
        }
        return null;
    }

    public function recordCooldown(int $userId, string $action): void
    {
        $now = date(Config::DATE_FORMAT);
        $this->db->prepare('INSERT INTO cooldowns (user_id, action, used_at) VALUES (?, ?, ?) ON CONFLICT(user_id, action) DO UPDATE SET used_at = ?')
            ->execute([$userId, $action, $now, $now]);
    }

    public function findUser(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, role, kicked_until, banned, rank, last_ip FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    public function getMessages(int $after = 0): array
    {
        if ($after === 0) {
            $stmt = $this->db->prepare('SELECT id, username, message, created_at, ip, kind, reply_to, color FROM messages ORDER BY id DESC LIMIT 200');
            $stmt->execute();
            return array_reverse($stmt->fetchAll());
        } else {
            $stmt = $this->db->prepare('SELECT id, username, message, created_at, ip, kind, reply_to, color FROM messages WHERE id > ? ORDER BY id ASC LIMIT 200');
            $stmt->execute([$after]);
            return $stmt->fetchAll();
        }
    }

    public function getHistory(int $before): array
    {
        $stmt = $this->db->prepare('SELECT id, username, message, created_at, ip, kind, reply_to, color FROM messages WHERE id < ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$before]);
        return array_reverse($stmt->fetchAll());
    }

    public function getOnlineUsers(): array
    {
        $cutoff = (new DateTime('now', new DateTimeZone('UTC')))->modify('-1 hour')->format(Config::DATE_FORMAT);
        $stmt = $this->db->prepare('SELECT username, last_seen, rank, color, status FROM users WHERE last_seen >= ? ORDER BY username ASC');
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll();
    }

    public function sendMessage(array $params): void
    {
        $stmt = $this->db->prepare('INSERT INTO messages (username, message, created_at, ip, reply_to, color, kind) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $params['username'],
            $params['message'],
            $params['created_at'],
            $params['ip'],
            $params['reply_to'] ?? null,
            $params['color'] ?? null,
            $params['kind'] ?? 'text'
        ]);
    }

    public function uploadFile(array $file, string $username): string
    {
        $data = file_get_contents($file['tmp_name']);
        $hash = hash('sha256', $data);
        $mime = mime_content_type($file['tmp_name']);

        $existing = $this->filesDb->prepare('SELECT id FROM files WHERE hash = ?');
        $existing->execute([$hash]);
        if (!$existing->fetch()) {
            $now = date(Config::DATE_FORMAT);
            $stmt = $this->filesDb->prepare('INSERT INTO files (hash, data, mime, size, username, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$hash, $data, $mime, $file['size'], $username, $now]);
        }
        return $hash;
    }

    public function getFile(string $hash): ?array
    {
        $stmt = $this->filesDb->prepare('SELECT data, mime FROM files WHERE hash = ?');
        $stmt->execute([$hash]);
        return $stmt->fetch() ?: null;
    }
}
