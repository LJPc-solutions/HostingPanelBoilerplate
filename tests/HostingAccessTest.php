<?php

declare(strict_types=1);

function test_hosting_ownership_scoping(): void
{
    $pdo = panel_test_pdo();
    $stmt = $pdo->prepare('INSERT INTO hosting (user_id, site_id, domain, package_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([1, 'site-abc', 'mine.nl', 'starter', 'active', date('Y-m-d H:i:s')]);
    $stmt->execute([2, 'site-def', 'theirs.nl', 'starter', 'active', date('Y-m-d H:i:s')]);

    $q = $pdo->prepare('SELECT * FROM hosting WHERE id = ? AND user_id = ?');
    $q->execute([1, 1]);
    assert($q->fetch() !== false);
    $q->execute([2, 1]);
    assert($q->fetch() === false);
}

function test_format_bytes(): void
{
    assert(format_bytes(0) === '0 B');
    assert(format_bytes(1024) === '1.0 KB');
    assert(format_bytes((int) (2.5 * 1024 ** 3)) === '2.5 GB');
}
