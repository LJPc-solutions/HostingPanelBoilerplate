<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\App;
use Panel\Response;

final class AuthController
{
    public function __construct(private readonly App $app)
    {
    }

    public function showRegister(array $params): Response
    {
        return Response::html($this->app->view->render('auth/register', ['title' => 'Register']));
    }

    public function register(array $params): Response
    {
        $res = $this->app->auth->register($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['name'] ?? '');
        if (!$res['ok']) {
            return Response::html($this->app->view->render('auth/register', [
                'title' => 'Register',
                'error' => $res['error'],
                'old' => ['email' => $_POST['email'] ?? '', 'name' => $_POST['name'] ?? ''],
            ]));
        }
        $this->app->auth->attempt($_POST['email'], $_POST['password'], $_SERVER['REMOTE_ADDR'] ?? '');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Welcome! Your account has been created.'];

        return Response::redirect('/dashboard');
    }

    public function showLogin(array $params): Response
    {
        return Response::html($this->app->view->render('auth/login', ['title' => 'Log in']));
    }

    public function login(array $params): Response
    {
        $res = $this->app->auth->attempt($_POST['email'] ?? '', $_POST['password'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
        if (!$res['ok']) {
            return Response::html($this->app->view->render('auth/login', [
                'title' => 'Log in',
                'error' => $res['error'],
                'old' => ['email' => $_POST['email'] ?? ''],
            ]));
        }

        return Response::redirect('/dashboard');
    }

    public function logout(array $params): Response
    {
        $this->app->auth->logout();

        return Response::redirect('/');
    }
}
