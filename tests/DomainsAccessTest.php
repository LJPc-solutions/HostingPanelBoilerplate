<?php

declare(strict_types=1);

function test_domain_ownership_scoping(): void
{
    $pdo = panel_test_pdo();
    $stmt = $pdo->prepare('INSERT INTO domains (user_id, domain, status, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([1, 'mine.nl', 'active', date('Y-m-d H:i:s')]);
    $stmt->execute([2, 'theirs.nl', 'active', date('Y-m-d H:i:s')]);

    $q = $pdo->prepare('SELECT * FROM domains WHERE domain = ? AND user_id = ?');
    $q->execute(['mine.nl', 1]);
    assert($q->fetch() !== false);
    $q->execute(['theirs.nl', 1]);
    assert($q->fetch() === false);
}

function test_nameserver_validation_pattern(): void
{
    $valid = fn (string $ns): bool => (bool) preg_match('/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/i', $ns);
    assert($valid('ns1.example-dns.net'));
    assert(!$valid('not a hostname'));
    assert(!$valid('ns1'));
}
