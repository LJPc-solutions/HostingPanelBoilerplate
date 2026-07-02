<?php

declare(strict_types=1);

function test_contact_ownership_scoping(): void
{
    $pdo = panel_test_pdo();
    $stmt = $pdo->prepare('INSERT INTO contacts (user_id, handle, name, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([1, 'MOCKAAA', 'Mine', date('Y-m-d H:i:s')]);
    $stmt->execute([2, 'MOCKBBB', 'Theirs', date('Y-m-d H:i:s')]);

    $q = $pdo->prepare('SELECT * FROM contacts WHERE id = ? AND user_id = ?');
    $q->execute([1, 1]);
    assert($q->fetch() !== false);
    $q->execute([2, 1]);
    assert($q->fetch() === false); // other user's contact invisible
}
