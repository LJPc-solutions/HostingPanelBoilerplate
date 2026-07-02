<?php

declare(strict_types=1);

use Panel\Api\ApiException;
use Panel\Api\MockApiClient;

function panel_test_mock(): MockApiClient
{
    $dir = sys_get_temp_dir() . '/panel-mock-' . getmypid() . '-' . bin2hex(random_bytes(4));

    return new MockApiClient($dir);
}

function test_mock_check_is_deterministic(): void
{
    $mock = panel_test_mock();
    $a = $mock->checkDomain('example.com');
    $b = $mock->checkDomain('example.com');
    assert($a === $b);
    assert(is_bool($a['available']) && $a['price'] === 12.50);
    assert($mock->checkDomain('example.unknown-tld')['available'] === null);
}

function test_mock_domain_lifecycle(): void
{
    $mock = panel_test_mock();
    $res = $mock->registerDomain('mysite.nl', 'MOCKABC', ['external_reference' => '1']);
    assert($res['success'] === true);

    $domains = $mock->listDomains();
    assert(count($domains) === 1 && $domains[0]['domain'] === 'mysite.nl');

    $info = $mock->getDomain('mysite.nl');
    assert($info['status'] === 'active' && $info['auto_renew'] === true && $info['contact_handle'] === 'MOCKABC');

    $mock->updateDomain('mysite.nl', ['auto_renew' => false, 'nameservers' => ['ns1.other.net', 'ns2.other.net']]);
    $info = $mock->getDomain('mysite.nl');
    assert($info['auto_renew'] === false && $info['nameservers'] === ['ns1.other.net', 'ns2.other.net']);

    assert(strlen($mock->getAuthCode('mysite.nl')) === 8);

    $records = $mock->getDnsRecords('mysite.nl');
    assert(count($records) === 4);
    $records[] = ['type' => 'TXT', 'name' => 'x', 'content' => 'y', 'ttl' => 300, 'prio' => null];
    $mock->saveDnsRecords('mysite.nl', $records);
    assert(count($mock->getDnsRecords('mysite.nl')) === 5);

    $threw = false;
    try {
        $mock->updateDomain('unknown.nl', []);
    } catch (ApiException) {
        $threw = true;
    }
    assert($threw);
}

function test_mock_contacts_and_hosting(): void
{
    $mock = panel_test_mock();
    $handle = $mock->createContact([
        'name' => 'Test Person', 'organization' => null, 'address' => 'Street 1',
        'postal_code' => '1234 AB', 'city' => 'City', 'country' => 'NL',
        'email' => 'a@b.c', 'voice' => '+31612345678',
    ]);
    assert(str_starts_with($handle, 'MOCK'));
    assert($mock->getContact($handle)['name'] === 'Test Person');
    assert(count($mock->listContacts()) === 1);

    $order = $mock->createHosting('mysite.nl', 'hosting_5gb', ['external_reference' => '1']);
    assert(str_starts_with($order['order_id'], 'mock-'));
    $status = $mock->getOrderStatus($order['order_id']);
    assert(in_array($status['status'], ['processing', 'completed'], true));
}
