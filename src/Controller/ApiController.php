<?php

namespace App\Controller;

use App\Config;
use App\Database;
use App\Auth\SessionManager;
use App\Chat\ChatService;
use App\Chat\CommandHandler;
use App\Chat\WordFilter;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;

class ApiController
{
    private ChatService $chatService;
    private SessionManager $sessionManager;

    public function __construct(
        private ?object $currentUser,
        private string $csrfToken,
        private string $ip
    ) {
        $this->chatService = new ChatService();
        $this->sessionManager = new SessionManager(Database::get());
    }

    public function handle(string $api): void
    {
        header('Content-Type: application/json');

        if (!$this->currentUser) {
            $this->jsonResponse(['kicked' => true]);
        }

        $now = date(Config::DATE_FORMAT);

        if ($this->currentUser->banned || (new \App\Auth\Authenticator())->isIpBanned($this->ip)) {
            $this->sessionManager->delete($_COOKIE['session'] ?? '');
            $this->jsonResponse(['kicked' => true, 'error' => 'You are permanently banned.']);
        }

        if ($this->currentUser->kicked_until && $this->currentUser->kicked_until > $now) {
            $this->sessionManager->delete($_COOKIE['session'] ?? '');
            $this->jsonResponse(['kicked' => true, 'error' => "You are kicked until {$this->currentUser->kicked_until}."]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($this->csrfToken, $_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $method = $_SERVER['REQUEST_METHOD'];

        switch ("$method /$api") {
            case 'GET /messages':
                $this->getMessages();
                break;
            case 'GET /history':
                $this->getHistory();
                break;
            case 'GET /message':
                $this->getMessage();
                break;
            case 'POST /offline':
                $this->offline();
                break;
            case 'POST /send':
                $this->send();
                break;
            default:
                $this->jsonResponse(['error' => 'Not found'], 404);
        }
    }

    private function getMessages(): void
    {
        $now = date(Config::DATE_FORMAT);
        if ($this->currentUser->last_seen < (new DateTime('now', new DateTimeZone('UTC')))->modify('-10 seconds')->format(Config::DATE_FORMAT)) {
            Database::get()->prepare('UPDATE users SET last_seen = ?, last_ip = ? WHERE id = ?')
                ->execute([$now, $this->ip, $this->currentUser->id]);
        }

        $after = (int)($_GET['after'] ?? 0);
        $messages = $this->chatService->getMessages($after);
        $messages = $this->filterShadowBanned($messages);

        // Strip ip
        foreach ($messages as &$m) unset($m['ip']);

        $users = $this->chatService->getOnlineUsers();
        $this->jsonResponse(['messages' => $messages, 'users' => $users]);
    }

    private function getHistory(): void
    {
        $before = (int)($_GET['before'] ?? 0);
        $messages = $this->chatService->getHistory($before);
        $messages = $this->filterShadowBanned($messages);
        foreach ($messages as &$m) unset($m['ip']);
        $this->jsonResponse(['messages' => $messages, 'hasMore' => count($messages) === 200]);
    }

    private function getMessage(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = Database::get()->prepare("SELECT username, message FROM messages WHERE id = ? AND kind = 'text'");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) $this->jsonResponse(['error' => 'Not found']);
        $this->jsonResponse($row);
    }

    private function offline(): void
    {
        $time = (new DateTime('now', new DateTimeZone('UTC')))->modify('-1 minutes')->format(Config::DATE_FORMAT);
        Database::get()->prepare('UPDATE users SET last_seen = ? WHERE id = ?')
            ->execute([$time, $this->currentUser->id]);
        $this->jsonResponse(['ok' => true]);
    }

    private function send(): void
    {
        if ($this->currentUser->muted_until && $this->currentUser->muted_until > date(Config::DATE_FORMAT)) {
            $this->jsonResponse(['error' => 'You are muted.']);
        }

        // Rate limit
        if ($this->currentUser->role !== 'admin') {
            $rateLimit = match (true) {
                $this->currentUser->rank >= 2 => 20,
                $this->currentUser->rank === 1 => 10,
                default => 6,
            };
            $cutoff = (new DateTime('now', new DateTimeZone('UTC')))->modify('-60 seconds')->format(Config::DATE_FORMAT);
            $stmt = Database::get()->prepare("SELECT COUNT(*) FROM messages WHERE username = ? AND created_at >= ? AND kind = 'text'");
            $stmt->execute([$this->currentUser->username, $cutoff]);
            if ($stmt->fetchColumn() >= $rateLimit) {
                $this->jsonResponse(['error' => 'Too many messages. Wait a moment.']);
            }
        }

        $replyTo = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
        if ($replyTo) {
            $check = Database::get()->prepare("SELECT id FROM messages WHERE id = ? AND kind = 'text'");
            $check->execute([$replyTo]);
            if (!$check->fetch()) $replyTo = null;
        }

        // Image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            if ($this->currentUser->rank < 8) $this->jsonResponse(['error' => 'Rank 8+ required to upload images.']);
            if ($_FILES['image']['size'] > 1024 * 1024) $this->jsonResponse(['error' => 'File too large. Max 1MB.']);
            if (mime_content_type($_FILES['image']['tmp_name']) !== 'image/gif') $this->jsonResponse(['error' => 'Only GIF images are allowed.']);

            $hash = $this->chatService->uploadFile($_FILES['image'], $this->currentUser->username);
            $this->chatService->sendMessage([
                'username' => $this->currentUser->username,
                'message' => '[img:' . $hash . ']',
                'created_at' => date(Config::DATE_FORMAT),
                'ip' => $this->ip,
                'reply_to' => $replyTo,
                'color' => $this->currentUser->color
            ]);
            $this->jsonResponse([]);
        }

        $text = trim($_POST['message'] ?? '');
        if ($text === '') $this->jsonResponse(['error' => 'This message is not allowed ¯\_(ツ)_/¯']);
        if (mb_strlen($text) > 2000) $this->jsonResponse(['error' => 'Message is too long']);

        // Rank checks
        if ($this->currentUser->rank < 9 && str_starts_with($text, '~~')) $this->jsonResponse(['error' => 'Rank 9 required for wave messages.']);
        if ($this->currentUser->rank < 9 && str_starts_with($text, '^^')) $this->jsonResponse(['error' => 'Rank 9 required for cold breeze messages.']);
        if ($this->currentUser->rank < 6 && (str_starts_with($text, '~') || str_starts_with($text, '^'))) $this->jsonResponse(['error' => 'Rank 6+ required for styled messages.']);
        if ($this->currentUser->rank < 9 && str_starts_with($text, '#')) $this->jsonResponse(['error' => 'Rank 9 required for big messages.']);
        if ($this->currentUser->rank < 5 && str_starts_with($text, '__')) $this->jsonResponse(['error' => 'Rank 5+ required for underline messages.']);
        if ($this->currentUser->rank < 4 && str_starts_with($text, '*')) $this->jsonResponse(['error' => 'Rank 4+ required for bold messages.']);
        if ($this->currentUser->rank < 2 && str_starts_with($text, '_')) $this->jsonResponse(['error' => 'Rank 2+ required for italic messages.']);

        if ($this->currentUser->rank < 5) {
            $text = preg_replace('#https?://\S+#i', '***', $text);
            $text = preg_replace('#\b\S+\.\S+/\S*#i', '***', $text);
        }

        if ($this->currentUser->rank === 0) {
            $lastMsg = Database::get()->prepare("SELECT created_at FROM messages WHERE username = ? AND kind = 'text' ORDER BY id DESC LIMIT 1");
            $lastMsg->execute([$this->currentUser->username]);
            $lastMsgTime = $lastMsg->fetchColumn();
            if ($lastMsgTime && (new DateTimeImmutable($lastMsgTime))->modify('+3 seconds') > (new DateTime('now', new DateTimeZone('UTC')))) {
                $this->jsonResponse(['error' => 'Wait 3 seconds between messages.']);
            }

            $isQuestion = str_ends_with($text, '?') || str_ends_with($text, '?!');
            $text = preg_replace('/[^a-zA-Z ]/u', '', $text);
            $text = preg_replace('/(\S{10})\S+/u', '$1…', $text);

            $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $filtered = [];
            foreach ($words as $word) {
                $lower = strtolower($word);
                if (!WordFilter::isAllowedWord($lower) || WordFilter::isBadWord($lower)) {
                    $filtered[] = str_repeat('•', min(mb_strlen($word), 3));
                } else {
                    $filtered[] = $word;
                }
            }
            $text = implode(' ', $filtered);
            $first = mb_substr($text, 0, 1);
            $text = $first . mb_strtolower(mb_substr($text, 1));
            $text = preg_replace('/\p{So}|\p{Cs}/u', '', $text);
            $text = preg_replace('/(•)\1{3,}/u', '•••', $text);
            $text = trim($text);
            if (mb_strlen($text) > 70) $text = mb_substr($text, 0, 70);
            if ($isQuestion) $text .= '?';
        }

        if ($text === '') $this->jsonResponse(['error' => 'This message is not allowed ¯\_(ツ)_/¯']);

        // Commands
        if ($text[0] === '/') {
            (new CommandHandler($this->chatService, $this->currentUser, $this->ip))->handle($text);
            $this->jsonResponse([]);
        }

        $this->chatService->sendMessage([
            'username' => $this->currentUser->username,
            'message' => $text,
            'created_at' => date(Config::DATE_FORMAT),
            'ip' => $this->ip,
            'reply_to' => $replyTo,
            'color' => $this->currentUser->color
        ]);
        $this->jsonResponse([]);
    }

    private function filterShadowBanned(array $messages): array
    {
        $db = Database::get();
        $sbRows = $db->query('SELECT DISTINCT username, ip FROM shadow_bans')->fetchAll();
        $sbUsernames = array_unique(array_column($sbRows, 'username'));
        $sbIps = array_unique(array_column($sbRows, 'ip'));

        $isAdmin = $this->currentUser->role === 'admin';
        $result = [];
        foreach ($messages as $msg) {
            if ($msg['kind'] !== 'text') {
                $result[] = $msg;
                continue;
            }
            $msgIp = $msg['ip'] ?? '';
            $banned = in_array($msg['username'], $sbUsernames, true) || in_array($msgIp, $sbIps, true);
            if ($isAdmin) {
                if ($banned) $msg['shadow_banned'] = true;
                $result[] = $msg;
            } elseif ($banned) {
                if ($msg['ip'] === $this->ip) $result[] = $msg;
            } else {
                $result[] = $msg;
            }
        }
        return array_values($result);
    }

    private function jsonResponse(array $data, int $code = 200): never
    {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }
}
