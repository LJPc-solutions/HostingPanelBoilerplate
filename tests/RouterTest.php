<?php

declare(strict_types=1);

use Panel\Response;
use Panel\Router;

function test_router_matches_static_and_param_routes(): void
{
    $router = new Router();
    $router->add('GET', '/domains', fn () => 'list');
    $router->add('GET', '/domains/{domain}/dns', fn () => 'dns');

    $hit = $router->match('GET', '/domains');
    assert($hit !== null && ($hit['handler'])() === 'list');
    assert($hit['params'] === []);

    $hit = $router->match('GET', '/domains/example.com/dns/');
    assert($hit !== null && ($hit['handler'])() === 'dns');
    assert($hit['params'] === ['domain' => 'example.com']);

    assert($router->match('POST', '/domains') === null);
    assert($router->match('GET', '/nope') === null);
    assert($router->match('GET', '/domains/a/b/dns') === null);
}

function test_response_factories(): void
{
    $r = Response::redirect('/login');
    assert($r->status === 302 && $r->headers['Location'] === '/login');

    $j = Response::json(['ok' => true], 201);
    assert($j->status === 201 && $j->body === '{"ok":true}');
    assert($j->headers['Content-Type'] === 'application/json');

    $h = Response::html('<p>x</p>');
    assert($h->status === 200 && str_contains($h->headers['Content-Type'], 'text/html'));
}
