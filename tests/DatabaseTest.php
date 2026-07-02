<?php

declare(strict_types=1);

use Panel\Database;

function panel_test_pdo(): PDO
{
    $pdo = Database::connect(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
    Database::migrate($pdo);

    return $pdo;
}

function test_migrate_creates_tables_idempotently(): void
{
    $pdo = panel_test_pdo();
    Database::migrate($pdo); // second run must not throw

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['users', 'login_attempts', 'password_resets', 'contacts', 'domains', 'hosting', 'orders', 'order_items'] as $table) {
        assert(in_array($table, $tables, true), "missing table {$table}");
    }
}

function test_users_roundtrip(): void
{
    $pdo = panel_test_pdo();
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute(['a@b.c', 'hash', 'Test', date('Y-m-d H:i:s')]);
    $row = $pdo->query('SELECT * FROM users')->fetch();
    assert($row['email'] === 'a@b.c');
    assert((int) $row['id'] === 1);
}
