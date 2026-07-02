<?php

declare(strict_types=1);

namespace Panel\Controllers;

use PDOException;
use Panel\App;
use Panel\Response;

final class AccountController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(array $params): Response
    {
        return Response::html($this->app->view->render('account', [
            'title' => 'Account',
            'old' => [],
        ]));
    }

    public function update(array $params): Response
    {
        $user = $this->user();
        $data = $this->validate($_POST, $user);
        if (is_string($data)) {
            return Response::html($this->app->view->render('account', [
                'title' => 'Account',
                'old' => $_POST,
                'error' => $data,
            ]));
        }

        try {
            if ($data['password'] !== null) {
                $stmt = $this->app->pdo->prepare('UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?');
                $stmt->execute([$data['name'], $data['email'], password_hash($data['password'], PASSWORD_DEFAULT), $user['id']]);
            } else {
                $stmt = $this->app->pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                $stmt->execute([$data['name'], $data['email'], $user['id']]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // unique constraint: lost a create race for this email
                return Response::html($this->app->view->render('account', [
                    'title' => 'Account',
                    'old' => $_POST,
                    'error' => 'An account with this email already exists.',
                ]));
            }

            throw $e;
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Account updated.'];

        return Response::redirect('/account');
    }

    /** @return array{name: string, email: string, password: ?string}|string data on success, error message on failure */
    private function validate(array $input, array $user): array|string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $newPassword = (string) ($input['password'] ?? '');
        $currentPassword = (string) ($input['current_password'] ?? '');

        if ($name === '') {
            return 'Please enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }

        $stmt = $this->app->pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch() !== false) {
            return 'An account with this email already exists.';
        }

        if ($newPassword === '') {
            return ['name' => $name, 'email' => $email, 'password' => null];
        }

        if (strlen($newPassword) < 10) {
            return 'Password must be at least 10 characters.';
        }
        if (!password_verify($currentPassword, $user['password_hash'])) {
            return 'Current password is incorrect.';
        }

        return ['name' => $name, 'email' => $email, 'password' => $newPassword];
    }

    private function user(): array
    {
        return $this->app->auth->user();
    }
}
