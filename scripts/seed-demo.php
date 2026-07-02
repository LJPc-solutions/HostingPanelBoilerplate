<?php

declare(strict_types=1);

// Seeds a demo user (demo@example.com / demopassword1) with one active domain.
// Usage: ddev exec php scripts/seed-demo.php

require dirname(__DIR__) . '/vendor/autoload.php';

$config = panel_config(dirname(__DIR__));
$app = new Panel\App($config, dirname(__DIR__));

$app->api->registerDomain('demo.nl', 'MOCKDEMO', []);

$stmt = $app->pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute(['demo@example.com']);
if ($stmt->fetch() === false) {
    $stmt = $app->pdo->prepare('INSERT INTO users (email, password_hash, name, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute(['demo@example.com', password_hash('demopassword1', PASSWORD_DEFAULT), 'Demo', date('Y-m-d H:i:s')]);
}

$stmt = $app->pdo->prepare('SELECT id FROM domains WHERE domain = ?');
$stmt->execute(['demo.nl']);
if ($stmt->fetch() === false) {
    $stmt = $app->pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute(['demo@example.com']);
    $userId = (int) $stmt->fetchColumn();
    $stmt = $app->pdo->prepare('INSERT INTO domains (user_id, domain, status, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, 'demo.nl', 'active', date('Y-m-d H:i:s')]);
}

echo "Seeded demo@example.com / demopassword1 with domain demo.nl\n";
