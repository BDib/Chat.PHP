<?php

namespace App\Controller;

use App\Config;

class ViewController
{
    public function render(string $view, array $data = []): void
    {
        extract($data);
        require __DIR__ . '/../../views/layout.php';
    }

    public function renderAuth(string $authError, string $csrfToken): void
    {
        $this->render('auth', [
            'authError' => $authError,
            'csrfToken' => $csrfToken,
            'title' => Config::TITLE,
            'view' => 'auth'
        ]);
    }

    public function renderChat(object $currentUser, string $csrfToken): void
    {
        $this->render('chat', [
            'currentUser' => $currentUser,
            'csrfToken' => $csrfToken,
            'title' => Config::TITLE,
            'view' => 'chat'
        ]);
    }
}
