<?php

declare(strict_types=1);

use Panel\View;

function test_view_renders_template_inside_layout(): void
{
    $dir = sys_get_temp_dir() . '/panel-view-' . getmypid();
    @mkdir($dir);
    file_put_contents($dir . '/layout.php', '<html><title><?= e($title ?? "Panel") ?></title><?= $content ?></html>');
    file_put_contents($dir . '/hello.php', '<p>Hello <?= e($name) ?></p>');

    $view = new View($dir, ['config' => ['panel_name' => 'Panel']]);
    $html = $view->render('hello', ['name' => '<x>', 'title' => 'Hi']);

    assert(str_contains($html, '<p>Hello &lt;x&gt;</p>'));
    assert(str_contains($html, '<title>Hi</title>'));
}
