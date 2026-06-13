<?php

namespace App\Auth;

use App\Database;
use App\Config;
use App\Chat\WordFilter;
use PDO;

class Authenticator
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function isIpBanned(string $ip): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM ip_bans WHERE ip = ?');
        $stmt->execute([$ip]);
        return (bool)$stmt->fetch();
    }

    public function register(string $username, string $password, string $ip): ?string
    {
        $username = strtolower(trim($username));
        if (strlen($username) < 5 || strlen($username) > 9 || !preg_match('/^[a-z]+$/', $username)) {
            return 'Username must be 5-9 chars: lowercase letters only, no numbers.';
        }

        if (!WordFilter::isPronounceable($username)) {
            return 'Username must be a pronounceable word (no gibberish).';
        }

        $existing = $this->db->prepare('SELECT id FROM users WHERE username = ?');
        $existing->execute([$username]);

        $banned = file_exists(Config::BANNED_NAMES_PATH) ? array_filter(array_map('trim', file(Config::BANNED_NAMES_PATH))) : [];
        $nameBanned = false;
        foreach ($banned as $part) {
            if ($part !== '' && str_contains($username, $part)) {
                $nameBanned = true;
                break;
            }
        }

        if ($existing->fetch() || $nameBanned) {
            return 'Username already taken.';
        }

        $now = date(Config::DATE_FORMAT);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare('INSERT INTO users (username, password_hash, created_at, last_seen, last_ip) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $hash, $now, $now, $ip]);

        $id = (int)$this->db->lastInsertId();
        if ($id === 1) {
            $this->db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['admin', $id]);
        }

        return null; // Success
    }

    public function login(string $username, string $password, string $now): array|string
    {
        $username = strtolower(trim($username));
        $stmt = $this->db->prepare('SELECT id, password_hash, kicked_until, banned FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return 'Invalid username or password.';
        }

        if ($user['banned']) {
            return 'You are permanently banned.';
        }

        if ($user['kicked_until'] && $user['kicked_until'] > $now) {
            return "You are kicked until {$user['kicked_until']}.";
        }

        return $user;
    }

    public function getUserById(int $id): ?object
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? (object)$row : null;
    }
}
