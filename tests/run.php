<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

ini_set('assert.exception', '1');

if ((int) ini_get('zend.assertions') !== 1) {
    fwrite(STDERR, "zend.assertions must be 1. Run: php -d zend.assertions=1 tests/run.php\n");
    exit(2);
}

$failures = 0;
$passed = 0;

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_')) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "PASS {$fn}\n";
    } catch (Throwable $t) {
        $failures++;
        echo "FAIL {$fn}: {$t->getMessage()} ({$t->getFile()}:{$t->getLine()})\n";
    }
}

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
