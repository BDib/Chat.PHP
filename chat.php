<?php
const DB_PATH = __DIR__ . '/db/chat.db';
const FILES_DB_PATH = __DIR__ . '/db/files.db';
const TITLE = 'Chat';

const SCHEMA = <<<SQL
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
PRAGMA synchronous = NORMAL;
PRAGMA cache_size = -64000;
PRAGMA foreign_keys = true;
PRAGMA temp_store = memory;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_seen TEXT NOT NULL,
    last_ip TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    kicked_until TEXT,
    muted_until TEXT,
    banned INTEGER NOT NULL DEFAULT 0,
    rank INTEGER NOT NULL DEFAULT 0,
    color TEXT DEFAULT NULL,
    status TEXT DEFAULT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_name ON users(username);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL,
    ip TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'text',
    reply_to INTEGER,
    color TEXT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at);

CREATE TABLE IF NOT EXISTS ip_bans (
    ip TEXT PRIMARY KEY,
    username TEXT NOT NULL,
    banned_by TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS shadow_bans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT NOT NULL,
    banned_by TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_shadow_bans_username ON shadow_bans(username);
CREATE INDEX IF NOT EXISTS idx_shadow_bans_ip ON shadow_bans(ip);

CREATE TABLE IF NOT EXISTS cooldowns (
    user_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    used_at TEXT NOT NULL,
    PRIMARY KEY (user_id, action)
);

CREATE TABLE IF NOT EXISTS sessions (
    token TEXT PRIMARY KEY,
    user_id INTEGER,
    csrf_token TEXT NOT NULL,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);
SQL;

const FILES_SCHEMA = <<<SQL
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
PRAGMA synchronous = NORMAL;

CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hash TEXT NOT NULL,
    data BLOB NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    username TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_files_hash ON files(hash);
SQL;

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(SCHEMA);

$filesDb = new PDO('sqlite:' . FILES_DB_PATH);
$filesDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$filesDb->exec(FILES_SCHEMA);

function session_cookie(string $token = ''): void
{
  setcookie('session', $token, [
    'expires' => $token !== '' ? time() + 86400 * 30 : time() - 42000,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
  ]);
}

function session_create(?int $userId = null): array
{
  global $db;
  $token = bin2hex(random_bytes(32));
  $csrf = bin2hex(random_bytes(32));
  $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
  $expires = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d\TH:i:s\Z');
  $db->prepare('INSERT INTO sessions (token, user_id, csrf_token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([$token, $userId, $csrf, $now, $expires]);
  session_cookie($token);
  return ['token' => $token, 'user_id' => $userId, 'csrf_token' => $csrf];
}

function session_load(): ?array
{
  global $db;
  $token = $_COOKIE['session'] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
  $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
  $stmt = $db->prepare('SELECT token, user_id, csrf_token FROM sessions WHERE token = ? AND expires_at > ?');
  $stmt->execute([$token, $now]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function session_delete(string $token): void
{
  global $db;
  $db->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
  session_cookie();
}

function session_regenerate(string $oldToken, int $userId): array
{
  session_delete($oldToken);
  return session_create($userId);
}

if (random_int(1, 1000) === 1) {
  $db->prepare('DELETE FROM sessions WHERE expires_at <= ?')
    ->execute([(new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')]);
}
$session = session_load() ?? session_create();
$csrfToken = $session['csrf_token'];

$currentUser = null;
if ($session['user_id'] !== null) {
  $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
  $stmt->execute([$session['user_id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $currentUser = (object)$row;
  } else {
    session_delete($session['token']);
    $session = session_create();
    $csrfToken = $session['csrf_token'];
  }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';
$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format(DATE_FORMAT);

function isPronounceable(string $name): bool
{
  $len = strlen($name);
  // Count vowels (y counts as vowel except at word start)
  $vc = preg_match_all('/[aeiouy]/', $name);
  if ($name[0] === 'y') $vc = max(0, $vc - 1);
  if ($vc === 0 || $vc / $len >= 0.8) return false;
  // For structural analysis, treat non-initial y as vowel
  $w = $name[0] . str_replace('y', 'a', substr($name, 1));
  if (preg_match('/[aeiou]{3}/', $w)) return false;
  if (preg_match('/(.)\1{2}/', $name)) return false;
  // Whitelist of allowed consonant pairs and triples
  $ok2 = array_flip([
    'bl', 'br', 'ch', 'cl', 'cr', 'dr', 'fl', 'fr', 'gl', 'gr', 'kn', 'ph', 'pl', 'pr',
    'sc', 'sh', 'sk', 'sl', 'sm', 'sn', 'sp', 'st', 'sw', 'th', 'tr', 'tw', 'wh', 'wr',
    'ck', 'ct', 'ft', 'ld', 'lf', 'lk', 'll', 'lm', 'ln', 'lp', 'ls', 'lt', 'lv',
    'mb', 'mp', 'nc', 'nd', 'ng', 'nk', 'nn', 'ns', 'nt', 'nz',
    'rb', 'rc', 'rd', 'rf', 'rg', 'rk', 'rl', 'rm', 'rn', 'rp', 'rs', 'rt', 'rv',
    'ff', 'ss', 'tt', 'dd', 'bb', 'gg', 'mm', 'pp', 'rr', 'zz',
    'dg', 'dm', 'gn', 'gm', 'mn', 'ks', 'ms', 'ts', 'ds', 'gs', 'bs', 'ws',
    'hn', 'hr', 'ht', 'kr', 'lz', 'nf', 'rz', 'xt', 'pt', 'ps',
  ]);
  $ok3 = array_flip([
    'ndr', 'ntr', 'ngl', 'nch', 'nst', 'ngs', 'nks', 'nts',
    'str', 'sch', 'scr', 'shr', 'spl', 'spr',
    'chr', 'thr', 'ght', 'mph', 'mpl',
    'rch', 'rst', 'rth', 'rld', 'rds', 'rks', 'rms', 'rns', 'rts',
    'lth', 'lds', 'lts',
  ]);
  preg_match_all('/[bcdfghjklmnpqrstvwxyz]+/', $w, $clusters);
  foreach ($clusters[0] as $cl) {
    $clen = strlen($cl);
    if ($clen > 3) return false;
    if ($clen === 3 && !isset($ok3[$cl])) return false;
    if ($clen === 2 && !isset($ok2[$cl])) return false;
  }
  return true;
}

// Logout
if (isset($_GET['logout'])) {
  session_delete($session['token']);
  header('Location: ' . (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
  exit;
}

// IP ban check
$ipBanned = $db->prepare('SELECT 1 FROM ip_bans WHERE ip = ?');
$ipBanned->execute([$ip]);
$isIpBanned = (bool)$ipBanned->fetch();

// Login / Register
$authError = '';
if ($isIpBanned) {
  $authError = 'You are permanently banned.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
  $authError = 'Invalid request. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'], $_POST['action'])) {
  $username = strtolower(trim($_POST['username']));
  $password = $_POST['password'];

  if ($username === '' || $password === '') {
    $authError = 'Username and password are required.';
  } elseif ($_POST['action'] === 'register' && (strlen($username) < 5 || strlen($username) > 9 || !preg_match('/^[a-z]+$/', $username))) {
    $authError = 'Username must be 5-9 chars: lowercase letters only, no numbers.';
  } elseif ($_POST['action'] === 'register' && !isPronounceable($username)) {
    $authError = 'Username must be a pronounceable word (no gibberish).';
  } elseif ($_POST['action'] === 'register') {
    $existing = $db->prepare('SELECT id FROM users WHERE username = ?');
    $existing->execute([$username]);
    $banned = file_exists(__DIR__ . '/banned_names.txt') ? array_filter(array_map('trim', file(__DIR__ . '/banned_names.txt'))) : [];
    $nameBanned = false;
    foreach ($banned as $part) {
      if ($part !== '' && str_contains($username, $part)) {
        $nameBanned = true;
        break;
      }
    }
    if ($existing->fetch() || $nameBanned) {
      $authError = 'Username already taken.';
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $db->prepare('INSERT INTO users (username, password_hash, created_at, last_seen, last_ip) VALUES (?, ?, ?, ?, ?)');
      $stmt->execute([$username, $hash, $now, $now, $ip]);
      $id = (int)$db->lastInsertId();
      if ($id === 1) {
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['admin', $id]);
      }
      $session = session_regenerate($session['token'], $id);
      $csrfToken = $session['csrf_token'];
      header('Location: ' . (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
      exit;
    }
  } elseif ($_POST['action'] === 'login') {
    $stmt = $db->prepare('SELECT id, password_hash, kicked_until, banned FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) {
      $authError = 'Invalid username or password.';
    } elseif ($user['banned']) {
      $authError = 'You are permanently banned.';
    } elseif ($user['kicked_until'] && $user['kicked_until'] > $now) {
      $authError = "You are kicked until {$user['kicked_until']}.";
    } else {
      $session = session_regenerate($session['token'], (int)$user['id']);
      $csrfToken = $session['csrf_token'];
      header('Location: ' . (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
      exit;
    }
  }
}

// Helpers
function jsonResponse(array $data, int $code = 200): never
{
  http_response_code($code);
  echo json_encode($data);
  exit;
}

function systemMessage(string $msg): void
{
  global $db, $currentUser, $now, $ip;
  $stmt = $db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
  $stmt->execute([$currentUser->username, $msg, $now, $ip, 'system']);
}

function checkCooldown(string $action, string $label, int $seconds = 1800): void
{
  global $db, $currentUser;
  $cutoff = now()->modify("-{$seconds} seconds")->format(DATE_FORMAT);
  $stmt = $db->prepare('SELECT used_at FROM cooldowns WHERE user_id = ? AND action = ? AND used_at > ?');
  $stmt->execute([$currentUser->id, $action, $cutoff]);
  if ($stmt->fetch()) {
    $mins = intdiv($seconds, 60);
    $unit = $seconds >= 3600 ? intdiv($seconds, 3600) . 'h' : $mins . 'm';
    jsonResponse(['error' => "You can only {$label} once per {$unit}."]);
  }
}

function recordCooldown(string $action): void
{
  global $db, $currentUser, $now;
  $db->prepare('INSERT INTO cooldowns (user_id, action, used_at) VALUES (?, ?, ?) ON CONFLICT(user_id, action) DO UPDATE SET used_at = ?')
    ->execute([$currentUser->id, $action, $now, $now]);
}

function findUser(string $username): ?array
{
  global $db;
  $stmt = $db->prepare('SELECT id, username, role, kicked_until, banned, rank, last_ip FROM users WHERE username = ?');
  $stmt->execute([$username]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function parseDuration(string $s): ?int
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

function now(): \DateTime
{
  return new \DateTime('now', new \DateTimeZone('UTC'));
}

function requireRole(string ...$allowed): void
{
  global $currentUser;
  if (!in_array($currentUser->role, $allowed, true)) {
    jsonResponse(['error' => 'Permission denied']);
  }
}

function requireTarget(string $username): array
{
  $target = findUser($username);
  if (!$target) jsonResponse(['error' => 'User not found']);
  return $target;
}

function validateColor(string $value): void
{
  if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
    jsonResponse(['error' => 'Color must be a 6-digit hex value like #AF32A2.']);
  }

  $hex = substr($value, 1);
  $r = hexdec(substr($hex, 0, 2));
  $g = hexdec(substr($hex, 2, 2));
  $b = hexdec(substr($hex, 4, 2));
  // relative luminance (sRGB)
  $lum = 0.2126 * $r / 255 + 0.7152 * $g / 255 + 0.0722 * $b / 255;
  if ($lum < 0.1) {
    jsonResponse(['error' => 'That color is too dark — it won\'t be readable.']);
  }
  if ($lum > 0.9) {
    jsonResponse(['error' => 'That color is too bright — it won\'t be readable.']);
  }
}

function loadAllowedWords(): array
{
  static $words = null;
  if ($words === null) {
    $lines = file(__DIR__ . '/words.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $words = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '' && $line[0] !== '#') {
        $words[$line] = true;
      }
    }
  }
  return $words;
}

function isAllowedWord(string $word, array $allowedWords): bool
{
  if (strlen($word) <= 1) return $word === 'i' || $word === 'a';
  if (isset($allowedWords[$word])) return true;

  // Try removing common suffixes and checking the stem
  $suffixes = ['ness', 'ment', 'able', 'ible', 'less', 'ful', 'ing', 'est', 'er', 'ed', 'ly', 'es', 's'];
  foreach ($suffixes as $suffix) {
    $len = strlen($suffix);
    if (strlen($word) > $len + 2 && str_ends_with($word, $suffix)) {
      $stem = substr($word, 0, -$len);
      if (isset($allowedWords[$stem])) return true;
      // Try adding back 'e' (e.g., making -> make, loving -> love)
      if (isset($allowedWords[$stem . 'e'])) return true;
      // Try removing doubled consonant (e.g., running -> run, stopped -> stop)
      if (strlen($stem) >= 2 && $stem[-1] === $stem[-2]) {
        if (isset($allowedWords[substr($stem, 0, -1)])) return true;
      }
    }
  }

  // Handle -ied -> -y (tried -> try)
  if (str_ends_with($word, 'ied') && strlen($word) > 4) {
    $stem = substr($word, 0, -3) . 'y';
    if (isset($allowedWords[$stem])) return true;
  }

  // Handle -ies -> -y (tries -> try)
  if (str_ends_with($word, 'ies') && strlen($word) > 4) {
    $stem = substr($word, 0, -3) . 'y';
    if (isset($allowedWords[$stem])) return true;
  }

  return false;
}

function loadBadWords(): array
{
  static $words = null;
  if ($words === null) {
    $path = __DIR__ . '/bad_words.txt';
    $words = [];
    if (file_exists($path)) {
      $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
          $words[$line] = true;
        }
      }
    }
  }
  return $words;
}

function isBadWord(string $word, array $badWords): bool
{
  return isset($badWords[$word]);
}

// Commands: name => [pattern, roles[], usage, handler]
$commands = [
  'help' => [
    'pattern' => '#^/help$#',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/help',
    'handler' => function () use ($currentUser) {
      $rank = $currentUser->rank;
      jsonResponse(['error' => <<<HELP
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
    'handler' => function ($count, $sides) use ($currentUser) {
      if ($currentUser->rank < 3) {
        jsonResponse(['error' => 'You need rank 3+ to roll dice.']);
      }
      $count = (int)$count;
      $sides = (int)$sides;
      if ($count < 1 || $count > 10) jsonResponse(['error' => 'Roll 1-10 dice.']);
      if ($sides < 2 || $sides > 100) jsonResponse(['error' => 'Dice must have 2-100 sides.']);
      $rolls = [];
      for ($i = 0; $i < $count; $i++) $rolls[] = random_int(1, $sides);
      $total = array_sum($rolls);
      $detail = implode(' + ', $rolls);
      $name = $currentUser->username;
      $msg = $count > 1 ? "{$name} rolled {$count}d{$sides}: {$detail} = {$total}" : "{$name} rolled d{$sides}: {$total}";
      systemMessage($msg);
    },
  ],
  'info' => [
    'pattern' => '#^/info ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/info <username>',
    'handler' => function ($username) use ($db) {
      $stmt = $db->prepare('SELECT rank, created_at FROM users WHERE username = ?');
      $stmt->execute([$username]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$user) jsonResponse(['error' => 'User not found']);
      $created = new \DateTime($user['created_at'], new \DateTimeZone('UTC'));
      $diff = (new \DateTime('now', new \DateTimeZone('UTC')))->diff($created);
      if ($diff->days === 0) $ago = 'today';
      elseif ($diff->days === 1) $ago = '1 day ago';
      else $ago = "{$diff->days} days ago";
      jsonResponse(['error' => "{$username}: rank {$user['rank']}, joined {$ago}"]);
    },
  ],
  'mod' => [
    'pattern' => '#^/mod (add|del) ([a-z0-9]+)$#',
    'roles' => ['admin'],
    'usage' => '/mod add|del <username>',
    'handler' => function ($action, $username) use ($db) {
      $target = requireTarget($username);
      if ($target['role'] === 'admin') {
        jsonResponse(['error' => 'Cannot change admin role']);
      }
      $newRole = $action === 'add' ? 'moderator' : 'user';
      $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $target['id']]);
      $msg = $action === 'add' ? "{$username} is now a moderator" : "{$username} is no longer a moderator";
      systemMessage($msg);
    },
  ],
  'ban' => [
    'pattern' => '#^/ban ([a-z0-9,\s]+)$#',
    'roles' => ['admin', 'moderator'],
    'usage' => '/ban <username>[, username2, ...]',
    'handler' => function ($input) use ($db, $currentUser) {
      $usernames = array_unique(array_filter(array_map('trim', explode(',', $input))));
      if (empty($usernames)) jsonResponse(['error' => 'No usernames provided']);
      foreach ($usernames as $username) {
        if (!preg_match('/^[a-z0-9]+$/', $username)) jsonResponse(['error' => "Invalid username: {$username}"]);
      }
      $banned = [];
      foreach ($usernames as $username) {
        $target = requireTarget($username);
        if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $currentUser->role !== 'admin')) {
          if (count($usernames) === 1) jsonResponse(['error' => 'Cannot ban this user']);
          continue;
        }
        $db->prepare('UPDATE users SET banned = 1, rank = 0 WHERE id = ?')->execute([$target['id']]);
        $banned[] = $username;
      }
      if (empty($banned)) jsonResponse(['error' => 'No users could be banned']);
      $list = implode(', ', $banned);
      systemMessage("{$list} banned by {$currentUser->username}");
    },
  ],
  'unban' => [
    'pattern' => '#^/unban ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator'],
    'usage' => '/unban <username>',
    'handler' => function ($username) use ($db, $currentUser) {
      $target = requireTarget($username);
      $db->prepare('UPDATE users SET banned = 0 WHERE id = ?')->execute([$target['id']]);
      systemMessage("{$username} was unbanned by {$currentUser->username}");
    },
  ],
  'shadowban' => [
    'pattern' => '#^/shadowban ([a-z0-9]+)$#',
    'roles' => ['admin'],
    'usage' => '/shadowban <username>',
    'handler' => function ($username) use ($db, $currentUser, $now) {
      $target = requireTarget($username);
      if ($target['role'] === 'admin') {
        jsonResponse(['error' => 'Cannot shadow ban an admin']);
      }
      $targetIp = $target['last_ip'] ?: '0.0.0.0';
      $db->prepare('INSERT INTO shadow_bans (username, ip, banned_by, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$username, $targetIp, $currentUser->username, $now]);
      jsonResponse(['error' => "{$username} is now shadow banned ({$targetIp})"]);
    },
  ],
  'unshadowban' => [
    'pattern' => '#^/unshadowban ([a-z0-9]+)$#',
    'roles' => ['admin'],
    'usage' => '/unshadowban <username>',
    'handler' => function ($username) use ($db, $currentUser) {
      requireTarget($username);
      $db->prepare('DELETE FROM shadow_bans WHERE username = ?')->execute([$username]);
      jsonResponse(['error' => "{$username} is no longer shadow banned"]);
    },
  ],
  'del' => [
    'pattern' => '#^/del ((?:\d+ ?)+)$#',
    'roles' => ['admin', 'moderator'],
    'usage' => '/del <id> [<id> ...]',
    'handler' => function ($arg) use ($db, $currentUser, $now, $ip) {
      $ids = array_unique(array_map('intval', preg_split('/\s+/', trim($arg))));
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $stmt = $db->prepare("SELECT id FROM messages WHERE id IN ({$placeholders}) AND kind = 'text'");
      $stmt->execute($ids);
      $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
      if (!$ids) jsonResponse(['error' => 'No messages found']);
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $db->prepare("DELETE FROM messages WHERE id IN ({$placeholders}) AND kind = 'text'")->execute($ids);
      $stmt = $db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
      $stmt->execute([$currentUser->username, json_encode($ids), $now, $ip, 'delete']);
      $count = count($ids);
      systemMessage("{$currentUser->username} deleted {$count} message(s)");
    },
  ],
  'delall' => [
    'pattern' => '#^/delall ([a-z0-9]+)$#',
    'roles' => ['admin'],
    'usage' => '/delall <username>',
    'handler' => function ($username) use ($db, $currentUser, $now, $ip) {
      $target = requireTarget($username);
      $stmt = $db->prepare("SELECT id FROM messages WHERE username = ? AND kind = 'text'");
      $stmt->execute([$username]);
      $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
      if (!$ids) jsonResponse(['error' => 'No messages found']);
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $db->prepare("DELETE FROM messages WHERE id IN ({$placeholders}) AND kind = 'text'")->execute($ids);
      $stmt = $db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
      $stmt->execute([$currentUser->username, json_encode($ids), $now, $ip, 'delete']);
      $count = count($ids);
      systemMessage("{$currentUser->username} deleted all {$count} message(s) from {$username}");
    },
  ],
  'kick' => [
    'pattern' => '#^/kick ([a-z0-9]+) (\d+[smhd])$#',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/kick <username> <duration> (e.g. 5m, 1h, 2d)',
    'handler' => function ($username, $duration) use ($db, $currentUser) {
      $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
      if (!$isMod && $currentUser->rank < 8) {
        jsonResponse(['error' => 'You need rank 8+ or moderator role to kick.']);
      }
      if (!$isMod) checkCooldown('kick', 'kick/unkick');
      $target = requireTarget($username);
      if ($target['role'] === 'admin') {
        jsonResponse(['error' => 'Cannot kick admin.']);
      }
      if ($target['role'] === 'moderator' && $currentUser->role !== 'admin') {
        jsonResponse(['error' => 'Only admin can kick moderators.']);
      }
      if (!$isMod && $target['rank'] >= $currentUser->rank) {
        jsonResponse(['error' => 'You can only kick lower-ranked users.']);
      }
      $seconds = parseDuration($duration);
      if (!$seconds || $seconds > 86400 * 30) jsonResponse(['error' => 'Invalid duration.']);
      if (!$isMod && $seconds > 600) jsonResponse(['error' => 'Rank 8 can only kick for up to 10m.']);
      $until = now()->modify("+{$seconds} seconds")->format(DATE_FORMAT);
      $db->prepare('UPDATE users SET kicked_until = ? WHERE id = ?')->execute([$until, $target['id']]);
      $msg = "{$username} was kicked for {$duration} by {$currentUser->username}";
      if (!$isMod) recordCooldown('kick');
      systemMessage($msg);
    },
  ],
  'unkick' => [
    'pattern' => '#^/unkick ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/unkick <username>',
    'handler' => function ($username) use ($db, $currentUser) {
      $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
      if (!$isMod && $currentUser->rank < 8) {
        jsonResponse(['error' => 'You need rank 8+ or moderator role to unkick.']);
      }
      if (!$isMod) checkCooldown('kick', 'kick/unkick');
      $target = requireTarget($username);
      $db->prepare('UPDATE users SET kicked_until = NULL WHERE id = ?')->execute([$target['id']]);
      if (!$isMod) recordCooldown('kick');
      systemMessage("{$username} was unkicked by {$currentUser->username}");
    },
  ],
  'ip' => [
    'pattern' => '#^/ip ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator'],
    'usage' => '/ip <username>',
    'handler' => function ($username) use ($db, $currentUser) {
      $target = requireTarget($username);
      if ($target['role'] === 'admin' && $currentUser->role !== 'admin') {
        jsonResponse(['error' => 'Cannot view admin IP']);
      }
      $stmt = $db->prepare('SELECT last_ip FROM users WHERE username = ?');
      $stmt->execute([$username]);
      $userIp = $stmt->fetchColumn();
      // Find all IPs this user has used in messages
      $stmt = $db->prepare('SELECT DISTINCT ip FROM messages WHERE username = ?');
      $stmt->execute([$username]);
      $allIps = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'ip');
      if ($userIp && !in_array($userIp, $allIps, true)) {
        array_unshift($allIps, $userIp);
      }
      if (!$allIps) jsonResponse(['error' => "{$username}: no IP data"]);
      // Find other usernames sharing any of these IPs
      $placeholders = implode(',', array_fill(0, count($allIps), '?'));
      $stmt = $db->prepare("SELECT DISTINCT username FROM users WHERE last_ip IN ({$placeholders}) AND username != ?");
      $stmt->execute([...$allIps, $username]);
      $sharedUsers = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'username');
      $stmt = $db->prepare("SELECT DISTINCT username FROM messages WHERE ip IN ({$placeholders}) AND username != ?");
      $stmt->execute([...$allIps, $username]);
      $sharedMsgUsers = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'username');
      $alts = array_unique(array_merge($sharedUsers, $sharedMsgUsers));
      sort($alts);
      $info = "{$username}: " . implode(', ', $allIps);
      if ($alts) $info .= "\nAlso used by: " . implode(', ', $alts);
      jsonResponse(['error' => $info]);
    },
  ],
  'rank' => [
    'pattern' => '#^/rank ([a-z0-9]+) ([0-9])$#',
    'roles' => ['admin'],
    'usage' => '/rank <username> <0-9>',
    'handler' => function ($username, $rank) use ($db) {
      $target = requireTarget($username);
      $rank = (int)$rank;
      $db->prepare('UPDATE users SET rank = ? WHERE id = ?')->execute([$rank, $target['id']]);
      systemMessage("{$username} is now rank {$rank}");
    },
  ],
  'promotions' => [
    'pattern' => '#^/promotions ([a-z0-9]+)$#',
    'roles' => ['admin'],
    'usage' => '/promotions <username>',
    'handler' => function ($username) use ($db) {
      requireTarget($username);
      $stmt = $db->prepare("SELECT message FROM messages WHERE kind = 'system' AND message LIKE '% promoted to rank % by ' || ?");
      $stmt->execute([$username]);
      $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
      if (!$rows) jsonResponse(['error' => "No promotions by {$username}"]);
      $names = [];
      foreach ($rows as $msg) {
        if (preg_match('/^(\S+) was promoted to rank/', $msg, $m)) {
          $names[$m[1]] = true;
        }
      }
      $list = implode(', ', array_keys($names));
      jsonResponse(['error' => "Users promoted by {$username}: {$list}"]);
    },
  ],
  'promotedby' => [
    'pattern' => '#^/promotedby ([a-z0-9]+)$#',
    'roles' => ['admin'],
    'usage' => '/promotedby <username>',
    'handler' => function ($username) use ($db) {
      requireTarget($username);
      $stmt = $db->prepare("SELECT message FROM messages WHERE kind = 'system' AND message LIKE ? || ' was promoted to rank % by %'");
      $stmt->execute([$username]);
      $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
      if (!$rows) jsonResponse(['error' => "No promotion history for {$username}"]);
      $promoters = [];
      foreach ($rows as $msg) {
        if (preg_match('/was promoted to rank (\d+) by (\S+)$/', $msg, $m)) {
          $promoters[] = "{$m[2]} (to rank {$m[1]})";
        }
      }
      $list = implode(', ', $promoters);
      jsonResponse(['error' => "Users who promoted {$username}: {$list}"]);
    },
  ],
  'ipban' => [
    'pattern' => '#^/ipban ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator'],
    'usage' => '/ipban <username>',
    'handler' => function ($username) use ($db, $currentUser, $now) {
      $target = requireTarget($username);
      if ($target['role'] === 'admin') {
        jsonResponse(['error' => 'Cannot IP ban admin']);
      }
      $ips = $db->prepare('SELECT DISTINCT ip FROM messages WHERE username = ? UNION SELECT last_ip FROM users WHERE username = ?');
      $ips->execute([$username, $username]);
      $allIps = $ips->fetchAll(PDO::FETCH_COLUMN);
      if (!$allIps) jsonResponse(['error' => 'No IPs found for this user.']);
      $insert = $db->prepare('INSERT OR IGNORE INTO ip_bans (ip, username, banned_by, created_at) VALUES (?, ?, ?, ?)');
      foreach ($allIps as $ip) {
        $insert->execute([$ip, $username, $currentUser->username, $now]);
      }
      $count = count($allIps);
      systemMessage("{$username} was IP banned by {$currentUser->username} ({$count} IPs)");
    },
  ],
  'ipban-list' => [
    'pattern' => '#^/ipban-list$#',
    'roles' => ['admin', 'moderator'],
    'usage' => '/ipban-list',
    'handler' => function () use ($db) {
      $rows = $db->query('SELECT username, ip, banned_by, created_at FROM ip_bans ORDER BY username, created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) jsonResponse(['error' => 'No IP bans.']);
      $lines = array_map(fn($r) => "{$r['username']}: {$r['ip']} (by {$r['banned_by']}, {$r['created_at']})", $rows);
      jsonResponse(['error' => "IP bans:\n" . implode("\n", $lines)]);
    },
  ],
  'ipunban' => [
    'pattern' => '#^/ipunban ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator'],
    'usage' => '/ipunban <username>',
    'handler' => function ($username) use ($db, $currentUser) {
      requireTarget($username);
      $db->prepare('DELETE FROM ip_bans WHERE username = ?')->execute([$username]);
      systemMessage("{$username} was IP unbanned by {$currentUser->username}");
    },
  ],
  'ipban-unban-all' => [
    'pattern' => '#^/ipban-unban-all$#',
    'roles' => ['admin'],
    'usage' => '/ipban-unban-all',
    'handler' => function () use ($db, $currentUser) {
      $count = $db->query('SELECT COUNT(*) FROM ip_bans')->fetchColumn();
      if (!$count) jsonResponse(['error' => 'No IP bans to clear.']);
      $db->exec('DELETE FROM ip_bans');
      // No system message.
    },
  ],
  'color' => [
    'pattern' => '/^\/color\s+(reset|\#[0-9a-fA-F]{6})$/',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/color <#RRGGBB|reset>',
    'handler' => function ($value) use ($db, $currentUser) {
      if ($currentUser->rank < 4) {
        jsonResponse(['error' => 'You need rank 4+ to use colored names.']);
      }
      if ($value === 'reset') {
        $db->prepare('UPDATE users SET color = NULL WHERE id = ?')->execute([$currentUser->id]);
        jsonResponse(['error' => 'Name color reset.']);
      }
      validateColor($value);
      $db->prepare('UPDATE users SET color = ? WHERE id = ?')->execute([$value, $currentUser->id]);
      jsonResponse(['error' => "Name color set to {$value}."]);
    },
  ],
  'setcolor' => [
    'pattern' => '/^\/setcolor\s+([a-z0-9]+)\s+(reset|\#[0-9a-fA-F]{6})$/',
    'roles' => ['admin', 'moderator'],
    'usage' => '/setcolor <username> <#RRGGBB|reset>',
    'handler' => function ($username, $value) use ($db, $currentUser) {
      $target = requireTarget($username);
      if ($target['role'] === 'admin' && $currentUser->role !== 'admin') {
        jsonResponse(['error' => 'Only admin can change admin colors.']);
      }
      if ($value === 'reset') {
        $db->prepare('UPDATE users SET color = NULL WHERE id = ?')->execute([$target['id']]);
        systemMessage("{$currentUser->username} reset {$username}'s color");
        jsonResponse([]);
      }
      validateColor($value);
      $db->prepare('UPDATE users SET color = ? WHERE id = ?')->execute([$value, $target['id']]);
      systemMessage("{$currentUser->username} set {$username}'s color to {$value}");
    },
  ],
  'promote' => [
    'pattern' => '#^/promote ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/promote <username>',
    'handler' => function ($username) use ($db, $currentUser) {
      if ($currentUser->rank < 1) {
        jsonResponse(['error' => 'You need at least rank 1 to promote.']);
      }
      $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
      if (!$isMod) checkCooldown('promote', 'promote', 3600);
      $target = requireTarget($username);
      if ($target['username'] === $currentUser->username) {
        jsonResponse(['error' => 'You cannot promote yourself.']);
      }
      $newRank = $target['rank'] + 1;
      $maxRank = $currentUser->rank - 1;
      if ($newRank > $maxRank) {
        jsonResponse(['error' => "You can only promote up to rank {$maxRank}."]);
      }
      $db->prepare('UPDATE users SET rank = ? WHERE id = ?')->execute([$newRank, $target['id']]);
      if (!$isMod) recordCooldown('promote');
      systemMessage("{$username} was promoted to rank {$newRank} by {$currentUser->username}");
    },
  ],
  'mute' => [
    'pattern' => '#^/mute ([a-z0-9]+) (\d+[smhd])$#',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/mute <username> <duration> (e.g. 30s, 5m, 1h)',
    'handler' => function ($username, $duration) use ($db, $currentUser) {
      $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
      if (!$isMod && $currentUser->rank < 7) {
        jsonResponse(['error' => 'You need rank 7+ to mute.']);
      }
      if (!$isMod) checkCooldown('mute', 'mute/unmute');
      $target = requireTarget($username);
      if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $currentUser->role !== 'admin')) {
        jsonResponse(['error' => 'Cannot mute this user.']);
      }
      if (!$isMod && $target['rank'] >= $currentUser->rank) {
        jsonResponse(['error' => 'You can only mute lower-ranked users.']);
      }
      $seconds = parseDuration($duration);
      if (!$seconds || $seconds > 86400 * 30) jsonResponse(['error' => 'Invalid duration.']);
      if (!$isMod && $seconds > 300) jsonResponse(['error' => 'Max mute duration is 5m.']);
      $until = now()->modify("+{$seconds} seconds")->format(DATE_FORMAT);
      $db->prepare('UPDATE users SET muted_until = ? WHERE id = ?')->execute([$until, $target['id']]);
      if (!$isMod) recordCooldown('mute');
      systemMessage("{$username} was muted for {$duration} by {$currentUser->username}");
    },
  ],
  'unmute' => [
    'pattern' => '#^/unmute ([a-z0-9]+)$#',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/unmute <username>',
    'handler' => function ($username) use ($db, $currentUser) {
      $isMod = in_array($currentUser->role, ['admin', 'moderator'], true);
      if (!$isMod && $currentUser->rank < 7) {
        jsonResponse(['error' => 'You need rank 7+ to unmute.']);
      }
      if (!$isMod) checkCooldown('mute', 'mute/unmute');
      $target = requireTarget($username);
      $db->prepare('UPDATE users SET muted_until = NULL WHERE id = ?')->execute([$target['id']]);
      if (!$isMod) recordCooldown('mute');
      systemMessage("{$username} was unmuted by {$currentUser->username}");
    },
  ],
  'status' => [
    'pattern' => '/^\/status\s+(.+)$/',
    'roles' => ['admin', 'moderator', 'user'],
    'usage' => '/status <word|reset>',
    'handler' => function ($value) use ($db, $currentUser) {
      if ($currentUser->rank < 3) {
        jsonResponse(['error' => 'You need rank 3+ to set a status.']);
      }
      if ($value === 'reset') {
        $db->prepare('UPDATE users SET status = NULL WHERE id = ?')->execute([$currentUser->id]);
        jsonResponse(['error' => 'Status cleared.']);
      }
      if (!preg_match('/^[a-z]+$/', $value)) {
        jsonResponse(['error' => 'Status must be a single lowercase word (a-z).']);
      }
      if (strlen($value) > 10) {
        jsonResponse(['error' => 'Status must be 10 characters or less.']);
      }
      $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$value, $currentUser->id]);
      jsonResponse(['error' => "Status set to: {$value}"]);
    },
  ],
  'setstatus' => [
    'pattern' => '/^\/setstatus\s+([a-z0-9]+)\s+(.+)$/',
    'roles' => ['admin', 'moderator'],
    'usage' => '/setstatus <username> <word|reset>',
    'handler' => function ($username, $value) use ($db, $currentUser) {
      $target = requireTarget($username);
      if ($target['role'] === 'admin' && $currentUser->role !== 'admin') {
        jsonResponse(['error' => 'Only admin can change admin status.']);
      }
      if ($value === 'reset') {
        $db->prepare('UPDATE users SET status = NULL WHERE id = ?')->execute([$target['id']]);
        systemMessage("{$currentUser->username} reset {$username}'s status");
        jsonResponse([]);
      }
      if (!preg_match('/^[a-z]+$/', $value)) {
        jsonResponse(['error' => 'Status must be a single lowercase word (a-z).']);
      }
      if (strlen($value) > 10) {
        jsonResponse(['error' => 'Status must be 10 characters or less.']);
      }
      $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$value, $target['id']]);
      systemMessage("{$currentUser->username} set {$username}'s status to {$value}");
    },
  ],
  'sys' => [
    'pattern' => '/^\/sys\s+(.+)$/',
    'roles' => ['admin'],
    'usage' => '/sys <message>',
    'handler' => function ($msg) use ($currentUser) {
      if ($currentUser->rank < 9) {
        jsonResponse(['error' => 'You need rank 9 to send system messages.']);
      }
      systemMessage($msg);
    },
  ],
  'demote' => [
    'pattern' => '#^/demote ([0-9]) ([a-z0-9,\s]+)$#',
    'roles' => ['admin'],
    'usage' => '/demote <0-9> <username>[, username2, ...]',
    'handler' => function ($rank, $input) use ($db, $currentUser) {
      $rank = (int)$rank;
      $usernames = array_unique(array_filter(array_map('trim', explode(',', $input))));
      if (empty($usernames)) jsonResponse(['error' => 'No usernames provided']);
      foreach ($usernames as $username) {
        if (!preg_match('/^[a-z0-9]+$/', $username)) jsonResponse(['error' => "Invalid username: {$username}"]);
      }
      $demoted = [];
      foreach ($usernames as $username) {
        $target = requireTarget($username);
        if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $currentUser->role !== 'admin')) {
          if (count($usernames) === 1) jsonResponse(['error' => 'Cannot demote this user']);
          continue;
        }
        if ($target['rank'] <= $rank) {
          if (count($usernames) === 1) jsonResponse(['error' => "{$username} is already rank {$target['rank']}"]);
          continue;
        }
        $db->prepare('UPDATE users SET rank = ? WHERE id = ?')->execute([$rank, $target['id']]);
        $demoted[] = $username;
      }
      if (empty($demoted)) jsonResponse(['error' => 'No users were demoted']);
      $list = implode(', ', $demoted);
      systemMessage("{$list} demoted to rank {$rank} by {$currentUser->username}");
    },
  ],
  'demoteall' => [
    'pattern' => '#^/demoteall ([0-9])$#',
    'roles' => ['admin'],
    'usage' => '/demoteall <max-rank>',
    'handler' => function ($max) use ($db, $currentUser) {
      $max = (int)$max;
      $stmt = $db->prepare('UPDATE users SET rank = ? WHERE rank > ? AND role != ?');
      $stmt->execute([$max, $max, 'admin']);
      $affected = $stmt->rowCount();
      systemMessage("{$currentUser->username} demoted everyone above rank {$max} back to {$max} ({$affected} users affected)");
    },
  ],
  'world' => [
    'pattern' => '#^/world (shake|flip|blur|disco|matrix|confetti|flash|wave|reload|blackout|rain)$#',
    'roles' => ['admin'],
    'usage' => '/world <shake|flip|blur|disco|matrix|confetti|flash|wave|reload|blackout|rain>',
    'handler' => function ($effect) use ($db, $currentUser) {
      global $now, $ip;
      $stmt = $db->prepare('INSERT INTO messages (username, message, created_at, ip, kind) VALUES (?, ?, ?, ?, ?)');
      $stmt->execute([$currentUser->username, $effect, $now, $ip, 'effect']);
    },
  ],
];

