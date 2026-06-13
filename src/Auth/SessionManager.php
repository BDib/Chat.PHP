<?php

namespace App\Auth;

use App\Database;
use App\Config;
use DateTime;
use DateTimeZone;
use PDO;

class SessionManager
{
    public function __construct(private PDO $db) {}

    public function cookie(string $token = ''): void
    {
        setcookie('session', $token, [
            'expires' => $token !== '' ? time() + 86400 * 30 : time() - 42000,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
        ]);
    }

    public function create(?int $userId = null): array
    {
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format(Config::DATE_FORMAT);
        $expires = (new DateTime('now', new DateTimeZone('UTC')))->modify('+30 days')->format(Config::DATE_FORMAT);

        $stmt = $this->db->prepare('INSERT INTO sessions (token, user_id, csrf_token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$token, $userId, $csrf, $now, $expires]);

        $this->cookie($token);
        return ['token' => $token, 'user_id' => $userId, 'csrf_token' => $csrf];
    }

    public function load(): ?array
    {
        $token = $_COOKIE['session'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $now = (new DateTime('now', new DateTimeZone('UTC')))->format(Config::DATE_FORMAT);
        $stmt = $this->db->prepare('SELECT token, user_id, csrf_token FROM sessions WHERE token = ? AND expires_at > ?');
        $stmt->execute([$token, $now]);
        return $stmt->fetch() ?: null;
    }

    public function delete(string $token): void
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE token = ?');
        $stmt->execute([$token]);
        $this->cookie();
    }

    public function regenerate(string $oldToken, int $userId): array
    {
        $this->delete($oldToken);
        return $this->create($userId);
    }

    public function gc(): void
    {
        if (random_int(1, 1000) === 1) {
            $now = (new DateTime('now', new DateTimeZone('UTC')))->format(Config::DATE_FORMAT);
            $stmt = $this->db->prepare('DELETE FROM sessions WHERE expires_at <= ?');
            $stmt->execute([$now]);
        }
    }
}
