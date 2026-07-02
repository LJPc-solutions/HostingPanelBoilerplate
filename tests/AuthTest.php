<?php

declare(strict_types=1);

use Panel\Auth;

/**
 * Simulates losing the registration create race: the uniqueness pre-check
 * sees no row even though one exists, so register() proceeds to INSERT and
 * hits the UNIQUE index (the exact concurrent-duplicate scenario).
 */
final class RaceSimulatingPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'SELECT id FROM users WHERE email = ?')) {
            $query = 'SELECT id FROM users WHERE 1 = 0 AND email = ?';
        }

        return parent::prepare($query, $options);
    }
}

function panel_test_auth(): Auth
{
    $_SESSION = [];

    return new Auth(panel_test_pdo());
}

function test_register_validates_and_creates_user(): void
{
    $auth = panel_test_auth();

    assert($auth->register('bad', 'longenoughpw', 'X')['ok'] === false);
    assert($auth->register('a@b.c', 'short', 'X')['ok'] === false);
    assert($auth->register('a@b.c', 'longenoughpw', '')['ok'] === false);

    $res = $auth->register('a@b.c', 'longenoughpw', 'Test');
    assert($res['ok'] === true && $res['user_id'] === 1);

    assert($auth->register('a@b.c', 'longenoughpw', 'Dup')['ok'] === false);
}

function test_login_and_throttle(): void
{
    $auth = panel_test_auth();
    $auth->register('a@b.c', 'longenoughpw', 'Test');

    assert($auth->attempt('a@b.c', 'wrong-password', '1.2.3.4')['ok'] === false);

    for ($i = 0; $i < 4; $i++) {
        $auth->attempt('a@b.c', 'wrong-password', '1.2.3.4');
    }
    $blocked = $auth->attempt('a@b.c', 'longenoughpw', '1.2.3.4');
    assert($blocked['ok'] === false && str_contains($blocked['error'], 'Too many attempts'));

    $ok = $auth->attempt('a@b.c', 'longenoughpw', '5.6.7.8');
    assert($ok['ok'] === true);
    assert($auth->user()['email'] === 'a@b.c');
}

function test_csrf_roundtrip(): void
{
    $auth = panel_test_auth();
    $token = $auth->csrfToken();
    assert(strlen($token) === 64);
    assert($auth->verifyCsrf($token) === true);
    assert($auth->verifyCsrf('nope') === false);
    assert($auth->verifyCsrf(null) === false);
}

function test_register_translates_lost_create_race(): void
{
    $pdo = new RaceSimulatingPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    Panel\Database::migrate($pdo);
    $_SESSION = [];
    $auth = new Panel\Auth($pdo);

    assert($auth->register('a@b.c', 'longenoughpw', 'First')['ok'] === true);
    $res = $auth->register('a@b.c', 'longenoughpw', 'Second');
    assert($res['ok'] === false && $res['error'] === 'An account with this email already exists.');
    assert($res['user_id'] === null);
}
