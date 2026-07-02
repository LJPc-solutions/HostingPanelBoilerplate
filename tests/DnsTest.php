<?php

declare(strict_types=1);

use Panel\Dns;

function test_dns_validate_accepts_good_records(): void
{
    $res = Dns::validate([
        ['type' => 'A', 'name' => '@', 'content' => '198.51.100.10', 'ttl' => '3600', 'prio' => ''],
        ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com.', 'ttl' => '3600', 'prio' => '10'],
        ['type' => 'TXT', 'name' => '_dmarc', 'content' => 'v=DMARC1; p=none', 'ttl' => '3600', 'prio' => ''],
    ]);
    assert($res['ok'] === true && $res['errors'] === []);
    assert($res['records'][0]['ttl'] === 3600 && $res['records'][0]['prio'] === null);
    assert($res['records'][1]['prio'] === 10);
    assert($res['records'][1]['content'] === 'mail.example.com'); // trailing dot stripped
}

function test_dns_validate_rejects_bad_records(): void
{
    $res = Dns::validate([
        ['type' => 'A', 'name' => '@', 'content' => 'not-an-ip', 'ttl' => '3600', 'prio' => ''],
        ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'ttl' => '3600', 'prio' => ''],
        ['type' => 'X', 'name' => '@', 'content' => 'x', 'ttl' => '30', 'prio' => ''],
    ]);
    assert($res['ok'] === false);
    assert(count($res['errors']) >= 3); // bad IP, missing MX prio, bad type + bad TTL
}
