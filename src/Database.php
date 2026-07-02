<?php

declare(strict_types=1);

namespace Panel;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(array $dbConfig): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($dbConfig['driver'] === 'sqlite') {
            $path = $dbConfig['sqlite_path'];
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0770, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');

            return $pdo;
        }

        if ($dbConfig['driver'] === 'mysql') {
            $m = $dbConfig['mysql'];
            $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset=utf8mb4";

            return new PDO($dsn, $m['username'], $m['password'], $options);
        }

        throw new RuntimeException("Unknown database driver: {$dbConfig['driver']}");
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

        $statements = [
            "CREATE TABLE IF NOT EXISTS users (
                id {$id},
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id {$id},
                email VARCHAR(255) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                attempted_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS password_resets (
                id {$id},
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS contacts (
                id {$id},
                user_id INT NOT NULL,
                handle VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS domains (
                id {$id},
                user_id INT NOT NULL,
                domain VARCHAR(255) NOT NULL UNIQUE,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS hosting (
                id {$id},
                user_id INT NOT NULL,
                site_id VARCHAR(64) NULL,
                domain VARCHAR(255) NOT NULL,
                package_id VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS orders (
                id {$id},
                user_id INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                total_cents INT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                remote_order_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS order_items (
                id {$id},
                order_id INT NOT NULL,
                type VARCHAR(32) NOT NULL,
                data TEXT NOT NULL,
                price_cents INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                remote_ref VARCHAR(64) NULL
            )",
        ];

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }

        $indexes = [
            'idx_login_attempts' => ['login_attempts', 'email, ip, attempted_at'],
            'idx_contacts_user' => ['contacts', 'user_id'],
            'idx_domains_user' => ['domains', 'user_id'],
            'idx_hosting_user' => ['hosting', 'user_id'],
            'idx_orders_user' => ['orders', 'user_id'],
            'idx_order_items_order' => ['order_items', 'order_id'],
        ];

        foreach ($indexes as $name => [$table, $columns]) {
            if ($sqlite) {
                $pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");

                continue;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $stmt->execute([$table, $name]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("CREATE INDEX {$name} ON {$table} ({$columns})");
            }
        }
    }
}
