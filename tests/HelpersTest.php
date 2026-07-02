<?php

declare(strict_types=1);

function test_e_escapes_html(): void
{
    assert(e('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');
    assert(e(null) === '');
}

function test_config_falls_back_to_example(): void
{
    $config = panel_config(dirname(__DIR__));
    assert(is_array($config));
    assert($config['db']['driver'] === 'sqlite');
    assert(array_is_list($config['packages']));
}
