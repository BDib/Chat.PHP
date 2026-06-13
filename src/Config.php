<?php

namespace App;

use Dotenv\Dotenv;

class Config
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) return;
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->safeLoad();
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        // Check environment variables first, then $_ENV, then default
        return $_ENV[$key] ?? (getenv($key) ?: $default);
    }

    public static function dbPath(string $key = 'DB_PATH'): string
    {
        $path = self::get($key);
        if (!$path) {
            $path = ($key === 'DB_PATH') ? 'db/chat.db' : 'db/files.db';
        }

        if (str_starts_with($path, '/')) return $path;
        return __DIR__ . '/../' . $path;
    }

    public const string WORDS_PATH = __DIR__ . '/../words.txt';
    public const string BANNED_NAMES_PATH = __DIR__ . '/../banned_names.txt';
    public const string BAD_WORDS_PATH = __DIR__ . '/../bad_words.txt';
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s\Z';
}
