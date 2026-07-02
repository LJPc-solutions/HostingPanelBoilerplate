<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\App;
use Panel\Response;

final class HomeController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(array $params): Response
    {
        return Response::html($this->app->view->render('home', [
            'title' => $this->app->config['panel_name'],
            'packages' => $this->app->config['packages'],
        ]));
    }
}
