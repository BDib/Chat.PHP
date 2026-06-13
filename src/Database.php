<?php

namespace App;

use PDO;
use Exception;

class Database
{
    private static ?PDO $db = null;
    private static ?PDO $filesDb = null;

    private static function getSchema(string $driver): string
    {
        $isSqlite = $driver === 'sqlite';
        $isPgsql = $driver === 'pgsql';
        $isMysql = $driver === 'mysql';

        $pk = 'PRIMARY KEY';
        $text = $isSqlite ? 'TEXT' : 'VARCHAR(255)';
        $longText = $isSqlite ? 'TEXT' : ($isPgsql ? 'TEXT' : 'LONGTEXT');
        $blob = $isSqlite ? 'BLOB' : ($isPgsql ? 'BYTEA' : 'LONGBLOB');

        $autoInc = '';
        if ($isSqlite) $autoInc = 'AUTOINCREMENT';
        elseif ($isMysql) $autoInc = 'AUTO_INCREMENT';

        $idType = 'INTEGER';
        if ($isPgsql) {
            $idType = 'SERIAL';
        }

        $schema = "";
        if ($isSqlite) {
            $schema .= "PRAGMA journal_mode = WAL;
                        PRAGMA busy_timeout = 5000;
                        PRAGMA synchronous = NORMAL;
                        PRAGMA cache_size = -64000;
                        PRAGMA foreign_keys = true;
                        PRAGMA temp_store = memory;";
        }

        $schema .= "
        CREATE TABLE IF NOT EXISTS users (
            id $idType $pk $autoInc,
            username $text NOT NULL,
            password_hash $text NOT NULL,
            created_at $text NOT NULL,
            last_seen $text NOT NULL,
            last_ip $text NOT NULL,
            role $text NOT NULL DEFAULT 'user',
            kicked_until $text,
            muted_until $text,
            banned INTEGER NOT NULL DEFAULT 0,
            rank INTEGER NOT NULL DEFAULT 0,
            color $text DEFAULT NULL,
            status $text DEFAULT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_users_name ON users(username);
        CREATE INDEX IF NOT EXISTS idx_users_last_seen ON users(last_seen);

        CREATE TABLE IF NOT EXISTS messages (
            id $idType $pk $autoInc,
            username $text NOT NULL,
            message $longText NOT NULL,
            created_at $text NOT NULL,
            ip $text NOT NULL,
            kind $text NOT NULL DEFAULT 'text',
            reply_to INTEGER,
            color $text DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at);
        CREATE INDEX IF NOT EXISTS idx_messages_username ON messages(username);

        CREATE TABLE IF NOT EXISTS ip_bans (
            ip $text $pk,
            username $text NOT NULL,
            banned_by $text NOT NULL,
            created_at $text NOT NULL
        );

        CREATE TABLE IF NOT EXISTS shadow_bans (
            id $idType $pk $autoInc,
            username $text NOT NULL,
            ip $text NOT NULL,
            banned_by $text NOT NULL,
            created_at $text NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_shadow_bans_username ON shadow_bans(username);
        CREATE INDEX IF NOT EXISTS idx_shadow_bans_ip ON shadow_bans(ip);

        CREATE TABLE IF NOT EXISTS cooldowns (
            user_id INTEGER NOT NULL,
            action $text NOT NULL,
            used_at $text NOT NULL,
            PRIMARY KEY (user_id, action)
        );

        CREATE TABLE IF NOT EXISTS sessions (
            token $text $pk,
            user_id INTEGER,
            csrf_token $text NOT NULL,
            created_at $text NOT NULL,
            expires_at $text NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);
        ";

        return $schema;
    }

    private static function getFilesSchema(string $driver): string
    {
        $isSqlite = $driver === 'sqlite';
        $isPgsql = $driver === 'pgsql';
        $isMysql = $driver === 'mysql';

        $pk = 'PRIMARY KEY';
        $text = $isSqlite ? 'TEXT' : 'VARCHAR(255)';
        $blob = $isSqlite ? 'BLOB' : ($isPgsql ? 'BYTEA' : 'LONGBLOB');

        $autoInc = '';
        if ($isSqlite) $autoInc = 'AUTOINCREMENT';
        elseif ($isMysql) $autoInc = 'AUTO_INCREMENT';

        $idType = 'INTEGER';
        if ($isPgsql) {
            $idType = 'SERIAL';
        }

        $schema = "";
        if ($isSqlite) {
            $schema .= "PRAGMA journal_mode = WAL;
                        PRAGMA busy_timeout = 5000;
                        PRAGMA synchronous = NORMAL;";
        }

        $schema .= "
        CREATE TABLE IF NOT EXISTS files (
            id $idType $pk $autoInc,
            hash $text NOT NULL,
            data $blob NOT NULL,
            mime $text NOT NULL,
            size INTEGER NOT NULL,
            username $text NOT NULL,
            created_at $text NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_files_hash ON files(hash);
        ";
        return $schema;
    }

    public static function get(): PDO
    {
        if (self::$db === null) {
            $driver = Config::get('DB_DRIVER', 'sqlite');
            $dsn = self::getDSN($driver, 'DB_PATH', 'DB_NAME');

            self::$db = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'));
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $schema = self::getSchema($driver);
            if ($driver === 'sqlite') {
                self::$db->exec($schema);
            } else {
                // Split queries for drivers that don't support multi-query in exec
                foreach (explode(';', $schema) as $query) {
                    $query = trim($query);
                    if ($query) self::$db->exec($query);
                }
            }
        }
        return self::$db;
    }

    public static function getFiles(): PDO
    {
        if (self::$filesDb === null) {
            $driver = Config::get('DB_DRIVER', 'sqlite');
            $dsn = self::getDSN($driver, 'FILES_DB_PATH', 'FILES_DB_NAME');

            self::$filesDb = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'));
            self::$filesDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$filesDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $schema = self::getFilesSchema($driver);
            if ($driver === 'sqlite') {
                self::$filesDb->exec($schema);
            } else {
                foreach (explode(';', $schema) as $query) {
                    $query = trim($query);
                    if ($query) self::$filesDb->exec($query);
                }
            }
        }
        return self::$filesDb;
    }

    private static function getDSN(string $driver, string $pathKey, string $nameKey): string
    {
        return match ($driver) {
            'sqlite' => (function() use ($pathKey) {
                $path = Config::dbPath($pathKey);
                $dir = dirname($path);
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                return 'sqlite:' . $path;
            })(),
            'mysql' => sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                Config::get('DB_HOST', 'localhost'),
                Config::get('DB_PORT', '3306'),
                Config::get($nameKey, Config::get('DB_NAME', 'chat'))
            ),
            'pgsql' => sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                Config::get('DB_HOST', 'localhost'),
                Config::get('DB_PORT', '5432'),
                Config::get($nameKey, Config::get('DB_NAME', 'chat'))
            ),
            default => throw new Exception("Unsupported DB driver: $driver"),
        };
    }
}