// File serving
if (isset($currentUser) && isset($_GET['file'])) {
  $hash = $_GET['file'];
  $stmt = $filesDb->prepare('SELECT data, mime FROM files WHERE hash = ?');
  $stmt->execute([$hash]);
  $file = $stmt->fetch(PDO::FETCH_ASSOC);
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

// API
if (isset($_GET['api'])) {
  header('Content-Type: application/json');

  if (!isset($currentUser)) {
    // Not logged in.
    jsonResponse(['kicked' => true]);
  }

  if ($currentUser->banned || $isIpBanned) {
    session_delete($session['token']);
    jsonResponse(['kicked' => true, 'error' => 'You are permanently banned.']);
  }

  if ($currentUser->kicked_until && $currentUser->kicked_until > $now) {
    session_delete($session['token']);
    jsonResponse(['kicked' => true, 'error' => "You are kicked until {$currentUser->kicked_until}."]);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
  }

  // Build sets of shadow-banned usernames and IPs for message filtering
  $sbRows = $db->query('SELECT DISTINCT username, ip FROM shadow_bans')->fetchAll(PDO::FETCH_ASSOC);
  $sbUsernames = array_unique(array_column($sbRows, 'username'));
  $sbIps = array_unique(array_column($sbRows, 'ip'));
  $isShadowBanned = function (string $msgUsername, string $msgIp) use ($sbUsernames, $sbIps): bool {
    return in_array($msgUsername, $sbUsernames, true) || in_array($msgIp, $sbIps, true);
  };
  $filterShadowBanned = function (array $messages) use ($currentUser, $isShadowBanned, $ip): array {
    $isAdmin = $currentUser->role === 'admin';
    $result = [];
    foreach ($messages as $msg) {
      if ($msg['kind'] !== 'text') {
        $result[] = $msg;
        continue;
      }
      $msgIp = $msg['ip'] ?? '';
      $banned = $isShadowBanned($msg['username'], $msgIp);
      if ($isAdmin) {
        if ($banned) $msg['shadow_banned'] = true;
        $result[] = $msg;
      } elseif ($banned) {
        // Shadow banned user sees own messages; others don't see them
        if ($msg['ip'] === $currentUser->last_ip) $result[] = $msg;
      } else {
        $result[] = $msg;
      }
    }
    return array_values($result);
  };

  switch ("{$_SERVER['REQUEST_METHOD']} /{$_GET['api']}") {
    case 'GET /messages':
      if ($currentUser->last_seen < now()->modify('-10 seconds')->format(DATE_FORMAT)) {
        $db->prepare('UPDATE users SET last_seen = ?, last_ip = ? WHERE id = ?')
          ->execute([$now, $ip, $currentUser->id]);
      }

      $after = (int)($_GET['after'] ?? 0);
      if ($after === 0) {
        $stmt = $db->prepare('SELECT id, username, message, created_at, ip, kind, reply_to, color FROM messages ORDER BY id DESC LIMIT 200');
        $stmt->execute();
        $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
      } else {
        $stmt = $db->prepare('SELECT id, username, message, created_at, ip, kind, reply_to, color FROM messages WHERE id > ? ORDER BY id ASC LIMIT 200');
        $stmt->execute([$after]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }
      $messages = $filterShadowBanned($messages);
      // Strip ip from response
      $messages = array_map(function ($m) {
        unset($m['ip']);
        return $m;
      }, $messages);

      $cutoff = now()->modify('-1 hour')->format(DATE_FORMAT);
      $users = $db->prepare('SELECT username, last_seen, rank, color, status FROM users WHERE last_seen >= ? ORDER BY username ASC');
      $users->execute([$cutoff]);

      $response = ['messages' => $messages, 'users' => $users->fetchAll(PDO::FETCH_ASSOC)];
      jsonResponse($response);
      break;

    case 'GET /history':
      $before = (int)($_GET['before'] ?? 0);
      $stmt = $db->prepare('SELECT id, username, message, created_at, ip, kind, reply_to, color FROM messages WHERE id < ? ORDER BY id DESC LIMIT 200');
      $stmt->execute([$before]);
      $messages = $filterShadowBanned(array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)));
      // Strip ip from response
      $messages = array_map(function ($m) {
        unset($m['ip']);
        return $m;
      }, $messages);
      jsonResponse(['messages' => $messages, 'hasMore' => count($messages) === 200]);
      break;

    case 'GET /message':
      $id = (int)($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT username, message FROM messages WHERE id = ? AND kind = 'text'");
      $stmt->execute([$id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row) jsonResponse(['error' => 'Not found']);
      jsonResponse(['username' => $row['username'], 'message' => $row['message']]);
      break;

    case 'POST /offline':
      $db->prepare('UPDATE users SET last_seen = ? WHERE id = ?')
        ->execute([now()->modify('-1 minutes')->format(DATE_FORMAT), $currentUser->id]);
      jsonResponse(['ok' => true]);
      break;

    case 'POST /send':
      if ($currentUser->muted_until && $currentUser->muted_until > $now) {
        jsonResponse(['error' => 'You are muted.']);
      }
      // Rate limit (skip for admins)
      if ($currentUser->role !== 'admin') {
        $rateLimit = match (true) {
          $currentUser->rank >= 2 => 15,
          $currentUser->rank === 1 => 6,
          default => 10,
        };
        $cutoff = now()->modify('-60 seconds')->format(DATE_FORMAT);
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE username = ? AND created_at >= ? AND kind = 'text'");
        $stmt->execute([$currentUser->username, $cutoff]);
        if ($stmt->fetchColumn() >= $rateLimit) {
          jsonResponse(['error' => 'Too many messages. Wait a moment.']);
        }
      }

      $replyTo = isset($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;
      if ($replyTo) {
        $check = $db->prepare("SELECT id FROM messages WHERE id = ? AND kind = 'text'");
        $check->execute([$replyTo]);
        if (!$check->fetch()) $replyTo = null;
      }

      // Image upload
      if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        if ($currentUser->rank < 8) {
          jsonResponse(['error' => 'Rank 8+ required to upload images.']);
        }
        $file = $_FILES['image'];
        if ($file['size'] > 1024 * 1024) {
          jsonResponse(['error' => 'File too large. Max 1MB.']);
        }
        $mime = mime_content_type($file['tmp_name']);
        if ($mime !== 'image/gif') {
          jsonResponse(['error' => 'Only GIF images are allowed.']);
        }
        $data = file_get_contents($file['tmp_name']);
        $hash = hash('sha256', $data);
        $existing = $filesDb->prepare('SELECT id FROM files WHERE hash = ?');
        $existing->execute([$hash]);
        $fileId = $existing->fetchColumn();
        if (!$fileId) {
          $stmt = $filesDb->prepare('INSERT INTO files (hash, data, mime, size, username, created_at) VALUES (?, ?, ?, ?, ?, ?)');
          $stmt->execute([$hash, $data, $mime, $file['size'], $currentUser->username, $now]);
          $fileId = (int)$filesDb->lastInsertId();
        }
        $text = '[img:' . $hash . ']';
        $stmt = $db->prepare('INSERT INTO messages (username, message, created_at, ip, reply_to, color) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$currentUser->username, $text, $now, $ip, $replyTo, $currentUser->color]);
        jsonResponse([]);
      }

      // Text message
      $text = trim($_POST['message'] ?? '');

      // Styled messages

      if ($currentUser->rank < 9 && str_starts_with($text, '~~')) {
        jsonResponse(['error' => 'Rank 9 required for wave messages.']);
      }
      if ($currentUser->rank < 9 && str_starts_with($text, '^^')) {
        jsonResponse(['error' => 'Rank 9 required for cold breeze messages.']);
      }
      if ($currentUser->rank < 6 && (str_starts_with($text, '~') || str_starts_with($text, '^'))) {
        jsonResponse(['error' => 'Rank 6+ required for styled messages.']);
      }
      if ($currentUser->rank < 9 && str_starts_with($text, '#')) {
        jsonResponse(['error' => 'Rank 9 required for big messages.']);
      }
      if ($currentUser->rank < 5 && str_starts_with($text, '__')) {
        jsonResponse(['error' => 'Rank 5+ required for underline messages.']);
      }
      if ($currentUser->rank < 4 && str_starts_with($text, '*')) {
        jsonResponse(['error' => 'Rank 4+ required for bold messages.']);
      }
      if ($currentUser->rank < 2 && str_starts_with($text, '_')) {
        jsonResponse(['error' => 'Rank 2+ required for italic messages.']);
      }

      // Strip URLs
      if ($currentUser->rank < 5) {
        $text = preg_replace('#https?://\S+#i', '***', $text);               // strip http(s) URLs
        $text = preg_replace('#\b\S+\.\S+/\S*#i', '***', $text);             // strip domain.tld/path
      }

      // Rank 0 restrictions
      if ($currentUser->rank === 0) {
        // Enforce 5s spacing between messages
        $lastMsg = $db->prepare("SELECT created_at FROM messages WHERE username = ? AND kind = 'text' ORDER BY id DESC LIMIT 1");
        $lastMsg->execute([$currentUser->username]);
        $lastMsgTime = $lastMsg->fetchColumn();
        if ($lastMsgTime && (new DateTimeImmutable($lastMsgTime))->modify('+3 seconds') > now()) {
          jsonResponse(['error' => 'Wait 3 seconds between messages.']);
        }

        // Modify message to remove bad words and repeating characters
        $isQuestion = str_ends_with($text, '?') || str_ends_with($text, '?!');
        $text = preg_replace('/[^a-zA-Z ]/u', '', $text);                    // only allow letters and spaces
        $text = preg_replace('/(\S{10})\S+/u', '$1…', $text);                // truncate long words
        $allowedWords = loadAllowedWords();
        $badWords = loadBadWords();
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $filtered = [];
        foreach ($words as $word) {
          $lower = strtolower($word);
          if (!isAllowedWord($lower, $allowedWords) || isBadWord($lower, $badWords)) {
            $filtered[] = str_repeat('•', min(mb_strlen($word), 3));
          } else {
            $filtered[] = $word;
          }
        }
        $text = implode(' ', $filtered);
        $first = mb_substr($text, 0, 1);                                     // preserve first letter case
        $text = $first . mb_strtolower(mb_substr($text, 1));                 // force rest to lowercase
        $text = preg_replace('/\p{So}|\p{Cs}/u', '', $text);                 // replace emoji with nothing
        $text = preg_replace('/(•)\1{3,}/u', '•••', $text);                  // bad words should be short
        $text = trim($text);
        if (mb_strlen($text) > 70) $text = mb_substr($text, 0, 70);          // truncate long
        if ($isQuestion) $text .= '?';
      }

      if ($text === '') {
        jsonResponse(['error' => 'This message is not allowed ¯\_(ツ)_/¯']);
      }

      if (mb_strlen($text) > 2000) {
        jsonResponse(['error' => 'Message is too long']);
      }

      // Route commands
      if ($text[0] === '/') {
        $cmdName = explode(' ', substr($text, 1))[0];
        if (isset($commands[$cmdName])) {
          $cmd = $commands[$cmdName];
          requireRole(...$cmd['roles']);
          if (preg_match($cmd['pattern'], $text, $m)) {
            ($cmd['handler'])(...array_slice($m, 1));
            jsonResponse([]);
          }
          jsonResponse(['error' => "Usage: {$cmd['usage']}"]);
        }
        $available = [];
        foreach ($commands as $cmd) {
          if (in_array($currentUser->role, $cmd['roles'], true)) {
            $available[] = $cmd['usage'];
          }
        }
        $list = $available ? "Unknown command. Available:\n" . implode("\n", $available) : 'No commands available.';
        jsonResponse(['error' => $list]);
      }

      $stmt = $db->prepare('INSERT INTO messages (username, message, created_at, ip, reply_to, color) VALUES (?, ?, ?, ?, ?, ?)');
      $stmt->execute([$currentUser->username, $text, $now, $ip, $replyTo, $currentUser->color]);
      jsonResponse([]);
      break;
  }

  jsonResponse(['error' => 'Not found'], 404);
}

?>
<!DOCTYPE html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title><?= TITLE ?></title>
<style>
  :root {
    --bg: #fff;
    --bg-secondary: #f5f5f5;
    --bg-hover: #eee;
    --bg-reply: #eef8ff;
    --text: #161616;
    --text-muted: #888;
    --border: #e0e0e0;
    --accent: #0066cc;
    --online: #22c55e;
    --mention-bg: rgba(212, 190, 80, 0.15);
    --error-bg: #fee;
    --error-text: #c00;
    --sidebar-width: 260px;

    @media (prefers-color-scheme: dark) {
      --bg: #000;
      --bg-secondary: #111;
      --bg-hover: #222;
      --bg-reply: #0a152e;
      --text: #e5e5e5;
      --text-muted: #777;
      --border: #222;
      --accent: #58a6ff;
      --online: #22c55e;
      --mention-bg: rgba(212, 190, 80, 0.12);
      --error-bg: #2a0000;
      --error-text: #f88;
    }
  }

  :root[data-theme="light"] {
    --bg: #fff;
    --bg-secondary: #f5f5f5;
    --bg-hover: #eee;
    --bg-reply: #eef8ff;
    --text: #161616;
    --text-muted: #888;
    --border: #e0e0e0;
    --accent: #0066cc;
    --online: #22c55e;
    --mention-bg: rgba(212, 190, 80, 0.15);
    --error-bg: #fee;
    --error-text: #c00;
    color-scheme: light;
  }

  :root[data-theme="dark"] {
    --bg: #000;
    --bg-secondary: #111;
    --bg-hover: #222;
    --bg-reply: #0a152e;
    --text: #e5e5e5;
    --text-muted: #777;
    --border: #222;
    --accent: #58a6ff;
    --online: #22c55e;
    --mention-bg: rgba(212, 190, 80, 0.12);
    --error-bg: #2a0000;
    --error-text: #f88;
    color-scheme: dark;
  }

  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  html, body {
    height: 100dvh;
    overflow: hidden;
    color-scheme: light dark;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font: 15px / 1.5 system-ui, -apple-system, sans-serif;
    display: flex;
  }

  a {
    color: var(--accent);
    text-decoration: underline;
  }

  /* --- Sidebar --- */
  .sidebar {
    width: var(--sidebar-width);
    min-width: var(--sidebar-width);
    height: 100dvh;
    background: var(--bg-secondary);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
  }

  .sidebar-header {
    height: 48px;
    padding-inline: 16px;
    font-weight: 700;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .icon {
    width: 1em;
    height: 1em;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
    vertical-align: middle;
  }

  .sidebar-header .close-sidebar {
    display: none;
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 20px;
    cursor: pointer;
    padding: 0 4px;
  }

  .users {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
  }

  .user {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: background 0.1s;
  }

  .user:hover {
    background: var(--bg-hover);
  }

  .status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--text-muted);
    flex-shrink: 0;
  }

  .status-dot.online {
    background: var(--online);
  }

  .user-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
  }

  .user-status {
    font-size: 11px;
    color: var(--text-muted);
    flex-shrink: 0;
  }

  /* --- Rank icons --- */
  .rank-icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    vertical-align: -2px;
    margin-left: 4px;
  }

  .rank-icon.rank-1, .rank-icon.rank-2, .rank-icon.rank-3, .rank-icon.rank-4 {
    color: #888;
    stroke: currentColor;
    fill: none;
  }

  .rank-icon.rank-5, .rank-icon.rank-6 {
    color: #3b82f6;
  }

  .rank-icon.rank-7 {
    color: #a855f7;
  }

  .rank-icon.rank-8 {
    color: #22d3ee;
  }

  .rank-icon.rank-9 {
    color: #eab308;
  }

  /* --- Main chat area --- */
  .main {
    flex: 1;
    display: flex;
    flex-direction: column;
    height: 100dvh;
    min-width: 0;
    position: relative;
  }

  .chat-header {
    height: 48px;
    padding-inline: 16px;
    border-bottom: 1px solid var(--border);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--bg);
  }

  .open-sidebar {
    display: none;
    background: none;
    border: none;
    color: var(--text);
    font-size: 20px;
    cursor: pointer;
    padding: 0 4px;
  }

  .messages {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
    display: flex;
    flex-direction: column;
  }

  .load-more {
    display: block;
    margin: 8px auto;
    padding: 6px 20px;
    font: inherit;
    font-size: 13px;
    color: var(--accent);
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.1s;
  }

  .load-more:hover {
    background: var(--bg-hover);
  }

  .message {
    padding: 3px 16px;
    padding-right: 70px;
    line-height: 1.5;
    word-wrap: break-word;
    white-space: pre-wrap;
    transition: background 0.1s;
    position: relative;
  }

  .message:hover {
    background: var(--bg-hover);
  }

  .message-actions {
    position: absolute;
    right: 16px;
    top: 3px;
    display: flex;
    align-items: center;
    gap: 4px;
    user-select: none;
    opacity: 0;
    transition: opacity 0.1s;
  }

  .message:hover .message-actions {
    opacity: 1;
  }

  .message-time {
    font-size: 11px;
    color: var(--text-muted);
    font-variant-numeric: tabular-nums;
    cursor: pointer;
  }

  .message-author {
    font-weight: 700;
    margin-right: 4px;
    cursor: pointer;
  }

  .message-text {
    white-space: pre-wrap;
  }

  .message-text.emoji-only {
    font-size: 2.5em;
    line-height: 1.2;
  }

  .message-text.big-message {
    font-size: 2em;
    font-weight: 500;
    line-height: 1.3;
  }

  .message-text.italic-message {
    font-style: italic;
  }

  .message-text.underline-message {
    text-decoration: underline;
  }

  .message-text.bold-message {
    font-weight: bold;
  }

  .message-text.fire-message {
    position: relative;
    background: linear-gradient(180deg, #fff200, #ff8c00, #ff2400, #c00);
    background-size: 100% 300%;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: fire-flow 1.5s ease-in-out infinite alternate;
    font-weight: bold;
    text-shadow: none;
  }

  .fire-particle {
    position: absolute;
    pointer-events: none;
    border-radius: 50%;
    animation: fire-rise linear forwards;
  }

  @keyframes fire-flow {
    0% {
      background-position: 50% 100%;
    }
    100% {
      background-position: 50% 0%;
    }
  }

  @keyframes fire-rise {
    0% {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
    50% {
      opacity: 0.8;
    }
    100% {
      opacity: 0;
      transform: translateY(-30px) scale(0);
    }
  }

  .message-text.cold-message {
    position: relative;
    background: linear-gradient(180deg, #e0f7ff, #7ec8e3, #3a8fd8, #1a5276);
    background-size: 100% 300%;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: cold-flow 2s ease-in-out infinite alternate;
    font-weight: bold;
    text-shadow: none;
    overflow: visible;
  }

  .snow-particle {
    position: absolute;
    pointer-events: none;
    border-radius: 50%;
    background: white;
    animation: snow-fall linear forwards;
  }

  @keyframes cold-flow {
    0% {
      background-position: 50% 0%;
    }
    100% {
      background-position: 50% 100%;
    }
  }

  @keyframes snow-fall {
    0% {
      opacity: 1;
      transform: translateY(0) translateX(0) scale(1);
    }
    50% {
      opacity: 0.8;
      transform: translateY(15px) translateX(4px) scale(0.8);
    }
    100% {
      opacity: 0;
      transform: translateY(30px) translateX(-3px) scale(0);
    }
  }

  .world-shake {
    animation: world-shake 0.15s linear 10;
  }

  @keyframes world-shake {
    0%, 100% {
      transform: translate(0, 0) rotate(0);
    }
    20% {
      transform: translate(-8px, 4px) rotate(-1.5deg);
    }
    40% {
      transform: translate(6px, -6px) rotate(2deg);
    }
    60% {
      transform: translate(-4px, 2px) rotate(-1deg);
    }
    80% {
      transform: translate(8px, -3px) rotate(1.5deg);
    }
  }

  .world-flip {
    animation: world-flip 10s ease-in-out;
  }

  @keyframes world-flip {
    0% {
      transform: rotate(0deg);
    }
    5% {
      transform: rotate(180deg);
    }
    90% {
      transform: rotate(180deg);
    }
    100% {
      transform: rotate(360deg);
    }
  }

  .world-blur {
    animation: world-blur 3s ease-in-out;
  }

  @keyframes world-blur {
    0%, 100% {
      filter: blur(0);
    }
    30%, 70% {
      filter: blur(8px);
    }
  }

  .world-disco {
    animation: world-disco 0.2s linear 15;
  }

  @keyframes world-disco {
    0% {
      background-color: #ff0080;
    }
    25% {
      background-color: #00ff41;
    }
    50% {
      background-color: #ffff00;
    }
    75% {
      background-color: #0080ff;
    }
    100% {
      background-color: #ff00ff;
    }
  }

  .flash-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 99999;
    pointer-events: none;
    background: white;
    animation: flash-bang 2s ease-out forwards;
  }

  @keyframes flash-bang {
    0% {
      opacity: 1;
    }
    15% {
      opacity: 0.2;
    }
    30% {
      opacity: 0.9;
    }
    45% {
      opacity: 0.2;
    }
    60% {
      opacity: 0.7;
    }
    75% {
      opacity: 0.2;
    }
    90% {
      opacity: 0.5;
    }
    100% {
      opacity: 0;
    }
  }

  .world-wave {
    animation: world-wave 0.4s ease-in-out 8 alternate;
  }

  @keyframes world-wave {
    0% {
      transform: skewX(-3deg) skewY(-1deg);
    }
    50% {
      transform: skewX(3deg) skewY(1deg);
    }
    100% {
      transform: skewX(-2deg) skewY(-1deg);
    }
  }

  .blackout-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 99999;
    pointer-events: none;
    background: black;
    animation: blackout 3s step-start forwards;
  }

  @keyframes blackout {
    0% {
      opacity: 1;
    }
    70% {
      opacity: 1;
    }
    71% {
      opacity: 0;
    }
    100% {
      opacity: 0;
    }
  }

  .rain-drop {
    position: fixed;
    width: 2px;
    z-index: 9999;
    pointer-events: none;
    background: linear-gradient(to bottom, transparent, rgba(100, 160, 255, 0.6), rgba(80, 140, 255, 0.9));
    border-radius: 0 0 2px 2px;
    animation: rain-fall linear forwards;
  }

  @keyframes rain-fall {
    0% {
      opacity: 0.8;
      transform: translateY(0);
    }
    100% {
      opacity: 0;
      transform: translateY(100vh);
    }
  }

  .matrix-canvas {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 9999;
    pointer-events: none;
  }

  .confetti-piece {
    position: fixed;
    width: 8px;
    height: 8px;
    z-index: 9999;
    pointer-events: none;
    animation: confetti-fall linear forwards;
  }

  @keyframes confetti-fall {
    0% {
      opacity: 1;
      transform: translateY(0) rotate(0deg);
    }
    100% {
      opacity: 0;
      transform: translateY(100vh) rotate(720deg);
    }
  }

  .message-text.gradient-message {
    background: linear-gradient(90deg, #ff0080, #ff8c00, #40e0d0, #7b68ee, #ff0080);
    background-size: 300% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: gradient-flow 3s linear infinite;
    font-weight: bold;
  }

  @keyframes gradient-flow {
    0% {
      background-position: 0% 50%;
    }
    100% {
      background-position: 300% 50%;
    }
  }

  .message-text.wave-message {
    display: inline-block;
  }

  .wave-char {
    display: inline-block;
    animation: wave-bob 1.5s ease-in-out infinite;
    white-space: pre;
  }

  @keyframes wave-bob {
    0%, 100% {
      transform: translateY(0);
    }
    50% {
      transform: translateY(-6px);
    }
  }

  .message.mention {
    background: var(--mention-bg);
    border-left: 2px solid rgb(212, 190, 80);
  }

  .message.mention:hover {
    background: rgba(212, 190, 80, 0.22);
  }

  .message.system {
    color: var(--text-muted);
    font-size: 14px;
  }

  .message.shadow-banned {
    color: #888;
    font-size: 12px;
    opacity: 0.5;
  }

  .new-messages-badge {
    position: absolute;
    bottom: 60px;
    left: 50%;
    transform: translateX(-50%);
    padding: 6px 16px;
    background: var(--accent);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    border-radius: 16px;
    cursor: pointer;
    z-index: 5;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    display: none;
  }

  /* --- Reply quote in message --- */
  .reply-quote {
    font-size: 12px;
    color: var(--text-muted);
    border-left: 2px solid var(--accent);
    padding: 1px 8px;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
    cursor: pointer;
  }

  .reply-quote:hover {
    background: var(--bg-hover);
  }

  .reply-quote-author {
    font-weight: 700;
    margin-right: 4px;
  }

  /* --- Reply preview bar --- */
  .reply-preview {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border);
    font-size: 13px;
    color: var(--text-muted);
  }

  .reply-preview.active {
    display: flex;
  }

  .reply-preview-text {
    flex: 1;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  .reply-preview-author {
    font-weight: 700;
    color: var(--accent);
    margin-right: 4px;
  }

  .reply-preview-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 16px;
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
  }

  .reply-preview-close:hover {
    color: var(--text);
  }

  /* --- Input area --- */
  .chat-form {
    display: flex;
    border-top: 1px solid var(--border);
    background: var(--bg-secondary);
  }

  .chat-form input {
    flex: 1;
    padding: 10px 16px;
    border: none;
    background: transparent;
    color: var(--text);
    font: inherit;
    outline: none;
  }

  .chat-form button {
    padding: 10px 20px;
    border: none;
    background: transparent;
    color: var(--accent);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
  }

  .chat-form button:hover {
    opacity: 0.7;
  }

  .chat-upload {
    padding: 10px 12px;
    border: none;
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
  }

  .chat-upload:hover {
    color: var(--accent);
  }

  .message-image {
    display: block;
    margin-top: 4px;
    max-width: 100%;
    height: auto;
  }

  /* --- Auth form --- */
  .auth {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    width: 100%;
  }

  .auth-box {
    width: 100%;
    max-width: 360px;
    padding: 32px;
  }

  .auth-tabs {
    display: flex;
    margin-bottom: 24px;
    border-bottom: 2px solid var(--border);
  }

  .auth-tab {
    flex: 1;
    padding: 10px;
    text-align: center;
    font-weight: 600;
    cursor: pointer;
    border: none;
    background: none;
    color: var(--text-muted);
    font: inherit;
    position: relative;
    transition: color 0.15s;
  }

  .auth-tab.active {
    color: var(--accent);
  }

  .auth-tab.active::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -2px;
    height: 2px;
    background: var(--accent);
  }

  .auth-panel {
    display: none;
  }

  .auth-panel.active {
    display: block;
  }

  .auth-box .field {
    margin-bottom: 12px;
  }

  .auth-box label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--text-muted);
  }

  .auth-box input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-secondary);
    color: var(--text);
    font: inherit;
    outline: none;
    transition: border-color 0.15s;
  }

  .auth-box input:focus {
    border-color: var(--accent);
  }

  .auth-box button[type="submit"] {
    width: 100%;
    padding: 10px;
    margin-top: 20px;
    border: none;
    border-radius: 8px;
    background: var(--accent);
    color: #fff;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
  }

  .auth-box button[type="submit"]:hover {
    opacity: 0.85;
  }

  .auth-error {
    background: var(--error-bg);
    color: var(--error-text);
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 16px;
    text-align: center;
  }

  .theme-toggle {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 18px;
    cursor: pointer;
    padding: 4px;
    display: flex;
    align-items: center;
  }

  .theme-toggle:hover {
    color: var(--text);
  }

  /* --- Mobile overlay --- */
  .sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
    z-index: 9;
  }

  /* --- Mobile --- */
  @media (max-width: 640px) {
    .sidebar {
      position: fixed;
      left: 0;
      top: 0;
      z-index: 10;
      transform: translateX(-100%);
      transition: transform 0.25s ease;
    }

    .sidebar.open {
      transform: translateX(0);
    }

    .sidebar.open ~ .sidebar-overlay {
      display: block;
    }

    .sidebar-header .close-sidebar {
      display: block;
    }

    .open-sidebar {
      display: block;
    }

    .chat-form button span {
      display: none;
    }
  }
