<?php

namespace App;

use PDO;

class Database
{
    private static ?PDO $db = null;
    private static ?PDO $filesDb = null;

    private const string SCHEMA = <<<SQL
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
    CREATE INDEX IF NOT EXISTS idx_users_last_seen ON users(last_seen);

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
    CREATE INDEX IF NOT EXISTS idx_messages_username ON messages(username);

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

    private const string FILES_SCHEMA = <<<SQL
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

    public static function get(): PDO
    {
        if (self::$db === null) {
            $dir = dirname(Config::DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            self::$db = new PDO('sqlite:' . Config::DB_PATH);
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$db->exec(self::SCHEMA);
        }
        return self::$db;
    }

    public static function getFiles(): PDO
    {
        if (self::$filesDb === null) {
            $dir = dirname(Config::FILES_DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            self::$filesDb = new PDO('sqlite:' . Config::FILES_DB_PATH);
            self::$filesDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$filesDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$filesDb->exec(self::FILES_SCHEMA);
        }
        return self::$filesDb;
    }
}
