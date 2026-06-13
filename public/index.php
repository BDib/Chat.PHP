<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Auth\SessionManager;
use App\Auth\Authenticator;
use App\Controller\ApiController;
use App\Controller\ViewController;
use App\Controller\AdminController;

Config::load();

$db = Database::get();
$sessionManager = new SessionManager($db);
$sessionManager->gc();

$session = $sessionManager->load() ?? $sessionManager->create();
$csrfToken = $session['csrf_token'];

$authenticator = new Authenticator();
$currentUser = null;

if ($session['user_id'] !== null) {
    $currentUser = $authenticator->getUserById($session['user_id']);
    if (!$currentUser) {
        $sessionManager->delete($session['token']);
        $session = $sessionManager->create();
        $csrfToken = $session['csrf_token'];
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Logout
if (isset($_GET['logout'])) {
    $sessionManager->delete($session['token']);
    header('Location: ./');
    exit;
}

// Admin router
if (isset($_GET['admin'])) {
    if (!$currentUser) {
        header('Location: ./');
        exit;
    }
    (new AdminController($currentUser, $csrfToken))->handle($_GET['admin']);
    exit;
}

// API router
if (isset($_GET['api'])) {
    (new ApiController($currentUser, $csrfToken, $ip))->handle($_GET['api']);
    exit;
}

// File serving
if ($currentUser && isset($_GET['file'])) {
    $chatService = new \App\Chat\ChatService();
    $file = $chatService->getFile($_GET['file']);
    if (!$file) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $file['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=31536000, immutable');
    echo $file['data'];
    exit;
}

// Auth processing
$authError = '';
if ($authenticator->isIpBanned($ip)) {
    $authError = 'You are permanently banned.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
    $authError = 'Invalid request. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'], $_POST['action'])) {
    $action = $_POST['action'];
    if ($_POST['username'] === '' || $_POST['password'] === '') {
        $authError = 'Username and password are required.';
    } elseif ($action === 'register') {
        $error = $authenticator->register($_POST['username'], $_POST['password'], $ip);
        if ($error) {
            $authError = $error;
        } else {
            // Re-fetch user to login
            $user = $authenticator->login($_POST['username'], $_POST['password'], date(Config::DATE_FORMAT));
            $sessionManager->regenerate($session['token'], $user['id']);
            header('Location: ./');
            exit;
        }
    } elseif ($action === 'login') {
        $result = $authenticator->login($_POST['username'], $_POST['password'], date(Config::DATE_FORMAT));
        if (is_string($result)) {
            $authError = $result;
        } else {
            $sessionManager->regenerate($session['token'], $result['id']);
            header('Location: ./');
            exit;
        }
    }
}

// View rendering
$viewController = new ViewController();
if ($currentUser) {
    $viewController->renderChat($currentUser, $csrfToken);
} else {
    $viewController->renderAuth($authError, $csrfToken);
}