</style>

<script>
  (function () {
    var saved = localStorage.getItem('theme')
    var modes = ['auto', 'light', 'dark']

    function getEffective(mode) {
      if (mode === 'light' || mode === 'dark') return mode
      return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
    }

    function apply(mode) {
      var eff = getEffective(mode)
      if (mode === 'auto') {
        delete document.documentElement.dataset.theme
      } else {
        document.documentElement.dataset.theme = mode
      }
      // Update meta theme-color tags
      var metas = document.querySelectorAll('meta[name="theme-color"]')
      var color = eff === 'dark' ? '#000000' : '#ffffff'
      metas.forEach(function (m) {
        m.setAttribute('content', color)
      })
      // Update toggle button icons
      var icons = {auto: 'monitor', light: 'sun', dark: 'moon'}
      document.querySelectorAll('.theme-toggle use').forEach(function (u) {
        u.setAttribute('href', '#icon-' + icons[mode])
      })
    }

    window.cycleTheme = function () {
      var cur = localStorage.getItem('theme') || 'auto'
      var next = modes[(modes.indexOf(cur) + 1) % modes.length]
      if (next === 'auto') {
        localStorage.removeItem('theme')
      } else {
        localStorage.setItem('theme', next)
      }
      apply(next)
    }

    // Listen for OS theme changes to update when in auto mode
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      apply(localStorage.getItem('theme') || 'auto')
    })

    apply(saved || 'auto')
  })()
