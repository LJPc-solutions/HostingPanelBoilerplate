<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\App;
use Panel\PasswordReset;
use Panel\Response;

final class PasswordController
{
    public function __construct(private readonly App $app)
    {
    }

    public function showForgot(array $params): Response
    {
        return Response::html($this->app->view->render('auth/forgot', ['title' => 'Reset password']));
    }

    public function sendLink(array $params): Response
    {
        $email = $_POST['email'] ?? '';
        $token = (new PasswordReset($this->app->pdo))->createToken($email);
        if ($token !== null) {
            $this->app->mailer->send(
                $email,
                'Reset your password',
                "Reset your password: {$this->app->config['base_url']}/password/reset/{$token}\n\nThis link expires in one hour."
            );
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'If that email exists, a reset link has been sent.'];

        return Response::redirect('/login');
    }

    public function showReset(array $params): Response
    {
        return Response::html($this->app->view->render('auth/reset', [
            'title' => 'Choose a new password',
            'token' => $params['token'],
        ]));
    }

    public function reset(array $params): Response
    {
        $ok = (new PasswordReset($this->app->pdo))->consume($params['token'], $_POST['password'] ?? '');
        if ($ok) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Your password has been changed, you can log in now.'];

            return Response::redirect('/login');
        }

        return Response::html($this->app->view->render('auth/reset', [
            'title' => 'Choose a new password',
            'token' => $params['token'],
            'error' => 'This reset link is invalid or expired, or the password is too short (minimum 10 characters).',
        ]));
    }
}
