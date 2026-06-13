<?php

namespace App\Controller;

use App\Config;
use App\Database;

class ProfileController
{
    private \PDO $db;

    public function __construct(
        private object $currentUser,
        private string $csrfToken
    ) {
        $this->db = Database::get();
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'update':
                $this->update();
                break;
            default:
                $this->index();
        }
    }

    private function index(): void
    {
        $view = new ViewController();
        $view->render('profile/index', [
            'currentUser' => $this->currentUser,
            'csrfToken' => $this->csrfToken,
            'title' => 'Profile Settings - ' . Config::get('APP_TITLE', 'Chat'),
            'view' => 'profile/index'
        ]);
    }

    private function update(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($this->csrfToken, $_POST['csrf_token'] ?? '')) {
            die('Invalid CSRF');
        }

        $color = $_POST['color'] ?? null;
        $status = $_POST['status'] ?? null;
        $password = $_POST['password'] ?? null;

        if ($color && $this->currentUser->rank >= 4) {
            if ($color === 'reset') {
                $this->db->prepare('UPDATE users SET color = NULL WHERE id = ?')->execute([$this->currentUser->id]);
            } elseif (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $this->db->prepare('UPDATE users SET color = ? WHERE id = ?')->execute([$color, $this->currentUser->id]);
            }
        }

        if ($status && $this->currentUser->rank >= 3) {
            if ($status === 'reset') {
                $this->db->prepare('UPDATE users SET status = NULL WHERE id = ?')->execute([$this->currentUser->id]);
            } elseif (preg_match('/^[a-z]+$/', $status) && strlen($status) <= 10) {
                $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $this->currentUser->id]);
            }
        }

        if ($password && strlen($password) >= 6) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $this->currentUser->id]);
        }

        header('Location: ?profile');
        exit;
    }
}
