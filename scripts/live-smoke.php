<?php

declare(strict_types=1);

/**
 * Live smoke test for LjpcApiClient.
 *
 * Exercises every read/safe method against a real LJPc instance and prints
 * the results, then proves the built request for every write method WITHOUT
 * sending it (a local capture transport records method/url/body and returns a
 * canned 2xx). No registrar/order write is ever fired.
 *
 * Usage:
 *   php scripts/live-smoke.php [base_url] [api_key]
 *   LJPC_BASE_URL=... LJPC_API_KEY=... php scripts/live-smoke.php
 *
 * Optional fixtures (owned by the key's owner), with defaults for the local
 * ddev instance: LJPC_SITE_ID (2019), LJPC_ORDER_ID (114), LJPC_REAL_DOMAIN
 * (a real, resolvable domain for checkDomain), LJPC_UNKNOWN_DOMAIN (an unsupported
 * TLD, to prove the unknown-TLD degradation).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Panel\Api\ApiException;
use Panel\Api\LjpcApiClient;

$baseUrl = $argv[1] ?? getenv('LJPC_BASE_URL') ?: '';
$apiKey = $argv[2] ?? getenv('LJPC_API_KEY') ?: '';

if ($baseUrl === '' || $apiKey === '') {
    fwrite(STDERR, "Usage: php scripts/live-smoke.php <base_url> <api_key>\n");
    fwrite(STDERR, "   or: LJPC_BASE_URL=... LJPC_API_KEY=... php scripts/live-smoke.php\n");
    exit(2);
}

$siteId = getenv('LJPC_SITE_ID') ?: '2019';
$orderId = getenv('LJPC_ORDER_ID') ?: '114';
$realDomain = getenv('LJPC_REAL_DOMAIN') ?: 'ljpc-e2e-probe-xyz.nl';
$unknownDomain = getenv('LJPC_UNKNOWN_DOMAIN') ?: 'example.this-tld-does-not-exist';
$contactHandleForWrites = getenv('LJPC_CONTACT_HANDLE') ?: 'apitest-owned-0001';

function line(string $s = ''): void
{
    echo $s . "\n";
}

function jsonPretty(mixed $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Run a read call, print its result, and never abort the whole smoke on one failure. */
function reads(string $label, callable $fn): void
{
    try {
        $result = $fn();
        line('OK  ' . $label);
        line(jsonPretty($result));
    } catch (Throwable $e) {
        line('ERR ' . $label . ' -> ' . get_class($e) . ': ' . $e->getMessage());
    }
    line();
}

$client = new LjpcApiClient($baseUrl, $apiKey);

line('=== LJPc live smoke ===');
line('base_url: ' . $baseUrl);
line('api_key : ' . substr($apiKey, 0, 8) . '... (' . strlen($apiKey) . ' chars)');
line('fixtures: site_id=' . $siteId . ' order_id=' . $orderId . ' real=' . $realDomain . ' unknown=' . $unknownDomain);
line('time    : ' . date('c'));
line();
line('--- REAL read calls (sent to the live API) ---');
line();

reads('listTlds (assert some priced)', static function () use ($client): array {
    $tlds = $client->listTlds();
    $priced = array_filter($tlds, static fn (array $t): bool => ($t['price'] ?? null) !== null);
    if ($priced === []) {
        throw new RuntimeException('no priced TLDs returned');
    }

    return [
        'total' => count($tlds),
        'priced' => count($priced),
        'sample' => array_slice($tlds, 0, 3),
    ];
});

reads('checkDomain (real ' . $realDomain . ')', static fn (): array => $client->checkDomain($realDomain));

reads('checkDomain (unknown TLD ' . $unknownDomain . ', expect all null/false)', static function () use ($client, $unknownDomain): array {
    $res = $client->checkDomain($unknownDomain);
    if ($res !== ['available' => null, 'premium' => false, 'price' => null]) {
        throw new RuntimeException('unknown TLD did not degrade to the null shape: ' . jsonPretty($res));
    }

    return $res;
});

reads('listPackages (hosting products, float|null price)', static function () use ($client): array {
    $packages = $client->listPackages();
    foreach ($packages as $p) {
        $price = $p['wholesale_monthly_price'];
        if ($price !== null && !is_float($price)) {
            throw new RuntimeException('wholesale_monthly_price is neither float nor null for ' . ($p['category_identifier'] ?? '?'));
        }
    }

    return $packages;
});

reads('listContacts', static fn (): array => $client->listContacts());

reads('listDomains', static fn (): array => $client->listDomains());

reads('getOrderStatus (order ' . $orderId . ')', static fn (): array => $client->getOrderStatus($orderId));

