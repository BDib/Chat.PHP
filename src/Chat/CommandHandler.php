<?php

namespace App\Chat;

use App\Config;
use App\Database;
use DateTime;
use DateTimeZone;
use PDO;

class CommandHandler
{
    private PDO $db;

    public function __construct(
        private ChatService $chatService,
        private object $currentUser,
        private string $ip
    ) {
        $this->db = Database::get();
    }

    public function handle(string $text): bool
    {
        if ($text[0] !== '/') return false;

        $parts = explode(' ', substr($text, 1));
        $cmdName = $parts[0];
        $commands = $this->getCommands();

        if (isset($commands[$cmdName])) {
            $cmd = $commands[$cmdName];
            $this->requireRole(...$cmd['roles']);
            if (preg_match($cmd['pattern'], $text, $m)) {
                $args = array_slice($m, 1);
                ($cmd['handler'])(...$args);
                return true;
            }
            $this->jsonResponse(['error' => "Usage: {$cmd['usage']}"]);
        }

        $available = [];
        foreach ($commands as $cmd) {
            if (in_array($this->currentUser->role, $cmd['roles'], true)) {
                $available[] = $cmd['usage'];
            }
        }
        $list = $available ? "Unknown command. Available:\n" . implode("\n", $available) : 'No commands available.';
        $this->jsonResponse(['error' => $list]);
        return true;
    }

