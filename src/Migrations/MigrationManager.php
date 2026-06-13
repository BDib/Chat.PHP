<?php

namespace App\Migrations;

use App\Database;
use App\Config;

class MigrationManager
{
    public static function migrate(): void
    {
        $db = Database::get();
        $driver = Config::get('DB_DRIVER', 'sqlite');

        // Initial schema creation logic is already inside Database::get()
        // but we can add version-based migrations here later.

        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            ip VARCHAR(255) NOT NULL,
            attempts INTEGER DEFAULT 1,
            last_attempt TEXT NOT NULL,
            PRIMARY KEY (ip)
        )");
    }
}
