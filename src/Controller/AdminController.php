<?php

namespace App\Controller;

use App\Config;
use App\Database;
use App\Auth\Authenticator;

class AdminController
{
    private \PDO $db;

    public function __construct(
        private object $currentUser,
        private string $csrfToken
    ) {
        if ($this->currentUser->role !== 'admin') {
            http_response_code(403);
            die('Access Denied');
        }
        $this->db = Database::get();
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'users':
                $this->manageUsers();
                break;
            case 'words':
                $this->manageWords();
                break;
            case 'update_user':
                $this->updateUser();
                break;
            case 'update_words':
                $this->updateWords();
                break;
            default:
                $this->index();
        }
    }

    private function index(): void
    {
        $view = new ViewController();
        $view->render('admin/index', [
            'currentUser' => $this->currentUser,
            'csrfToken' => $this->csrfToken,
            'title' => 'Admin Dashboard - ' . Config::get('APP_TITLE', 'Chat'),
            'view' => 'admin/index'
        ]);
    }

    private function manageUsers(): void
    {
        $stmt = $this->db->query('SELECT * FROM users ORDER BY created_at DESC');
        $users = $stmt->fetchAll();

        $view = new ViewController();
        $view->render('admin/users', [
            'currentUser' => $this->currentUser,
            'csrfToken' => $this->csrfToken,
            'users' => $users,
            'title' => 'Manage Users - ' . Config::get('APP_TITLE', 'Chat'),
            'view' => 'admin/users'
        ]);
    }

    private function updateUser(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($this->csrfToken, $_POST['csrf_token'] ?? '')) {
            die('Invalid CSRF');
        }

        $id = (int)$_POST['id'];
        $role = $_POST['role'];
        $rank = (int)$_POST['rank'];
        $banned = isset($_POST['banned']) ? 1 : 0;

        if ($id === (int)$this->currentUser->id && $role !== 'admin') {
             die('Cannot demote yourself');
        }

        $stmt = $this->db->prepare('UPDATE users SET role = ?, rank = ?, banned = ? WHERE id = ?');
        $stmt->execute([$role, $rank, $banned, $id]);

        header('Location: ?admin=users');
        exit;
    }

    private function manageWords(): void
    {
        $words = file_get_contents(Config::WORDS_PATH);

        $view = new ViewController();
        $view->render('admin/words', [
            'currentUser' => $this->currentUser,
            'csrfToken' => $this->csrfToken,
            'words' => $words,
            'title' => 'Manage Allowed Words - ' . Config::get('APP_TITLE', 'Chat'),
            'view' => 'admin/words'
        ]);
    }

    private function updateWords(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($this->csrfToken, $_POST['csrf_token'] ?? '')) {
            die('Invalid CSRF');
        }

        $content = $_POST['words'];
        file_put_contents(Config::WORDS_PATH, $content);

        header('Location: ?admin=words');
        exit;
    }
}