</script>

<?php if (!isset($currentUser)): ?>

  <div class="auth">
    <div class="auth-box">
      <div class="auth-tabs">
        <button class="auth-tab active" onclick="switchTab('login')" type="button">Login</button>
        <button class="auth-tab" onclick="switchTab('register')" type="button">Register</button>
      </div>
      <?php if ($authError): ?>
        <div class="auth-error"><?= htmlspecialchars($authError) ?></div>
      <?php endif; ?>
      <form class="auth-panel active" id="tab-login" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <div class="field">
          <label for="login-username">Username</label>
          <input type="text" id="login-username" name="username" maxlength="32" required autofocus
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="login-password">Password</label>
          <input type="password" id="login-password" name="password" required>
        </div>
        <button type="submit" name="action" value="login">Login</button>
      </form>
      <form class="auth-panel" id="tab-register" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <div class="field">
          <label for="reg-username">Username</label>
          <input type="text" id="reg-username" name="username" minlength="5" maxlength="9" pattern="[a-zA-Z]+"
                 title="Letters only, no numbers" required
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="reg-password">Password</label>
          <input type="password" id="reg-password" name="password" required minlength="6">
        </div>
        <button type="submit" name="action" value="register">Register</button>
      </form>
    </div>
  </div>
  <script>
    function switchTab(tab) {
      document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'))
      document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'))
      document.querySelector('#tab-' + tab).classList.add('active')
      document.querySelectorAll('.auth-tab').forEach(t => {
        if (t.textContent.toLowerCase() === tab) t.classList.add('active')
      })
    }
    <?php if (($_POST['action'] ?? '') === 'register'): ?>
    switchTab('register')
    <?php endif; ?>
  </script>

