<?php

declare(strict_types=1);

use Panel\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(panel_config(dirname(__DIR__)), dirname(__DIR__));
$app->handle();