    private function getCommands(): array
    {
        return [
            'help' => [
                'pattern' => '#^/help$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/help',
                'handler' => function () {
                    $rank = $this->currentUser->rank;
                    $this->jsonResponse(['error' => <<<HELP
                    You are rank {$rank}.

                    As you chat, you earn rank and unlock new abilities:

                    Rank 0 — Newcomer
                    Send short messages (70 chars), 6 per minute.

                    Rank 1 — Regular
                    Longer messages (2000 chars), 10 per minute.
                    /promote <user> — promote someone (up to your rank).

                    Rank 2 — Chatter
                     Style your messages: start with _ for italic.
                    20 messages per minute.

                    Rank 3 — Local
                    /status <word> — show a custom status in the sidebar.
                    /roll <N>d<S> — roll dice (e.g. 2d6, 1d20).

                    Rank 4 — Trusted
                    /color <#RRGGBB|reset> — pick a name color.
                    Start with * for bold.

                    Rank 5 — Veteran
                    Post URLs freely.
                    Start with __ for underline.

                    Rank 6 — Elite
                    Start with ~ for rainbow gradient text.
                    Start with ^ for animated fire text.

                    Rank 7 — Guardian
                    /mute <user> <time> — silence lower ranks (max 5m).
                    /unmute <user> — remove a mute.

                    Rank 8 — Champion
                    Upload GIF images.
                    /kick <user> <time> — kick lower ranks (max 10m).
                    /unkick <user> — remove a kick.

                    Rank 9 — Legend
                    Start a message with # to make it big and bold.
                    Start with ~~ for wave text.
                    Start with ^^ for cold breeze text.
                    HELP]);
                },
            ],
            'roll' => [
                'pattern' => '#^/roll (\d+)d(\d+)$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/roll <N>d<S> (e.g. 2d6, 1d20)',
                'handler' => function ($count, $sides) {
                    if ($this->currentUser->rank < 3) {
                        $this->jsonResponse(['error' => 'You need rank 3+ to roll dice.']);
                    }
                    $count = (int)$count;
                    $sides = (int)$sides;
                    if ($count < 1 || $count > 10) $this->jsonResponse(['error' => 'Roll 1-10 dice.']);
                    if ($sides < 2 || $sides > 100) $this->jsonResponse(['error' => 'Dice must have 2-100 sides.']);
                    $rolls = [];
                    for ($i = 0; $i < $count; $i++) $rolls[] = random_int(1, $sides);
                    $total = array_sum($rolls);
                    $detail = implode(' + ', $rolls);
                    $name = $this->currentUser->username;
                    $msg = $count > 1 ? "{$name} rolled {$count}d{$sides}: {$detail} = {$total}" : "{$name} rolled d{$sides}: {$total}";
                    $this->chatService->systemMessage($msg, $this->currentUser->username, $this->ip);
                },
            ],
            'info' => [
                'pattern' => '#^/info ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/info <username>',
                'handler' => function ($username) {
                    $stmt = $this->db->prepare('SELECT rank, created_at FROM users WHERE username = ?');
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();
                    if (!$user) $this->jsonResponse(['error' => 'User not found']);
                    $created = new DateTime($user['created_at'], new DateTimeZone('UTC'));
                    $diff = (new DateTime('now', new DateTimeZone('UTC')))->diff($created);
                    if ($diff->days === 0) $ago = 'today';
                    elseif ($diff->days === 1) $ago = '1 day ago';
                    else $ago = "{$diff->days} days ago";
                    $this->jsonResponse(['error' => "{$username}: rank {$user['rank']}, joined {$ago}"]);
                },
            ],
            'mod' => [
                'pattern' => '#^/mod (add|del) ([a-z0-9]+)$#',
                'roles' => ['admin'],
                'usage' => '/mod add|del <username>',
                'handler' => function ($action, $username) {
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin') {
                        $this->jsonResponse(['error' => 'Cannot change admin role']);
                    }
                    $newRole = $action === 'add' ? 'moderator' : 'user';
                    $this->db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $target['id']]);
                    $msg = $action === 'add' ? "{$username} is now a moderator" : "{$username} is no longer a moderator";
                    $this->chatService->systemMessage($msg, $this->currentUser->username, $this->ip);
                },
            ],
            'ban' => [
                'pattern' => '#^/ban ([a-z0-9,\s]+)$#',
                'roles' => ['admin', 'moderator'],
                'usage' => '/ban <username>[, username2, ...]',
                'handler' => function ($input) {
                    $usernames = array_unique(array_filter(array_map('trim', explode(',', $input))));
                    if (empty($usernames)) $this->jsonResponse(['error' => 'No usernames provided']);
                    foreach ($usernames as $username) {
                        if (!preg_match('/^[a-z0-9]+$/', $username)) $this->jsonResponse(['error' => "Invalid username: {$username}"]);
                    }
                    $banned = [];
                    foreach ($usernames as $username) {
                        $target = $this->chatService->findUser($username);
                        if (!$target) continue;
                        if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $this->currentUser->role !== 'admin')) {
                            continue;
                        }
                        $this->db->prepare('UPDATE users SET banned = 1, rank = 0 WHERE id = ?')->execute([$target['id']]);
                        $banned[] = $username;
                    }
                    if (empty($banned)) $this->jsonResponse(['error' => 'No users could be banned']);
                    $list = implode(', ', $banned);
                    $this->chatService->systemMessage("{$list} banned by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'unban' => [
                'pattern' => '#^/unban ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator'],
                'usage' => '/unban <username>',
                'handler' => function ($username) {
                    $target = $this->requireTarget($username);
                    $this->db->prepare('UPDATE users SET banned = 0 WHERE id = ?')->execute([$target['id']]);
                    $this->chatService->systemMessage("{$username} was unbanned by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'shadowban' => [
                'pattern' => '#^/shadowban ([a-z0-9]+)$#',
                'roles' => ['admin'],
                'usage' => '/shadowban <username>',
                'handler' => function ($username) {
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin') {
                        $this->jsonResponse(['error' => 'Cannot shadow ban an admin']);
                    }
                    $targetIp = $target['last_ip'] ?: '0.0.0.0';
                    $now = date(Config::DATE_FORMAT);
                    $this->db->prepare('INSERT INTO shadow_bans (username, ip, banned_by, created_at) VALUES (?, ?, ?, ?)')
                        ->execute([$username, $targetIp, $this->currentUser->username, $now]);
                    $this->jsonResponse(['error' => "{$username} is now shadow banned ({$targetIp})"]);
                },
            ],
            'unshadowban' => [
                'pattern' => '#^/unshadowban ([a-z0-9]+)$#',
                'roles' => ['admin'],
                'usage' => '/unshadowban <username>',
                'handler' => function ($username) {
                    $this->requireTarget($username);
                    $this->db->prepare('DELETE FROM shadow_bans WHERE username = ?')->execute([$username]);
                    $this->jsonResponse(['error' => "{$username} is no longer shadow banned"]);
                },
            ],
            'del' => [
                'pattern' => '#^/del ((?:\d+ ?)+)$#',
                'roles' => ['admin', 'moderator'],
                'usage' => '/del <id> [<id> ...]',
                'handler' => function ($arg) {
                    $ids = array_unique(array_map('intval', preg_split('/\s+/', trim($arg))));
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $this->db->prepare("SELECT id FROM messages WHERE id IN ({$placeholders}) AND kind = 'text'");
                    $stmt->execute($ids);
                    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
                    if (!$ids) $this->jsonResponse(['error' => 'No messages found']);

                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $this->db->prepare("DELETE FROM messages WHERE id IN ({$placeholders}) AND kind = 'text'")->execute($ids);

                    $now = date(Config::DATE_FORMAT);
                    $stmt = $this->db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$this->currentUser->username, json_encode($ids), $now, $this->ip, 'delete']);

                    $count = count($ids);
                    $this->chatService->systemMessage("{$this->currentUser->username} deleted {$count} message(s)", $this->currentUser->username, $this->ip);
                },
            ],
            'delall' => [
                'pattern' => '#^/delall ([a-z0-9]+)$#',
                'roles' => ['admin'],
                'usage' => '/delall <username>',
                'handler' => function ($username) {
                    $this->requireTarget($username);
                    $stmt = $this->db->prepare("SELECT id FROM messages WHERE username = ? AND kind = 'text'");
                    $stmt->execute([$username]);
                    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
                    if (!$ids) $this->jsonResponse(['error' => 'No messages found']);

                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $this->db->prepare("DELETE FROM messages WHERE id IN ({$placeholders}) AND kind = 'text'")->execute($ids);

                    $now = date(Config::DATE_FORMAT);
                    $stmt = $this->db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$this->currentUser->username, json_encode($ids), $now, $this->ip, 'delete']);

                    $count = count($ids);
                    $this->chatService->systemMessage("{$this->currentUser->username} deleted all {$count} message(s) from {$username}", $this->currentUser->username, $this->ip);
                },
            ],
            'kick' => [
                'pattern' => '#^/kick ([a-z0-9]+) (\d+[smhd])$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/kick <username> <duration> (e.g. 5m, 1h, 2d)',
                'handler' => function ($username, $duration) {
                    $isMod = in_array($this->currentUser->role, ['admin', 'moderator'], true);
                    if (!$isMod && $this->currentUser->rank < 8) {
                        $this->jsonResponse(['error' => 'You need rank 8+ or moderator role to kick.']);
                    }
                    if (!$isMod) {
                        $error = $this->chatService->checkCooldown($this->currentUser->id, 'kick', 'kick/unkick');
                        if ($error) $this->jsonResponse(['error' => $error]);
                    }
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin') $this->jsonResponse(['error' => 'Cannot kick admin.']);
                    if ($target['role'] === 'moderator' && $this->currentUser->role !== 'admin') $this->jsonResponse(['error' => 'Only admin can kick moderators.']);
                    if (!$isMod && $target['rank'] >= $this->currentUser->rank) $this->jsonResponse(['error' => 'You can only kick lower-ranked users.']);

                    $seconds = $this->parseDuration($duration);
                    if (!$seconds || $seconds > 86400 * 30) $this->jsonResponse(['error' => 'Invalid duration.']);
                    if (!$isMod && $seconds > 600) $this->jsonResponse(['error' => 'Rank 8 can only kick for up to 10m.']);

                    $until = (new DateTime('now', new DateTimeZone('UTC')))->modify("+{$seconds} seconds")->format(Config::DATE_FORMAT);
                    $this->db->prepare('UPDATE users SET kicked_until = ? WHERE id = ?')->execute([$until, $target['id']]);

                    if (!$isMod) $this->chatService->recordCooldown($this->currentUser->id, 'kick');
                    $this->chatService->systemMessage("{$username} was kicked for {$duration} by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'unkick' => [
                'pattern' => '#^/unkick ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/unkick <username>',
                'handler' => function ($username) {
                    $isMod = in_array($this->currentUser->role, ['admin', 'moderator'], true);
                    if (!$isMod && $this->currentUser->rank < 8) {
                        $this->jsonResponse(['error' => 'You need rank 8+ or moderator role to unkick.']);
                    }
                    if (!$isMod) {
                        $error = $this->chatService->checkCooldown($this->currentUser->id, 'kick', 'kick/unkick');
                        if ($error) $this->jsonResponse(['error' => $error]);
                    }
                    $target = $this->requireTarget($username);
                    $this->db->prepare('UPDATE users SET kicked_until = NULL WHERE id = ?')->execute([$target['id']]);
                    if (!$isMod) $this->chatService->recordCooldown($this->currentUser->id, 'kick');
                    $this->chatService->systemMessage("{$username} was unkicked by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'ip' => [
                'pattern' => '#^/ip ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator'],
                'usage' => '/ip <username>',
                'handler' => function ($username) {
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin' && $this->currentUser->role !== 'admin') {
                        $this->jsonResponse(['error' => 'Cannot view admin IP']);
                    }
                    $stmt = $this->db->prepare('SELECT last_ip FROM users WHERE username = ?');
                    $stmt->execute([$username]);
                    $userIp = $stmt->fetchColumn();
                    $stmt = $this->db->prepare('SELECT DISTINCT ip FROM messages WHERE username = ?');
                    $stmt->execute([$username]);
                    $allIps = array_column($stmt->fetchAll(), 'ip');
                    if ($userIp && !in_array($userIp, $allIps, true)) {
                        array_unshift($allIps, $userIp);
                    }
                    if (!$allIps) $this->jsonResponse(['error' => "{$username}: no IP data"]);

                    $placeholders = implode(',', array_fill(0, count($allIps), '?'));
                    $stmt = $this->db->prepare("SELECT DISTINCT username FROM users WHERE last_ip IN ({$placeholders}) AND username != ?");
                    $stmt->execute([...$allIps, $username]);
                    $sharedUsers = array_column($stmt->fetchAll(), 'username');

                    $stmt = $this->db->prepare("SELECT DISTINCT username FROM messages WHERE ip IN ({$placeholders}) AND username != ?");
                    $stmt->execute([...$allIps, $username]);
                    $sharedMsgUsers = array_column($stmt->fetchAll(), 'username');

                    $alts = array_unique(array_merge($sharedUsers, $sharedMsgUsers));
                    sort($alts);
                    $info = "{$username}: " . implode(', ', $allIps);
                    if ($alts) $info .= "\nAlso used by: " . implode(', ', $alts);
                    $this->jsonResponse(['error' => $info]);
                },
            ],
            'rank' => [
                'pattern' => '#^/rank ([a-z0-9]+) ([0-9])$#',
                'roles' => ['admin'],
                'usage' => '/rank <username> <0-9>',
                'handler' => function ($username, $rank) {
                    $target = $this->requireTarget($username);
                    $rank = (int)$rank;
                    $this->db->prepare('UPDATE users SET rank = ? WHERE id = ?')->execute([$rank, $target['id']]);
                    $this->chatService->systemMessage("{$username} is now rank {$rank}", $this->currentUser->username, $this->ip);
                },
            ],
            'promotions' => [
                'pattern' => '#^/promotions ([a-z0-9]+)$#',
                'roles' => ['admin'],
                'usage' => '/promotions <username>',
                'handler' => function ($username) {
                    $this->requireTarget($username);
                    $stmt = $this->db->prepare("SELECT message FROM messages WHERE kind = 'system' AND message LIKE '% promoted to rank % by ' || ?");
                    $stmt->execute([$username]);
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!$rows) $this->jsonResponse(['error' => "No promotions by {$username}"]);
                    $names = [];
                    foreach ($rows as $msg) {
                        if (preg_match('/^(\S+) was promoted to rank/', $msg, $m)) {
                            $names[$m[1]] = true;
                        }
                    }
                    $list = implode(', ', array_keys($names));
                    $this->jsonResponse(['error' => "Users promoted by {$username}: {$list}"]);
                },
            ],
            'promotedby' => [
                'pattern' => '#^/promotedby ([a-z0-9]+)$#',
                'roles' => ['admin'],
                'usage' => '/promotedby <username>',
                'handler' => function ($username) {
                    $this->requireTarget($username);
                    $stmt = $this->db->prepare("SELECT message FROM messages WHERE kind = 'system' AND message LIKE ? || ' was promoted to rank % by %'");
                    $stmt->execute([$username]);
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!$rows) $this->jsonResponse(['error' => "No promotion history for {$username}"]);
                    $promoters = [];
                    foreach ($rows as $msg) {
                        if (preg_match('/was promoted to rank (\d+) by (\S+)$/', $msg, $m)) {
                            $promoters[] = "{$m[2]} (to rank {$m[1]})";
                        }
                    }
                    $list = implode(', ', $promoters);
                    $this->jsonResponse(['error' => "Users who promoted {$username}: {$list}"]);
                },
            ],
            'ipban' => [
                'pattern' => '#^/ipban ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator'],
                'usage' => '/ipban <username>',
                'handler' => function ($username) {
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin') $this->jsonResponse(['error' => 'Cannot IP ban admin']);
                    $ips = $this->db->prepare('SELECT DISTINCT ip FROM messages WHERE username = ? UNION SELECT last_ip FROM users WHERE username = ?');
                    $ips->execute([$username, $username]);
                    $allIps = $ips->fetchAll(PDO::FETCH_COLUMN);
                    if (!$allIps) $this->jsonResponse(['error' => 'No IPs found for this user.']);

                    $now = date(Config::DATE_FORMAT);
                    $insert = $this->db->prepare('INSERT OR IGNORE INTO ip_bans (ip, username, banned_by, created_at) VALUES (?, ?, ?, ?)');
                    foreach ($allIps as $ip) {
                        $insert->execute([$ip, $username, $this->currentUser->username, $now]);
                    }
                    $count = count($allIps);
                    $this->chatService->systemMessage("{$username} was IP banned by {$this->currentUser->username} ({$count} IPs)", $this->currentUser->username, $this->ip);
                },
            ],
            'ipban-list' => [
                'pattern' => '#^/ipban-list$#',
                'roles' => ['admin', 'moderator'],
                'usage' => '/ipban-list',
                'handler' => function () {
                    $rows = $this->db->query('SELECT username, ip, banned_by, created_at FROM ip_bans ORDER BY username, created_at DESC')->fetchAll();
                    if (!$rows) $this->jsonResponse(['error' => 'No IP bans.']);
                    $lines = array_map(fn($r) => "{$r['username']}: {$r['ip']} (by {$r['banned_by']}, {$r['created_at']})", $rows);
                    $this->jsonResponse(['error' => "IP bans:\n" . implode("\n", $lines)]);
                },
            ],
            'ipunban' => [
                'pattern' => '#^/ipunban ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator'],
                'usage' => '/ipunban <username>',
                'handler' => function ($username) {
                    $this->requireTarget($username);
                    $this->db->prepare('DELETE FROM ip_bans WHERE username = ?')->execute([$username]);
                    $this->chatService->systemMessage("{$username} was IP unbanned by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'ipban-unban-all' => [
                'pattern' => '#^/ipban-unban-all$#',
                'roles' => ['admin'],
                'usage' => '/ipban-unban-all',
                'handler' => function () {
                    $count = $this->db->query('SELECT COUNT(*) FROM ip_bans')->fetchColumn();
                    if (!$count) $this->jsonResponse(['error' => 'No IP bans to clear.']);
                    $this->db->exec('DELETE FROM ip_bans');
                    $this->jsonResponse([]);
                },
            ],
            'color' => [
                'pattern' => '/^\/color\s+(reset|\#[0-9a-fA-F]{6})$/',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/color <#RRGGBB|reset>',
                'handler' => function ($value) {
                    if ($this->currentUser->rank < 4) $this->jsonResponse(['error' => 'You need rank 4+ to use colored names.']);
                    if ($value === 'reset') {
                        $this->db->prepare('UPDATE users SET color = NULL WHERE id = ?')->execute([$this->currentUser->id]);
                        $this->jsonResponse(['error' => 'Name color reset.']);
                    }
                    $this->validateColor($value);
                    $this->db->prepare('UPDATE users SET color = ? WHERE id = ?')->execute([$value, $this->currentUser->id]);
                    $this->jsonResponse(['error' => "Name color set to {$value}."]);
                },
            ],
            'setcolor' => [
                'pattern' => '/^\/setcolor\s+([a-z0-9]+)\s+(reset|\#[0-9a-fA-F]{6})$/',
                'roles' => ['admin', 'moderator'],
                'usage' => '/setcolor <username> <#RRGGBB|reset>',
                'handler' => function ($username, $value) {
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin' && $this->currentUser->role !== 'admin') $this->jsonResponse(['error' => 'Only admin can change admin colors.']);
                    if ($value === 'reset') {
                        $this->db->prepare('UPDATE users SET color = NULL WHERE id = ?')->execute([$target['id']]);
                        $this->chatService->systemMessage("{$this->currentUser->username} reset {$username}'s color", $this->currentUser->username, $this->ip);
                        $this->jsonResponse([]);
                    }
                    $this->validateColor($value);
                    $this->db->prepare('UPDATE users SET color = ? WHERE id = ?')->execute([$value, $target['id']]);
                    $this->chatService->systemMessage("{$this->currentUser->username} set {$username}'s color to {$value}", $this->currentUser->username, $this->ip);
                    $this->jsonResponse([]);
                },
            ],
            'promote' => [
                'pattern' => '#^/promote ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/promote <username>',
                'handler' => function ($username) {
                    if ($this->currentUser->rank < 1) $this->jsonResponse(['error' => 'You need at least rank 1 to promote.']);
                    $isMod = in_array($this->currentUser->role, ['admin', 'moderator'], true);
                    if (!$isMod) {
                        $error = $this->chatService->checkCooldown($this->currentUser->id, 'promote', 'promote', 3600);
                        if ($error) $this->jsonResponse(['error' => $error]);
                    }
                    $target = $this->requireTarget($username);
                    if ($target['username'] === $this->currentUser->username) $this->jsonResponse(['error' => 'You cannot promote yourself.']);
                    $newRank = $target['rank'] + 1;
                    $maxRank = $this->currentUser->rank - 1;
                    if ($newRank > $maxRank) {
                        $this->jsonResponse(['error' => "You can only promote up to rank {$maxRank}."]);
                    }
                    $this->db->prepare('UPDATE users SET rank = ? WHERE id = ?')->execute([$newRank, $target['id']]);
                    if (!$isMod) $this->chatService->recordCooldown($this->currentUser->id, 'promote');
                    $this->chatService->systemMessage("{$username} was promoted to rank {$newRank} by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'mute' => [
                'pattern' => '#^/mute ([a-z0-9]+) (\d+[smhd])$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/mute <username> <duration> (e.g. 30s, 5m, 1h)',
                'handler' => function ($username, $duration) {
                    $isMod = in_array($this->currentUser->role, ['admin', 'moderator'], true);
                    if (!$isMod && $this->currentUser->rank < 7) $this->jsonResponse(['error' => 'You need rank 7+ to mute.']);
                    if (!$isMod) {
                        $error = $this->chatService->checkCooldown($this->currentUser->id, 'mute', 'mute/unmute');
                        if ($error) $this->jsonResponse(['error' => $error]);
                    }
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $this->currentUser->role !== 'admin')) $this->jsonResponse(['error' => 'Cannot mute this user.']);
                    if (!$isMod && $target['rank'] >= $this->currentUser->rank) $this->jsonResponse(['error' => 'You can only mute lower-ranked users.']);

                    $seconds = $this->parseDuration($duration);
                    if (!$seconds || $seconds > 86400 * 30) $this->jsonResponse(['error' => 'Invalid duration.']);
                    if (!$isMod && $seconds > 300) $this->jsonResponse(['error' => 'Max mute duration is 5m.']);

                    $until = (new DateTime('now', new DateTimeZone('UTC')))->modify("+{$seconds} seconds")->format(Config::DATE_FORMAT);
                    $this->db->prepare('UPDATE users SET muted_until = ? WHERE id = ?')->execute([$until, $target['id']]);
                    if (!$isMod) $this->chatService->recordCooldown($this->currentUser->id, 'mute');
                    $this->chatService->systemMessage("{$username} was muted for {$duration} by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'unmute' => [
                'pattern' => '#^/unmute ([a-z0-9]+)$#',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/unmute <username>',
                'handler' => function ($username) {
                    $isMod = in_array($this->currentUser->role, ['admin', 'moderator'], true);
                    if (!$isMod && $this->currentUser->rank < 7) $this->jsonResponse(['error' => 'You need rank 7+ to unmute.']);
                    if (!$isMod) {
                        $error = $this->chatService->checkCooldown($this->currentUser->id, 'mute', 'mute/unmute');
                        if ($error) $this->jsonResponse(['error' => $error]);
                    }
                    $target = $this->requireTarget($username);
                    $this->db->prepare('UPDATE users SET muted_until = NULL WHERE id = ?')->execute([$target['id']]);
                    if (!$isMod) $this->chatService->recordCooldown($this->currentUser->id, 'mute');
                    $this->chatService->systemMessage("{$username} was unmuted by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'status' => [
                'pattern' => '/^\/status\s+(.+)$/',
                'roles' => ['admin', 'moderator', 'user'],
                'usage' => '/status <word|reset>',
                'handler' => function ($value) {
                    if ($this->currentUser->rank < 3) $this->jsonResponse(['error' => 'You need rank 3+ to set a status.']);
                    if ($value === 'reset') {
                        $this->db->prepare('UPDATE users SET status = NULL WHERE id = ?')->execute([$this->currentUser->id]);
                        $this->jsonResponse(['error' => 'Status cleared.']);
                    }
                    if (!preg_match('/^[a-z]+$/', $value)) $this->jsonResponse(['error' => 'Status must be a single lowercase word (a-z).']);
                    if (strlen($value) > 10) $this->jsonResponse(['error' => 'Status must be 10 characters or less.']);
                    $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$value, $this->currentUser->id]);
                    $this->jsonResponse(['error' => "Status set to: {$value}"]);
                },
            ],
            'setstatus' => [
                'pattern' => '/^\/setstatus\s+([a-z0-9]+)\s+(.+)$/',
                'roles' => ['admin', 'moderator'],
                'usage' => '/setstatus <username> <word|reset>',
                'handler' => function ($username, $value) {
                    $target = $this->requireTarget($username);
                    if ($target['role'] === 'admin' && $this->currentUser->role !== 'admin') $this->jsonResponse(['error' => 'Only admin can change admin status.']);
                    if ($value === 'reset') {
                        $this->db->prepare('UPDATE users SET status = NULL WHERE id = ?')->execute([$target['id']]);
                        $this->chatService->systemMessage("{$this->currentUser->username} reset {$username}'s status", $this->currentUser->username, $this->ip);
                        $this->jsonResponse([]);
                    }
                    if (!preg_match('/^[a-z]+$/', $value)) $this->jsonResponse(['error' => 'Status must be a single lowercase word (a-z).']);
                    if (strlen($value) > 10) $this->jsonResponse(['error' => 'Status must be 10 characters or less.']);
                    $this->db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$value, $target['id']]);
                    $this->chatService->systemMessage("{$this->currentUser->username} set {$username}'s status to {$value}", $this->currentUser->username, $this->ip);
                    $this->jsonResponse([]);
                },
            ],
            'sys' => [
                'pattern' => '/^\/sys\s+(.+)$/',
                'roles' => ['admin'],
                'usage' => '/sys <message>',
                'handler' => function ($msg) {
                    if ($this->currentUser->rank < 9) $this->jsonResponse(['error' => 'You need rank 9 to send system messages.']);
                    $this->chatService->systemMessage($msg, $this->currentUser->username, $this->ip);
                },
            ],
            'demote' => [
                'pattern' => '#^/demote ([0-9]) ([a-z0-9,\s]+)$#',
                'roles' => ['admin'],
                'usage' => '/demote <0-9> <username>[, username2, ...]',
                'handler' => function ($rank, $input) {
                    $rank = (int)$rank;
                    $usernames = array_unique(array_filter(array_map('trim', explode(',', $input))));
                    if (empty($usernames)) $this->jsonResponse(['error' => 'No usernames provided']);
                    foreach ($usernames as $username) {
                        if (!preg_match('/^[a-z0-9]+$/', $username)) $this->jsonResponse(['error' => "Invalid username: {$username}"]);
                    }
                    $demoted = [];
                    foreach ($usernames as $username) {
                        $target = $this->chatService->findUser($username);
                        if (!$target) continue;
                        if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $this->currentUser->role !== 'admin')) continue;
                        if ($target['rank'] <= $rank) continue;
                        $this->db->prepare('UPDATE users SET rank = ? WHERE id = ?')->execute([$rank, $target['id']]);
                        $demoted[] = $username;
                    }
                    if (empty($demoted)) $this->jsonResponse(['error' => 'No users were demoted']);
                    $list = implode(', ', $demoted);
                    $this->chatService->systemMessage("{$list} demoted to rank {$rank} by {$this->currentUser->username}", $this->currentUser->username, $this->ip);
                },
            ],
            'demoteall' => [
                'pattern' => '#^/demoteall ([0-9])$#',
                'roles' => ['admin'],
                'usage' => '/demoteall <max-rank>',
                'handler' => function ($max) {
                    $max = (int)$max;
                    $stmt = $this->db->prepare('UPDATE users SET rank = ? WHERE rank > ? AND role != ?');
                    $stmt->execute([$max, $max, 'admin']);
                    $affected = $stmt->rowCount();
                    $this->chatService->systemMessage("{$this->currentUser->username} demoted everyone above rank {$max} back to {$max} ({$affected} users affected)", $this->currentUser->username, $this->ip);
                },
            ],
            'world' => [
                'pattern' => '#^/world (shake|flip|blur|disco|matrix|confetti|flash|wave|reload|blackout|rain)$#',
                'roles' => ['admin'],
                'usage' => '/world <shake|flip|blur|disco|matrix|confetti|flash|wave|reload|blackout|rain>',
                'handler' => function ($effect) {
                    $now = date(Config::DATE_FORMAT);
                    $stmt = $this->db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$this->currentUser->username, $effect, $now, $this->ip, 'effect']);
                },
            ],
        ];
    }

    private function requireRole(string ...$allowed): void
    {
        if (!in_array($this->currentUser->role, $allowed, true)) {
            $this->jsonResponse(['error' => 'Permission denied']);
        }
    }

    private function requireTarget(string $username): array
    {
        $target = $this->chatService->findUser($username);
        if (!$target) $this->jsonResponse(['error' => 'User not found']);
        return $target;
    }

    private function jsonResponse(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function validateColor(string $value): void
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            $this->jsonResponse(['error' => 'Color must be a 6-digit hex value like #AF32A2.']);
        }
        $hex = substr($value, 1);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $lum = 0.2126 * $r / 255 + 0.7152 * $g / 255 + 0.0722 * $b / 255;
        if ($lum < 0.1) $this->jsonResponse(['error' => 'That color is too dark — it won\'t be readable.']);
        if ($lum > 0.9) $this->jsonResponse(['error' => 'That color is too bright — it won\'t be readable.']);
    }

    private function parseDuration(string $s): ?int
    {
        if (preg_match('/^(\d+)([smhd])$/', $s, $m)) {
            return (int)$m[1] * match ($m[2]) {
                's' => 1,
                'm' => 60,
                'h' => 3600,
                'd' => 86400
            };
        }
        return null;
    }
}
