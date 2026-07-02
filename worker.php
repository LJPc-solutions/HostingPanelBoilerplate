<?php

declare(strict_types=1);

use Panel\App;

require __DIR__ . '/vendor/autoload.php';

$config = panel_config(__DIR__);
$rootDir = __DIR__;

$handler = static function () use ($config, $rootDir): void {
    (new App($config, $rootDir))->handle();
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