<?php else: ?>

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      Online
      <button class="close-sidebar" onclick="toggleSidebar()">
        <svg class="icon">
          <use href="#icon-x"/>
        </svg>
      </button>
    </div>
    <div class="users"></div>
  </aside>
  <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

  <!-- Main chat -->
  <main class="main">
    <div class="chat-header">
      <button class="open-sidebar" onclick="toggleSidebar()">
        <svg class="icon">
          <use href="#icon-menu"/>
        </svg>
      </button>
      <span><?= TITLE ?></span>
      <span style="flex:1"></span>
      <button class="theme-toggle" onclick="cycleTheme()" title="Toggle theme">
        <svg class="icon">
          <use href="#icon-sun"/>
        </svg>
      </button>
      <span
        style="font-size:13px;font-weight:400;color:var(--text-muted)"><?= htmlspecialchars($currentUser->username) ?></span>
      <a href="?logout" style="font-size:13px">logout</a>
    </div>

    <div class="messages" id="messages">
      <button class="load-more" id="load-more">Load older messages</button>
    </div>
    <div class="new-messages-badge" id="new-messages-badge"></div>

    <div class="reply-preview" id="reply-preview">
      <span class="reply-preview-text"></span>
      <button class="reply-preview-close" onclick="cancelReply()">
        <svg class="icon">
          <use href="#icon-x"/>
        </svg>
      </button>
    </div>
    <form class="chat-form" id="chat-form">
      <input type="file" id="file-input" accept="image/gif" style="display:none">
      <input type="text" name="message" placeholder="Type a message..." autocomplete="off"
             maxlength="<?= $currentUser->rank === 0 ? 70 : 2000 ?>">
      <button type="button" class="chat-upload"
              onclick="document.getElementById('file-input').click()"<?php if ($currentUser->rank < 8): ?> style="display:none"<?php endif; ?>>
        <svg class="icon">
          <use href="#icon-image"/>
        </svg>
      </button>
      <button type="submit">Send</button>
    </form>
  </main>

  <script>
    function createElement(tag, attrs, ...children) {
      const e = document.createElement(tag)
      if (attrs) for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v)
      e.append(...children.filter(Boolean))
      return e
    }

    function div(attrs, ...children) {
      return createElement('div', attrs, ...children)
    }

    function span(attrs, ...children) {
      return createElement('span', attrs, ...children)
    }

    function text(t) {
      return document.createTextNode(t)
    }

    const isSlowDevice = navigator.hardwareConcurrency <= 4 || navigator.deviceMemory <= 2

    function icon(name) {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
      svg.setAttribute('class', 'icon')
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use')
      use.setAttribute('href', '#icon-' + name)
      svg.appendChild(use)
      return svg
    }

    function morph(from, to) {
      if (from.nodeType !== to.nodeType) {
        from.replaceWith(to)
        return
      }
      if (from.nodeType === Node.TEXT_NODE || from.nodeType === Node.COMMENT_NODE) {
        if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue
        return
      }
      if (from.nodeType === Node.ELEMENT_NODE) {
        if (from.tagName !== to.tagName) {
          from.replaceWith(to)
          return
        }
        syncAttributes(from, to)
        syncChildren(from, to)
        return
      }
      from.replaceWith(to)
    }

    function syncAttributes(from, to) {
      for (const {name} of Array.from(from.attributes)) {
        if (!to.hasAttribute(name)) from.removeAttribute(name)
      }
      for (const {name, value} of Array.from(to.attributes)) {
        if (from.getAttribute(name) !== value) from.setAttribute(name, value)
      }
    }

    function syncChildren(from, to) {
      const fromKids = Array.from(from.childNodes)
      const toKids = Array.from(to.childNodes)
      const commonLen = Math.min(fromKids.length, toKids.length)
      for (let i = 0; i < commonLen; i++) {
        morph(fromKids[i], toKids[i])
      }
      for (let i = fromKids.length - 1; i >= toKids.length; i--) {
        from.removeChild(fromKids[i])
      }
      for (let i = commonLen; i < toKids.length; i++) {
        from.appendChild(toKids[i])
      }
    }

    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open')
    }

    const messages = document.getElementById('messages')
    const loadMore = document.getElementById('load-more')
    const usersList = document.querySelector('.users')
    const badge = document.getElementById('new-messages-badge')
    const form = document.getElementById('chat-form')
    const input = form.querySelector('input[name="message"]')
    const replyPreview = document.getElementById('reply-preview')
    const myUsername = <?= json_encode($currentUser->username) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>

    let lastId = 0
    let oldestId = 0
    let hasMore = true
    let atBottom = true
    let unread = 0
    let polling = false
    let replyTo = null

    function scrollToBottom() {
      messages.scrollTop = messages.scrollHeight
      unread = 0
      badge.style.display = 'none'
    }

    function setReply(id, username, body) {
      replyTo = id
      const preview = replyPreview.querySelector('.reply-preview-text')
      preview.innerHTML = ''
      preview.append(
        span({class: 'reply-preview-author'}, text(username)),
        text(body.replace(/\n/g, ' ').substring(0, 100))
      )
      replyPreview.classList.add('active')
      input.focus()
    }

    function cancelReply() {
      replyTo = null
      replyPreview.classList.remove('active')
    }

    messages.addEventListener('scroll', () => {
      atBottom = messages.scrollTop + messages.clientHeight >= messages.scrollHeight - 30
      if (atBottom) {
        unread = 0
        badge.style.display = 'none'
      }
    }, {passive: true})

    badge.addEventListener('click', scrollToBottom)

    messages.addEventListener('click', (e) => {
      // Click on reply quote scrolls to original
      const quote = e.target.closest('.reply-quote')
      if (quote) {
        const origId = quote.dataset.replyTo
        const orig = messages.querySelector(`[data-id="${origId}"]`)
        if (orig) {
          orig.scrollIntoView({behavior: 'smooth', block: 'center'})
          orig.style.background = 'var(--bg-reply)'
          setTimeout(() => orig.style.background = '', 1500)
        }
        return
      }
      // Timestamp click copies id
      const time = e.target.closest('.message-time')
      if (time) {
        const id = time.closest('.message')?.dataset.id
        if (id) navigator.clipboard.writeText(id)
        return
      }
      // Skip links and username mentions
      if (e.target.closest('a') || e.target.closest('[data-username]')) return
      // Skip if user is selecting text
      if (window.getSelection().toString()) return
      // Click anywhere on message → reply
      const msg = e.target.closest('.message')
      const author = msg?.querySelector('.message-author')?.textContent || ''
      const textNode = msg?.querySelector('.message-text')?.textContent || ''
      if (msg && author.length > 0 && textNode.length > 0) {
        setReply(msg.dataset.id, author, textNode)
      }
    })

    addEventListener('click', (e) => {
      const name = e.target.closest('[data-username]')
      if (name) {
        const username = name.dataset.username
        input.value = input.value.trimEnd() + (input.value ? ' ' : '') + username + ' '
        input.focus()
      }
    })

    function formatTime(iso) {
      const d = new Date(iso)
      return d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})
    }

    function findReplySource(id) {
      const el = messages.querySelector(`[data-id="${id}"]`)
      if (!el) return null
      const author = el.querySelector('.message-author')?.textContent || ''
      const body = el.querySelector('.message-text')?.textContent || ''
      return {author, body}
    }

    function fillQuote(quote, src) {
      quote.innerHTML = ''
      quote.append(
        span({class: 'reply-quote-author'}, text(src.author)),
        text(src.body.replace(/\n/g, ' ').substring(0, 100))
      )
    }

    async function fetchReplySource(id, quote) {
      try {
        const res = await fetch('?api=message&id=' + id)
        const data = await res.json()
        if (data.username) {
          const body = /^\[img:[a-f0-9]{64}\]$/.test(data.message.trim()) ? '[GIF]' : data.message
          fillQuote(quote, {author: data.username, body})
        }
      } catch (e) {
        console.error(e)
      }
    }

    function isEmojiOnly(str) {
      const trimmed = str.trim()
      const emojis = trimmed.match(/\p{Emoji_Presentation}|\p{Emoji}\uFE0F/gu)
      return emojis && emojis.length >= 1 && emojis.length <= 3 && emojis.join('') === trimmed
    }

    function renderMessageText(content) {
      const el = span({class: 'message-text'})
      const t = content.trimStart()
      if (t.startsWith('#')) {
        content = t.slice(1)
        el.classList.add('big-message')
      } else if (t.startsWith('~~')) {
        content = t.slice(2)
        el.classList.add('wave-message')
        const text = content
        el.textContent = ''
        for (let i = 0; i < text.length; i++) {
          const ch = document.createElement('span')
          ch.textContent = text[i]
          ch.className = 'wave-char'
          ch.style.animationDelay = (i * 0.06) + 's'
          el.appendChild(ch)
        }
        return el
      } else if (t.startsWith('~')) {
        content = t.slice(1)
        el.classList.add('gradient-message')
      } else if (t.startsWith('^^')) {
        content = t.slice(2)
        el.classList.add('cold-message')
        let coldActive = true
        const len = content.length
        const baseDelay = Math.max(10, 120 - len * 2)
        const spawnSnow = () => {
          if (!coldActive || !el.isConnected) return
          const p = document.createElement('span')
          p.className = 'snow-particle'
          const size = 2 + Math.random() * 3
          p.style.width = size + 'px'
          p.style.height = size + 'px'
          p.style.left = (Math.random() * 100) + '%'
          p.style.top = (-2 + Math.random() * 4) + 'px'
          p.style.animationDuration = (0.8 + Math.random() * 1) + 's'
          el.appendChild(p)
          p.addEventListener('animationend', () => p.remove())
          setTimeout(spawnSnow, baseDelay + Math.random() * baseDelay)
        }
        setTimeout(spawnSnow, Math.random() * 100)
        if (isSlowDevice) setTimeout(() => {
          coldActive = false
        }, 5000)
      } else if (t.startsWith('^')) {
        content = t.slice(1)
        el.classList.add('fire-message')
        let fireActive = true
        const spawnParticle = () => {
          if (!fireActive || !el.isConnected) return
          const p = document.createElement('span')
          p.className = 'fire-particle'
          const colors = ['#fff200', '#ff8c00', '#ff4500', '#ff2400']
          p.style.background = colors[Math.random() * colors.length | 0]
          const size = 2 + Math.random() * 4
          p.style.width = size + 'px'
          p.style.height = size + 'px'
          p.style.left = (Math.random() * 100) + '%'
          p.style.bottom = (-2 + Math.random() * 4) + 'px'
          p.style.animationDuration = (0.5 + Math.random() * 0.8) + 's'
          el.appendChild(p)
          p.addEventListener('animationend', () => p.remove())
          const delay = 30 + Math.random() * 60
          setTimeout(spawnParticle, delay)
        }
        setTimeout(spawnParticle, Math.random() * 100)
        if (isSlowDevice) setTimeout(() => {
          fireActive = false
        }, 5000)
      } else if (t.startsWith('__')) {
        content = t.slice(2)
        el.classList.add('underline-message')
      } else if (t.startsWith('_')) {
        content = t.slice(1)
        el.classList.add('italic-message')
      } else if (t.startsWith('*')) {
        content = t.slice(1)
        el.classList.add('bold-message')
      } else if (isEmojiOnly(content)) {
        el.classList.add('emoji-only')
      }
      const parts = content.split(/(\[img:[a-f0-9]{64}])/)
      for (const part of parts) {
        const m = part.match(/^\[img:([a-f0-9]{64})]$/)
        if (m) {
          const img = createElement('img', {class: 'message-image', src: '?file=' + m[1], loading: 'lazy'})
          img.onload = () => {
            if (atBottom) scrollToBottom()
          }
          el.appendChild(img)
          el.appendChild(span({'style': 'display: none'}, text('[GIF]')))
        } else if (part) {
          el.appendChild(text(part))
        }
      }
      return el
    }

    function renderMessage(msg) {
      if (msg.kind === 'effect') {
        if (Math.abs(Date.now() - new Date(msg.created_at).getTime()) <= 2000) {
          const fx = msg.message
          if (fx === 'shake' || fx === 'flip' || fx === 'blur' || fx === 'disco' || fx === 'wave') {
            const cls = 'world-' + fx
            document.body.classList.add(cls)
            document.body.addEventListener('animationend', function handler(e) {
              if (e.target === document.body && e.animationName === cls) {
                document.body.classList.remove(cls)
                document.body.removeEventListener('animationend', handler)
              }
            })
          } else if (fx === 'blackout') {
            const overlay = document.createElement('div')
            overlay.className = 'blackout-overlay'
            document.body.appendChild(overlay)
            overlay.addEventListener('animationend', () => overlay.remove())
          } else if (fx === 'flash') {
            const overlay = document.createElement('div')
            overlay.className = 'flash-overlay'
            document.body.appendChild(overlay)
            overlay.addEventListener('animationend', () => overlay.remove())
          } else if (fx === 'matrix') {
            const c = document.createElement('canvas')
            c.className = 'matrix-canvas'
            c.width = window.innerWidth
            c.height = window.innerHeight
            document.body.appendChild(c)
            const ctx = c.getContext('2d')
            const cols = Math.floor(c.width / 14)
            const drops = Array(cols).fill(0)
            const chars = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン0123456789'
            const iv = setInterval(() => {
              ctx.fillStyle = 'rgba(0,0,0,0.05)'
              ctx.fillRect(0, 0, c.width, c.height)
              ctx.fillStyle = '#0f0'
              ctx.font = '14px monospace'
              for (let i = 0; i < cols; i++) {
                const ch = chars[Math.random() * chars.length | 0]
                ctx.fillText(ch, i * 14, drops[i] * 14)
                if (drops[i] * 14 > c.height && Math.random() > 0.975) drops[i] = 0
                drops[i]++
              }
            }, 40)
            setTimeout(() => {
              clearInterval(iv)
              c.remove()
            }, 20000)
          } else if (fx === 'confetti') {
            const colors = ['#ff0080', '#ff8c00', '#40e0d0', '#7b68ee', '#ff4444', '#44ff44', '#ffff00']
            for (let i = 0; i < 80; i++) {
              const p = document.createElement('div')
              p.className = 'confetti-piece'
              p.style.left = (Math.random() * 100) + 'vw'
              p.style.top = (-10 - Math.random() * 20) + 'px'
              p.style.background = colors[Math.random() * colors.length | 0]
              p.style.borderRadius = Math.random() > 0.5 ? '50%' : '0'
              p.style.width = (5 + Math.random() * 8) + 'px'
              p.style.height = (5 + Math.random() * 8) + 'px'
              p.style.animationDuration = (2 + Math.random() * 2) + 's'
              p.style.animationDelay = (Math.random() * 1.5) + 's'
              document.body.appendChild(p)
              p.addEventListener('animationend', () => p.remove())
            }
          } else if (fx === 'rain') {
            let spawned = 0
            const spawnRain = () => {
              if (spawned >= 120) return
              const d = document.createElement('div')
              d.className = 'rain-drop'
              d.style.left = (Math.random() * 100) + 'vw'
              d.style.top = (-10 - Math.random() * 30) + 'px'
              d.style.height = (15 + Math.random() * 25) + 'px'
              d.style.animationDuration = (0.4 + Math.random() * 0.4) + 's'
              document.body.appendChild(d)
              d.addEventListener('animationend', () => d.remove())
              spawned++
              setTimeout(spawnRain, 60)
            }
            spawnRain()
          } else if (fx === 'reload') {
            setTimeout(() => location.reload(), 2100)
          }
        }
        return null
      }
      if (msg.kind === 'delete') {
        for (const id of JSON.parse(msg.message)) {
          const el = messages.querySelector(`[data-id="${id}"]`)
          if (el) el.remove()
        }
        return null
      }
      if (msg.kind === 'system') {
        const sysEl = div({class: 'message system', 'data-id': msg.id},
          text(msg.message)
        )
        sysEl.appendChild(
          span({class: 'message-actions'},
            span({class: 'message-time'}, text(formatTime(msg.created_at)))
          )
        )
        return sysEl
      }
      const isMention = new RegExp('\\b' + myUsername + '\\b', 'i').test(msg.message)
      const isShadowBanned = msg.shadow_banned === true
      const el = div({
        class: 'message' + (isMention ? ' mention' : '') + (isShadowBanned ? ' shadow-banned' : ''),
        'data-id': msg.id
      })
      // Reply quote
      if (msg.reply_to) {
        const src = findReplySource(msg.reply_to)
        const quote = div({class: 'reply-quote', 'data-reply-to': msg.reply_to})
        if (src) {
          fillQuote(quote, src)
        } else {
          quote.append(text('...'))
          fetchReplySource(msg.reply_to, quote)
        }
        el.appendChild(quote)
      }
      el.append(
        span({class: 'message-actions'},
          span({class: 'message-time'}, text(formatTime(msg.created_at)))
        ),
        span({
          class: 'message-author',
          'data-username': msg.username, ...(msg.color ? {style: 'color:' + msg.color} : {})
        }, text(msg.username)),
        renderMessageText(' ' + msg.message),
      )
      return el
    }

    function rankIcon(rank) {
      if (rank < 1 || rank > 9) return null
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
      svg.setAttribute('class', 'rank-icon rank-' + rank)
      const title = document.createElementNS('http://www.w3.org/2000/svg', 'title')
      title.textContent = 'Rank ' + rank
      svg.appendChild(title)
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use')
      use.setAttribute('href', '#icon-rank-' + rank)
      svg.appendChild(use)
      return svg
    }

    const onlineUsers = new Set()
    const lastOnline = new Map()
    let firstPoll = true

    function addSystemMessage(msg) {
      const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})
      const el = div({class: 'message system'}, text(msg))
      el.appendChild(
        span({class: 'message-actions'},
          span({class: 'message-time'}, text(time))
        )
      )
      messages.appendChild(el)
      if (atBottom) scrollToBottom()
    }

    function renderUser(user, online, label) {
      return div({class: 'user'},
        span({class: 'status-dot' + (online ? ' online' : '')}),
        span(
          {class: 'user-name', 'data-username': user.username, ...(user.color ? {style: 'color:' + user.color} : {})},
          text(user.username),
          rankIcon(user.rank)
        ),
        span({class: 'user-status'}, text(label))
      )
    }

    function renderUsers(users) {
      const now = Date.now()
      const current = new Set()
      const top = []
      const bottom = []
      for (const u of users) {
        const ago = now - new Date(u.last_seen).getTime()
        if (ago <= 60000) {
          top.push({...u, ago})
          current.add(u.username)
        } else {
          bottom.push({...u, ago})
        }
      }
      if (!firstPoll) {
        for (const name of current) {
          if (!onlineUsers.has(name)) {
            const last = lastOnline.get(name)
            if (!last || now - last > 600000) addSystemMessage(name + ' joined')
          }
        }
      }
      firstPoll = false
      onlineUsers.clear()
      for (const name of current) {
        onlineUsers.add(name)
        lastOnline.set(name, now)
      }
      const newUsersList = div({class: 'users'})
      for (const u of top) {
        newUsersList.appendChild(renderUser(u, true, u.status || 'online'))
      }
      bottom.sort((a, b) => new Date(b.last_seen) - new Date(a.last_seen))
      for (const u of bottom) {
        const s = Math.round(u.ago / 1000)
        let label = s + 's ago'
        if (s >= 3600) label = Math.floor(s / 3600) + 'h ago'
        else if (s >= 60) label = Math.floor(s / 60) + 'm ago'
        newUsersList.appendChild(renderUser(u, false, label))
      }
      morph(usersList, newUsersList)
    }

    async function poll() {
      if (polling) return
      polling = true
      try {
        const res = await fetch('?api=messages&after=' + lastId)
        const data = await res.json()
        if (data.kicked) {
          if (data.error) alert(data.error)
          location.reload()
          return
        }
        if (data.messages.length) {
          for (const msg of data.messages) {
            const el = renderMessage(msg)
            if (el) messages.appendChild(el)
            lastId = msg.id
            if (!oldestId && msg.kind !== 'delete') oldestId = msg.id
          }
          if (atBottom) {
            scrollToBottom()
          } else {
            unread += data.messages.length
            badge.textContent = unread + ' new message' + (unread > 1 ? 's' : '')
            badge.style.display = 'block'
          }
        }
        renderUsers(data.users)
      } catch (e) {
        console.error(e)
      } finally {
        polling = false
      }
    }

    async function loadHistory() {
      if (!hasMore || !oldestId) return
      loadMore.textContent = 'Loading...'
      loadMore.disabled = true
      try {
        const res = await fetch('?api=history&before=' + oldestId)
        const data = await res.json()
        const scrollBefore = messages.scrollHeight
        let anchor = loadMore
        for (const msg of data.messages) {
          const el = renderMessage(msg)
          if (el) {
            anchor.after(el)
            anchor = el
          }
        }
        if (data.messages.length) oldestId = data.messages[0].id
        messages.scrollTop += messages.scrollHeight - scrollBefore
        hasMore = data.hasMore
        loadMore.style.display = hasMore ? '' : 'none'
      } catch (e) {
        console.error(e)
      }
      loadMore.textContent = 'Load older messages'
      loadMore.disabled = false
    }

    loadMore.addEventListener('click', loadHistory)

    form.addEventListener('submit', async (e) => {
      e.preventDefault()
      const txt = input.value.trim()
      if (!txt) return
      input.value = ''
      const body = new FormData()
      body.append('message', txt)
      body.append('csrf_token', csrfToken)
      if (replyTo) body.append('reply_to', replyTo)
      cancelReply()
      try {
        const res = await fetch('?api=send', {method: 'POST', body})
        const data = await res.json()
        if (data.error) addSystemMessage(data.error)
        await poll()
      } catch (e) {
        console.error(e)
      }
    })

    document.getElementById('file-input').addEventListener('change', async function () {
      const file = this.files[0]
      if (!file) return
      this.value = ''
      if (file.type !== 'image/gif') {
        addSystemMessage('Only GIF images are allowed.')
        return
      }
      if (file.size > 1024 * 1024) {
        addSystemMessage('File too large. Max 1MB.')
        return
      }
      const body = new FormData()
      body.append('image', file)
      body.append('csrf_token', csrfToken)
      if (replyTo) body.append('reply_to', replyTo)
      cancelReply()
      try {
        const res = await fetch('?api=send', {method: 'POST', body})
        const data = await res.json()
        if (data.error) addSystemMessage(data.error)
        await poll()
      } catch (e) {
        console.error(e)
      }
    })

    poll()
    setInterval(poll, 1000)

    function sendOffline() {
      const body = new FormData()
      body.append('csrf_token', csrfToken)
      navigator.sendBeacon('?api=offline', body)
    }

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') sendOffline()
    })
    window.addEventListener('beforeunload', sendOffline)
  </script>


