<?php

declare(strict_types=1);

use Panel\Auth;
use Panel\Mail\LogMailer;
use Panel\PasswordReset;

function test_password_reset_roundtrip(): void
{
    $pdo = panel_test_pdo();
    $_SESSION = [];
    $auth = new Auth($pdo);
    $auth->register('a@b.c', 'longenoughpw', 'Test');

    $pr = new PasswordReset($pdo);
    assert($pr->createToken('unknown@b.c') === null);

    $token = $pr->createToken('a@b.c');
    assert(is_string($token) && strlen($token) === 64);

    assert($pr->consume($token, 'short') === false);
    assert($pr->consume('wrong-token', 'anotherlongpw') === false);
    assert($pr->consume($token, 'anotherlongpw') === true);
    assert($pr->consume($token, 'anotherlongpw') === false); // single use

    assert($auth->attempt('a@b.c', 'anotherlongpw', '9.9.9.9')['ok'] === true);
}

function test_log_mailer_appends(): void
{
    $file = sys_get_temp_dir() . '/panel-mail-' . getmypid() . '.log';
    @unlink($file);
    $mailer = new LogMailer($file, 'noreply@example.com');
    $mailer->send('a@b.c', 'Reset your password', 'Body');
    $log = file_get_contents($file);
    assert(str_contains($log, 'to=a@b.c') && str_contains($log, 'Reset your password'));
}