reads('getHosting (site ' . $siteId . ')', static fn (): array => $client->getHosting($siteId));

// getDomain / getDnsRecords against the owned domains the API reports.
$ownedDomains = [];
try {
    $ownedDomains = array_column($client->listDomains(), 'domain');
} catch (Throwable $e) {
    line('ERR could not list owned domains for getDomain/getDnsRecords: ' . $e->getMessage());
    line();
}

foreach ($ownedDomains as $domain) {
    reads('getDomain (' . $domain . ')', static fn (): array => $client->getDomain($domain));
    reads('getDnsRecords (' . $domain . ', 404 -> [])', static fn (): array => $client->getDnsRecords($domain));
}

line('--- BOUNDARY write calls (request built, NOT sent) ---');
line();

/** @var list<array{method: string, url: string, body: ?array}> $captured */
$captured = [];
$captureTransport = static function (string $method, string $url, ?array $jsonBody, array $headers) use (&$captured): array {
    $captured[] = ['method' => $method, 'url' => $url, 'body' => $jsonBody];

    // createHosting resolves a category to a product_uuid via GET /v1/products
    // first; hand it a canned catalog so it can build the order line to capture.
    if (str_contains($url, '/v1/products')) {
        return ['status' => 200, 'body' => (string) json_encode(['products' => [[
            'uuid' => 'BOUNDARY-UUID-5GB',
            'name' => '5GB hosting',
            'billing_period_type' => 'client_choice',
            'category_identifier' => 'hosting_5gb',
        ]]])];
    }

    // A generic success envelope that satisfies every write-result / handle / order-id path.
    return ['status' => 200, 'body' => (string) json_encode([
        'success' => true,
        'jti' => 'capture',
        'data' => ['message' => 'captured (boundary, not sent)', 'handle' => 'captured-handle', 'order' => ['id' => 0]],
    ])];
};

$boundaryClient = new LjpcApiClient($baseUrl, $apiKey, $captureTransport);

$boundary = static function (string $label, callable $fn) use (&$captured): void {
    $before = count($captured);
    try {
        $fn();
    } catch (Throwable $e) {
        line('ERR ' . $label . ' -> ' . get_class($e) . ': ' . $e->getMessage());
    }
    line('BUILT ' . $label);
    foreach (array_slice($captured, $before) as $call) {
        line('  ' . $call['method'] . ' ' . $call['url']);
        if ($call['body'] !== null) {
            line('  body: ' . str_replace("\n", "\n  ", jsonPretty($call['body'])));
        }
    }
    line();
};

$boundary('registerDomain', static fn () => $boundaryClient->registerDomain(
    'boundary-smoke.nl',
    $contactHandleForWrites,
    ['years' => 2, 'nameservers' => ['ns1.ljpc.network', 'ns2.ljpc.network'], 'external_reference' => 'SMOKE-REG'],
));

$boundary('transferDomain', static fn () => $boundaryClient->transferDomain(
    'boundary-smoke.nl',
    'EPP-AUTH-CODE-EXAMPLE',
    $contactHandleForWrites,
    ['external_reference' => 'SMOKE-TRANSFER'],
));

$boundary('updateDomain', static fn () => $boundaryClient->updateDomain(
    'boundary-smoke.nl',
    ['auto_renew' => false, 'transfer_lock' => true, 'whois_contact_handle' => $contactHandleForWrites],
));

$boundary('createContact', static fn () => $boundaryClient->createContact([
    'name' => 'Boundary Smoke',
    'organization' => 'LJPc',
    'address' => 'Teststreet 1',
    'postal_code' => '1234AB',
    'city' => 'Testcity',
    'country' => 'NL',
    'email' => 'boundary@example.com',
    'voice' => '+31612345678',
]));

$boundary('updateContact', static fn () => $boundaryClient->updateContact(
    $contactHandleForWrites,
    ['city' => 'Newcity'],
));

$boundary('saveDnsRecords', static fn () => $boundaryClient->saveDnsRecords('boundary-smoke.nl', [
    ['type' => 'A', 'name' => '@', 'content' => '198.51.100.10', 'ttl' => 3600, 'prio' => null],
    ['type' => 'MX', 'name' => '@', 'content' => 'mail.boundary-smoke.nl', 'ttl' => 3600, 'prio' => 10],
]));

$boundary('createHosting', static fn () => $boundaryClient->createHosting(
    'boundary-smoke.nl',
    'hosting_5gb',
    ['external_reference' => 'SMOKE-HOSTING'],
));

line('=== live smoke complete ===');
line('Boundary calls captured: ' . count($captured) . ' (none sent to the live API).');