<?php endif; ?>

<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
  <symbol id="icon-sun" viewBox="0 0 24 24">
    <circle cx="12" cy="12" r="5"/>
    <line x1="12" y1="1" x2="12" y2="3"/>
    <line x1="12" y1="21" x2="12" y2="23"/>
    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
    <line x1="1" y1="12" x2="3" y2="12"/>
    <line x1="21" y1="12" x2="23" y2="12"/>
    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
  </symbol>
  <symbol id="icon-moon" viewBox="0 0 24 24">
    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
  </symbol>
  <symbol id="icon-monitor" viewBox="0 0 24 24">
    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
    <line x1="8" y1="21" x2="16" y2="21"/>
    <line x1="12" y1="17" x2="12" y2="21"/>
  </symbol>
  <symbol id="icon-x" viewBox="0 0 24 24">
    <line x1="18" y1="6" x2="6" y2="18"/>
    <line x1="6" y1="6" x2="18" y2="18"/>
  </symbol>
  <symbol id="icon-menu" viewBox="0 0 24 24">
    <line x1="4" y1="6" x2="20" y2="6"/>
    <line x1="4" y1="12" x2="20" y2="12"/>
    <line x1="4" y1="18" x2="20" y2="18"/>
  </symbol>
  <symbol id="icon-image" viewBox="0 0 24 24">
    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
    <circle cx="8.5" cy="8.5" r="1.5"/>
    <polyline points="21 15 16 10 5 21"/>
  </symbol>
  <symbol id="icon-reply" viewBox="0 0 24 24">
    <polyline points="9 17 4 12 9 7"/>
    <path d="M20 18v-2a4 4 0 0 0-4-4H4"/>
  </symbol>
  <!-- Rank icons -->
  <symbol id="icon-rank-1" viewBox="0 0 24 24">
    <path d="M5 12l5 5l10 -10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
  </symbol>
  <symbol id="icon-rank-2" viewBox="0 0 24 24">
    <path d="M7 12l5 5l10 -10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
    <path d="M2 12l5 5m5 -5l5 -5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
  </symbol>
  <symbol id="icon-rank-3" viewBox="0 0 24 24">
    <path d="M8.56 3.69a9 9 0 0 0 -2.92 1.95" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
    <path d="M3.69 8.56a9 9 0 0 0 -.69 3.44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
    <path d="M3.69 15.44a9 9 0 0 0 1.95 2.92" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
    <path d="M8.56 20.31a9 9 0 0 0 3.44 .69" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
    <path d="M15.44 20.31a9 9 0 0 0 2.92 -1.95" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M20.31 15.44a9 9 0 0 0 .69 -3.44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
    <path d="M20.31 8.56a9 9 0 0 0 -1.95 -2.92" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M15.44 3.69a9 9 0 0 0 -3.44 -.69" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
    <path d="M9 12l2 2l4 -4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
  </symbol>
  <symbol id="icon-rank-4" viewBox="0 0 24 24">
    <path
      d="M5 7.2a2.2 2.2 0 0 1 2.2 -2.2h1a2.2 2.2 0 0 0 1.55 -.64l.7 -.7a2.2 2.2 0 0 1 3.12 0l.7 .7c.412 .41 .97 .64 1.55 .64h1a2.2 2.2 0 0 1 2.2 2.2v1c0 .58 .23 1.138 .64 1.55l.7 .7a2.2 2.2 0 0 1 0 3.12l-.7 .7a2.2 2.2 0 0 0 -.64 1.55v1a2.2 2.2 0 0 1 -2.2 2.2h-1a2.2 2.2 0 0 0 -1.55 .64l-.7 .7a2.2 2.2 0 0 1 -3.12 0l-.7 -.7a2.2 2.2 0 0 0 -1.55 -.64h-1a2.2 2.2 0 0 1 -2.2 -2.2v-1a2.2 2.2 0 0 0 -.64 -1.55l-.7 -.7a2.2 2.2 0 0 1 0 -3.12l.7 -.7a2.2 2.2 0 0 0 .64 -1.55v-1"
      fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M9 12l2 2l4 -4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round"/>
  </symbol>
  <symbol id="icon-rank-5" viewBox="0 0 24 24">
    <path
      d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873l-6.158 -3.245"
      fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="icon-rank-6" viewBox="0 0 24 24">
    <path
      d="M8.243 7.34l-6.38 .925l-.113 .023a1 1 0 0 0 -.44 1.684l4.622 4.499l-1.09 6.355l-.013 .11a1 1 0 0 0 1.464 .944l5.706 -3l5.693 3l.1 .046a1 1 0 0 0 1.352 -1.1l-1.091 -6.355l4.624 -4.5l.078 -.085a1 1 0 0 0 -.633 -1.62l-6.38 -.926l-2.852 -5.78a1 1 0 0 0 -1.794 0l-2.853 5.78z"
      fill="currentColor" stroke="none"/>
  </symbol>
  <symbol id="icon-rank-7" viewBox="0 0 24 24">
    <path
      d="M17.657 12.007a1.39 1.39 0 0 0 -1.103 .765l-.855 1.723l-1.907 .277c-.52 .072 -.96 .44 -1.124 .944l-.038 .14c-.1 .465 .046 .954 .393 1.29l1.377 1.337l-.326 1.892a1.393 1.393 0 0 0 2.018 1.465l1.708 -.895l1.708 .896a1.388 1.388 0 0 0 1.462 -.105l.112 -.09a1.39 1.39 0 0 0 .442 -1.272l-.325 -1.891l1.38 -1.339c.38 -.371 .516 -.924 .352 -1.427l-.051 -.134a1.39 1.39 0 0 0 -1.073 -.81l-1.907 -.278l-.853 -1.722a1.393 1.393 0 0 0 -1.247 -.773l-.143 .007z"
      fill="currentColor" stroke="none"/>
    <path
      d="M6.057 12.007a1.39 1.39 0 0 0 -1.103 .765l-.855 1.723l-1.907 .277c-.52 .072 -.96 .44 -1.124 .944l-.038 .14c-.1 .465 .046 .954 .393 1.29l1.377 1.337l-.326 1.892a1.393 1.393 0 0 0 2.018 1.465l1.708 -.895l1.708 .896a1.388 1.388 0 0 0 1.462 -.105l.112 -.09a1.39 1.39 0 0 0 .442 -1.272l-.324 -1.891l1.38 -1.339c.38 -.371 .516 -.924 .352 -1.427l-.051 -.134a1.39 1.39 0 0 0 -1.073 -.81l-1.908 -.279l-.853 -1.722a1.393 1.393 0 0 0 -1.247 -.772l-.143 .007z"
      fill="currentColor" stroke="none"/>
    <path
      d="M11.857 2.007a1.39 1.39 0 0 0 -1.103 .765l-.855 1.723l-1.907 .277c-.52 .072 -.96 .44 -1.124 .944l-.038 .14c-.1 .465 .046 .954 .393 1.29l1.377 1.337l-.326 1.892a1.393 1.393 0 0 0 2.018 1.465l1.708 -.894l1.709 .896a1.388 1.388 0 0 0 1.462 -.105l.112 -.09a1.39 1.39 0 0 0 .442 -1.272l-.325 -1.892l1.38 -1.339c.38 -.371 .516 -.924 .352 -1.427l-.051 -.134a1.39 1.39 0 0 0 -1.073 -.81l-1.908 -.279l-.853 -1.722a1.393 1.393 0 0 0 -1.247 -.772l-.143 .007z"
      fill="currentColor" stroke="none"/>
  </symbol>
  <symbol id="icon-rank-8" viewBox="0 0 24 24">
    <path
      d="M12 2.005c-.777 0 -1.508 .367 -1.971 .99l-5.362 6.895c-.89 1.136 -.89 3.083 0 4.227l5.375 6.911a2.457 2.457 0 0 0 3.93 -.017l5.361 -6.894c.89 -1.136 .89 -3.083 0 -4.227l-5.375 -6.911a2.446 2.446 0 0 0 -1.958 -.974z"
      fill="currentColor" stroke="none"/>
  </symbol>
  <symbol id="icon-rank-9" viewBox="0 0 24 24">
    <path
      d="M19 19h-14c-.5 0 -.9 -.3 -1 -.8l-2 -10c0 -.4 .1 -.8 .5 -1.1c.4 -.2 .8 -.2 1.1 0l4.1 3.3l3.4 -5.1c.4 -.6 1.3 -.6 1.7 0l3.4 5.1l4.1 -3.3c.3 -.3 .8 -.3 1.1 0c.4 .2 .5 .6 .5 1.1l-2 10c0 .5 -.5 .8 -1 .8z"
      fill="currentColor" stroke="none"/>
  </symbol>
</svg>
