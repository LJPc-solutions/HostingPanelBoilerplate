<?php

declare(strict_types=1);

use Panel\Api\ApiException;
use Panel\Api\LjpcApiClient;

/**
 * Records every transport call and dispatches to a per-test handler.
 * Acts as the injected transport seam so no network is ever touched.
 */
final class LjpcFakeTransport
{
    /** @var list<array{method: string, url: string, body: ?array, headers: array, timeout: int}> */
    public array $calls = [];

    /** @var callable */
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    /** @return array{status: int, body: string} */
    public function __invoke(string $method, string $url, ?array $jsonBody, array $headers, int $timeout = 0): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $jsonBody, 'headers' => $headers, 'timeout' => $timeout];

        return ($this->handler)($method, $url, $jsonBody, $headers);
    }
}

function ljpc_ok(mixed $data): array
{
    return ['status' => 200, 'body' => (string) json_encode(['success' => true, 'jti' => 'jti-1', 'data' => $data])];
}

function ljpc_created(mixed $data): array
{
    return ['status' => 201, 'body' => (string) json_encode(['success' => true, 'jti' => 'jti-1', 'data' => $data])];
}

function ljpc_err(int $status, string $message): array
{
    return ['status' => $status, 'body' => (string) json_encode(['success' => false, 'jti' => 'jti-1', 'message' => $message])];
}

function ljpc_raw(int $status, mixed $payload): array
{
    return ['status' => $status, 'body' => (string) json_encode($payload)];
}

function ljpc_client(LjpcFakeTransport $t): LjpcApiClient
{
    return new LjpcApiClient('https://api.test/api', 'secret-key', $t);
}

/** @return array{0: bool, 1: string} */
function ljpc_catch(callable $fn): array
{
    try {
        $fn();
    } catch (ApiException $e) {
        return [true, $e->getMessage()];
    }

    return [false, ''];
}

// --- Note 1: envelope unwrap ---------------------------------------------------

function test_ljpc_listtlds_unwraps_envelope_and_keeps_null_price(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok([
        ['tld' => 'nl', 'price' => 3.88, 'currency' => 'EUR', 'transfer_supported' => true],
        ['tld' => 'weird', 'price' => null, 'currency' => 'EUR', 'transfer_supported' => false],
    ]));
    $tlds = ljpc_client($t)->listTlds();

    assert(count($tlds) === 2);
    assert($tlds[0]['tld'] === 'nl' && $tlds[0]['price'] === 3.88);
    assert($tlds[1]['price'] === null);
    // Correct base path used (base_url already ends in /api).
    assert(str_ends_with($t->calls[0]['url'], '/api/hosting/v1/tlds'));
    // Auth header carried on every call.
    assert(($t->calls[0]['headers']['X-API-Key'] ?? null) === 'secret-key');
}

// --- Notes 1/2: error mapping (500 status and success:false flag) --------------

function test_ljpc_500_maps_to_apiexception_with_envelope_message(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_err(500, 'Internal boom'));
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->listTlds());

    assert($threw === true);
    assert($msg === 'Internal boom');
    // Never leak URL or key.
    assert(!str_contains($msg, 'api.test'));
    assert(!str_contains($msg, 'secret-key'));
}

function test_ljpc_success_false_flag_maps_to_apiexception(): void
{
    // 200 status but success:false must still throw with the envelope message.
    $t = new LjpcFakeTransport(fn () => ljpc_err(200, 'Not allowed'));
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->listTlds());

    assert($threw === true && $msg === 'Not allowed');
}

// --- Note 3: checkDomain is two calls, merged ----------------------------------

function test_ljpc_checkdomain_merges_check_and_price(): void
{
    $t = new LjpcFakeTransport(function (string $m, string $url): array {
        if (str_contains($url, '/domain-names/check')) {
            return ljpc_ok(['ljpc-probe.nl' => 'available']);
        }

        return ljpc_ok(['ljpc-probe.nl' => [
            'premium' => true,
            'price' => ['amount' => '11.23', 'currency' => 'EUR'],
            'price_eur' => ['amount' => '11.23', 'currency' => 'EUR'],
        ]]);
    });
    $res = ljpc_client($t)->checkDomain('ljpc-probe.nl');

    assert($res['available'] === true);
    assert($res['premium'] === true);
    assert($res['price'] === 11.23);
    // Two distinct endpoint calls.
    assert(count($t->calls) === 2);
    assert(str_contains($t->calls[0]['url'], '/domain-names/check?domain=ljpc-probe.nl'));
    assert(str_contains($t->calls[1]['url'], '/domain-names/price?domain=ljpc-probe.nl'));
}

function test_ljpc_checkdomain_unknown_tld_yields_nulls(): void
{
    // The API now answers an unsupported/unregistrable TLD with a clean 200:
    // availability string "unknown" and a null price. That must read as unknown
    // (available null), never as taken, and must not throw.
    $t = new LjpcFakeTransport(function (string $m, string $url): array {
        if (str_contains($url, '/domain-names/check')) {
            return ljpc_ok(['weird.unknowntld' => 'unknown']);
        }

        return ljpc_ok(['weird.unknowntld' => null]);
    });
    $res = ljpc_client($t)->checkDomain('weird.unknowntld');

    assert($res['available'] === null);
    assert($res['premium'] === false);
    assert($res['price'] === null);
}

// Availability-string mapping: "available" -> true; a definitive taken/registered
// allow-list -> false; everything else (including an unrecognized status like
// "reserved") -> null, fail-safe. Null price is tolerated.
function test_ljpc_checkdomain_maps_availability_string(): void
{
    $cases = [
        'available' => true,
        'taken' => false,
        'registered' => false,
        'unavailable' => false,
        'active' => false,
        'unknown' => null,
        'invalid' => null,
        'error' => null,
        // Unrecognized status must fail safe to null (unknown), never taken.
        'reserved' => null,
    ];
    foreach ($cases as $verdict => $expected) {
        $t = new LjpcFakeTransport(function (string $m, string $url) use ($verdict): array {
            if (str_contains($url, '/domain-names/check')) {
                return ljpc_ok(['probe.nl' => $verdict]);
            }

            return ljpc_ok(['probe.nl' => null]); // price absent must be tolerated
        });
        $res = ljpc_client($t)->checkDomain('probe.nl');

        assert($res['available'] === $expected);
        assert($res['price'] === null);
        assert($res['premium'] === false);
    }
}

// The API returns a clean 200 for an unknown TLD now, so a 5xx genuinely means the
// service is down. checkDomain must propagate it, never mask it as "unknown".
function test_ljpc_checkdomain_5xx_propagates_apiexception(): void
{
    $t = new LjpcFakeTransport(fn (): array => ljpc_err(500, 'Server Error'));
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->checkDomain('example.nl'));

    assert($threw === true);
    assert($msg === 'Server Error');
}

function test_ljpc_checkdomain_html_500_propagates_apiexception(): void
{
    // A raw HTML 500 body (json_decode -> null) is still a real outage and must throw.
    $t = new LjpcFakeTransport(fn (): array => ['status' => 500, 'body' => '<!DOCTYPE html><html>Server Error</html>']);
    [$threw] = ljpc_catch(fn () => ljpc_client($t)->checkDomain('example.nl'));

    assert($threw === true);
}

function test_ljpc_checkdomain_transport_timeout_propagates_apiexception(): void
{
    // A slow/unreachable lookup surfaces as the transport ApiException and must
    // propagate so the panel can show a friendly outage page, not a fake verdict.
    $t = new LjpcFakeTransport(function (): array {
        throw new ApiException('The hosting service is unreachable, please try again.');
    });
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->checkDomain('example.nl'));

    assert($threw === true);
    assert($msg === 'The hosting service is unreachable, please try again.');
}

function test_ljpc_checkdomain_permission_403_still_throws(): void
{
    // A 4xx (for example a missing "Check domain availability" permission) is a real
    // caller error and must surface rather than silently showing every domain unknown.
    $t = new LjpcFakeTransport(fn (): array => ljpc_err(403, 'You do not have permission to check domain availability.'));
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->checkDomain('example.nl'));

    assert($threw === true);
    assert($msg === 'You do not have permission to check domain availability.');
}

// checkDomain's two reads use the shorter interactive timeout (8s) so a slow
// registrar cannot stall the page; writes keep the longer default (20s).
function test_ljpc_checkdomain_uses_shorter_interactive_timeout(): void
{
    $t = new LjpcFakeTransport(function (string $m, string $url): array {
        if (str_contains($url, '/domain-names/check')) {
            return ljpc_ok(['probe.nl' => 'available']);
        }

        return ljpc_ok(['probe.nl' => ['premium' => false, 'price_eur' => ['amount' => '11.23']]]);
    });
    ljpc_client($t)->checkDomain('probe.nl');

    assert($t->calls[0]['timeout'] === 8);
    assert($t->calls[1]['timeout'] === 8);

    // A write keeps the longer default timeout.
    $t2 = new LjpcFakeTransport(fn (): array => ljpc_ok(['domain' => 'x.nl', 'message' => 'Domain updated successfully']));
    ljpc_client($t2)->updateDomain('x.nl', ['auto_renew' => false]);
    assert($t2->calls[0]['timeout'] === 20);
}

// --- Note 4: listPackages raw + filter + rename --------------------------------

function test_ljpc_listpackages_raw_filters_and_renames(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_raw(200, ['products' => [
        ['uuid' => 'u1', 'name' => '5GB hosting', 'billing_period_type' => 'client_choice',
            'monthly_price' => '4.99', 'yearly_price' => '49.90', 'category' => 'hosting',
            'category_identifier' => 'hosting_5gb'],
        ['uuid' => 'u2', 'name' => 'Domein .media', 'billing_period_type' => 'yearly_only',
            'monthly_price' => null, 'yearly_price' => '44.99', 'category' => 'domains',
            'category_identifier' => 'media'],
        ['uuid' => 'u3', 'name' => 'DNS hosting', 'billing_period_type' => 'client_choice',
            'monthly_price' => null, 'category' => 'hosting', 'category_identifier' => 'hosting_dns'],
        ['uuid' => 'u4', 'name' => 'Forward hosting', 'billing_period_type' => 'client_choice',
            'monthly_price' => '1.50', 'category' => 'hosting', 'category_identifier' => 'hosting_forward'],
    ]]));
    $pkgs = ljpc_client($t)->listPackages();

    // Domain product filtered out; three hosting packages remain.
    $ids = array_column($pkgs, 'category_identifier');
    assert($ids === ['hosting_5gb', 'hosting_dns', 'hosting_forward']);
    assert($pkgs[0]['wholesale_monthly_price'] === 4.99); // renamed + cast to float
    assert($pkgs[1]['wholesale_monthly_price'] === null); // null preserved
    assert(!array_key_exists('monthly_price', $pkgs[0]));
    // Raw endpoint (no envelope) at /v1/products.
    assert(str_ends_with($t->calls[0]['url'], '/api/v1/products'));
}

// --- Note 5: createHosting resolves uuid, stringifies order id -----------------

function test_ljpc_createhosting_resolves_uuid_and_stringifies_order_id(): void
{
    $orderBody = null;
    $t = new LjpcFakeTransport(function (string $m, string $url, ?array $body) use (&$orderBody): array {
        if (str_contains($url, '/v1/products')) {
            return ljpc_raw(200, ['products' => [
                ['uuid' => 'uuid-5gb', 'name' => '5GB hosting', 'billing_period_type' => 'client_choice',
                    'monthly_price' => '4.99', 'category_identifier' => 'hosting_5gb'],
            ]]);
        }
        $orderBody = $body;

        return ljpc_created(['order' => ['id' => 114, 'status' => 'pending', 'total_price' => 4.99]]);
    });
    $res = ljpc_client($t)->createHosting('mysite.nl', 'hosting_5gb', ['external_reference' => 'REF-1', 'contact_handle' => 'ignored']);

    assert($res['order_id'] === '114'); // int -> string
    // Products consulted first, then the order posted.
    assert(str_contains($t->calls[0]['url'], '/v1/products'));
    assert($t->calls[1]['method'] === 'POST' && str_contains($t->calls[1]['url'], '/v1/orders'));
    // One hosting line with the resolved uuid; billing_period set for client_choice.
    $line = $orderBody['order_lines'][0]['cart_item'];
    assert($line['type'] === 'hosting');
    assert($line['product_uuid'] === 'uuid-5gb');
    assert($line['domain'] === 'mysite.nl');
    assert($line['billing_period'] === 'monthly');
    // contact_handle hint does not leak into a hosting line.
    assert(!array_key_exists('contact_handle', $line));
    // external_reference forwarded via meta.
    assert($orderBody['meta']['external_reference'] === 'REF-1');
}

function test_ljpc_createhosting_unknown_category_throws(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_raw(200, ['products' => []]));
    [$threw] = ljpc_catch(fn () => ljpc_client($t)->createHosting('mysite.nl', 'hosting_5gb'));

    assert($threw === true);
}

// --- Note 6: getContact field renames ------------------------------------------

function test_ljpc_getcontact_renames_and_drops_fields(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok([
        'brand' => 'default', 'name' => 'Owned Contact', 'addressLine' => ['Teststreet 1'],
        'postalCode' => '1234AB', 'city' => 'Testcity', 'country' => 'NL',
        'email' => 'owned@example.com', 'voice' => '+31.612345678', 'organization' => 'LJPc',
        'handle' => 'apitest-owned-0001', 'customer' => 'apitest', 'registries' => [],
        'properties' => [], 'validations' => [],
    ]));
    $c = ljpc_client($t)->getContact('apitest-owned-0001');

    assert($c['address'] === 'Teststreet 1'); // addressLine[0]
    assert($c['postal_code'] === '1234AB'); // postalCode -> postal_code
    assert($c['handle'] === 'apitest-owned-0001');
    assert($c['organization'] === 'LJPc');
    assert(!array_key_exists('brand', $c));
    assert(!array_key_exists('customer', $c));
    assert(!array_key_exists('registries', $c));
    assert(!array_key_exists('addressLine', $c));
    assert(!array_key_exists('postalCode', $c));
}

// --- Note 7: getHosting id rename + null disk ----------------------------------

function test_ljpc_gethosting_renames_id_and_tolerates_null_disk(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok([
        'id' => 2019, 'domain' => 'reseller-api-test.nl', 'status' => 'active',
        'disk_space_gb' => 10, 'disk_used_bytes' => null, 'disk_quota_bytes' => 10737418240,
    ]));
    $h = ljpc_client($t)->getHosting('2019');

    assert($h['site_id'] === '2019'); // id -> site_id, string
    assert($h['disk_used_bytes'] === null); // null preserved
    assert($h['disk_quota_bytes'] === 10737418240);
    assert($h['domain'] === 'reseller-api-test.nl');
    assert(!array_key_exists('id', $h));
}

// --- Note 8: getOrderStatus vocabulary + nesting -------------------------------

function test_ljpc_getorderstatus_maps_state_to_status(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok([
        'order' => ['id' => 110, 'status' => 'pending'],
        'simple_status' => 'processing',
        'provisioning' => ['overall' => 'processing', 'lines' => [
            ['index' => 0, 'type' => 'hosting', 'state' => 'done', 'site_id' => 2019, 'domain' => null],
            ['index' => 1, 'type' => 'domain', 'state' => 'pending', 'site_id' => null, 'domain' => null],
            ['index' => 2, 'type' => 'hosting', 'state' => 'failed', 'site_id' => null, 'domain' => null],
        ]],
    ]));
    $s = ljpc_client($t)->getOrderStatus('110');

    assert($s['status'] === 'processing'); // simple_status
    assert($s['lines'][0]['status'] === 'completed'); // done -> completed
    assert($s['lines'][0]['site_id'] === '2019'); // int -> string
    assert($s['lines'][0]['type'] === 'hosting');
    assert($s['lines'][1]['status'] === 'pending' && $s['lines'][1]['site_id'] === null);
    assert($s['lines'][2]['status'] === 'failed');
    // index/domain not modelled.
    assert(!array_key_exists('index', $s['lines'][0]));
}

// --- Note 9: getHostingLoginUrl 204 ---------------------------------------------

function test_ljpc_gethostingloginurl_returns_url(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok(['url' => 'https://plesk.example/init?PLESKSESSID=x']));
    $url = ljpc_client($t)->getHostingLoginUrl('2019');

    assert($url === 'https://plesk.example/init?PLESKSESSID=x');
}

function test_ljpc_gethostingloginurl_204_throws(): void
{
    // Decision: 204 (no URL available) throws ApiException rather than returning ''.
    $t = new LjpcFakeTransport(fn () => ['status' => 204, 'body' => '']);
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->getHostingLoginUrl('2019'));

    assert($threw === true);
    assert($msg !== '');
    assert(!str_contains($msg, 'api.test') && !str_contains($msg, 'secret-key'));
}

// --- Note 10: getAuthCode 404 throws, getDnsRecords 404 -> [] -------------------

function test_ljpc_getauthcode_returns_code(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok(['domain' => 'x.nl', 'auth_code' => 'EPP-CODE-1']));
    assert(ljpc_client($t)->getAuthCode('x.nl') === 'EPP-CODE-1');
}

function test_ljpc_getauthcode_404_throws(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_err(404, 'Auth code is not available for this domain'));
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->getAuthCode('x.nl'));

    assert($threw === true && $msg === 'Auth code is not available for this domain');
}

function test_ljpc_getdnsrecords_maps_records(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok([
        'zone' => ['domain' => 'x.nl', 'source' => 'manual'],
        'records' => [
            ['type' => 'A', 'name' => '@', 'content' => '192.0.2.10', 'ttl' => 3600, 'prio' => null],
            ['type' => 'MX', 'name' => '@', 'content' => 'mail.x.nl', 'ttl' => 3600, 'prio' => 10],
        ],
    ]));
    $recs = ljpc_client($t)->getDnsRecords('x.nl');

    assert(count($recs) === 2);
    assert($recs[0] === ['type' => 'A', 'name' => '@', 'content' => '192.0.2.10', 'ttl' => 3600, 'prio' => null]);
    assert($recs[1]['prio'] === 10);
}

function test_ljpc_getdnsrecords_404_returns_empty(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_err(404, 'No DNS zone exists for this domain'));
    assert(ljpc_client($t)->getDnsRecords('x.nl') === []);
}

// --- Note 11: pagination accumulation ------------------------------------------

function test_ljpc_listdomains_pages_through_and_drops_extra_fields(): void
{
    $t = new LjpcFakeTransport(function (string $m, string $url): array {
        $page = preg_match('/[?&]page=(\d+)/', $url, $mm) ? (int) $mm[1] : 1;
        if ($page === 1) {
            return ljpc_ok(['domains' => [
                ['domain' => 'a.nl', 'status' => 'active', 'expires_at' => '2027-01-01', 'auto_renew' => true, 'transfer_lock' => true],
                ['domain' => 'b.nl', 'status' => 'active', 'expires_at' => '2027-01-02', 'auto_renew' => false, 'transfer_lock' => false],
            ], 'total' => 3, 'page' => 1, 'per_page' => 100]);
        }

        return ljpc_ok(['domains' => [
            ['domain' => 'c.nl', 'status' => 'active', 'expires_at' => null, 'auto_renew' => true, 'transfer_lock' => true],
        ], 'total' => 3, 'page' => 2, 'per_page' => 100]);
    });
    $domains = ljpc_client($t)->listDomains();

    assert(count($domains) === 3);
    assert(array_column($domains, 'domain') === ['a.nl', 'b.nl', 'c.nl']);
    assert($domains[0] === ['domain' => 'a.nl', 'status' => 'active', 'expires_at' => '2027-01-01']);
    assert(!array_key_exists('auto_renew', $domains[0]));
    assert(count($t->calls) === 2); // two pages fetched
}

function test_ljpc_listcontacts_pages_through(): void
{
    $t = new LjpcFakeTransport(function (string $m, string $url): array {
        $page = preg_match('/[?&]page=(\d+)/', $url, $mm) ? (int) $mm[1] : 1;
        if ($page === 1) {
            return ljpc_ok(['contacts' => [
                ['handle' => 'h1', 'name' => 'One', 'email' => 'one@x.nl', 'brand' => 'default', 'city' => 'A'],
            ], 'total' => 2, 'page' => 1, 'per_page' => 100]);
        }

        return ljpc_ok(['contacts' => [
            ['handle' => 'h2', 'name' => 'Two', 'email' => 'two@x.nl', 'brand' => 'default', 'city' => 'B'],
        ], 'total' => 2, 'page' => 2, 'per_page' => 100]);
    });
    $contacts = ljpc_client($t)->listContacts();

    assert(count($contacts) === 2);
    assert($contacts[0] === ['handle' => 'h1', 'name' => 'One', 'email' => 'one@x.nl']);
    assert($contacts[1]['handle'] === 'h2');
    assert(count($t->calls) === 2);
}

// --- Note 2: write-result synthesis --------------------------------------------

function test_ljpc_registerdomain_builds_write_result_and_body(): void
{
    $sent = null;
    $t = new LjpcFakeTransport(function (string $m, string $url, ?array $body) use (&$sent): array {
        $sent = $body;

        return ljpc_created(['domain' => 'mysite.nl', 'status' => 'active', 'message' => 'Domain registered successfully']);
    });
    $res = ljpc_client($t)->registerDomain('mysite.nl', 'HANDLE-1', [
        'years' => 2, 'nameservers' => ['ns1.x.nl', 'ns2.x.nl'], 'external_reference' => 'REF',
    ]);

    assert($res === ['success' => true, 'message' => 'Domain registered successfully']);
    assert($sent['domain'] === 'mysite.nl');
    assert($sent['contact_handle'] === 'HANDLE-1');
    assert($sent['years'] === 2);
    assert($sent['nameservers'] === ['ns1.x.nl', 'ns2.x.nl']);
    assert($sent['external_reference'] === 'REF');
}

function test_ljpc_registerdomain_error_throws_with_message(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_err(422, 'Domain already exists'));
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->registerDomain('mysite.nl', 'HANDLE-1'));

    assert($threw === true && $msg === 'Domain already exists');
}

function test_ljpc_transferdomain_puts_domain_in_path_and_body(): void
{
    $sent = null;
    $t = new LjpcFakeTransport(function (string $m, string $url, ?array $body) use (&$sent): array {
        $sent = $body;
        assert(str_contains($url, '/domain-names/move-me.nl/transfer'));

        return ['status' => 202, 'body' => (string) json_encode(['success' => true, 'jti' => 'j', 'data' => [
            'domain' => 'move-me.nl', 'status' => 'pending_transfer', 'message' => 'Domain transfer initiated successfully',
        ]])];
    });
    $res = ljpc_client($t)->transferDomain('move-me.nl', 'AUTH-XYZ', 'HANDLE-2', ['nameservers' => ['ns1.x.nl', 'ns2.x.nl']]);

    assert($res['success'] === true && $res['message'] === 'Domain transfer initiated successfully');
    assert($sent['auth_code'] === 'AUTH-XYZ');
    assert($sent['contact_handle'] === 'HANDLE-2');
    assert($sent['nameservers'] === ['ns1.x.nl', 'ns2.x.nl']);
}

function test_ljpc_updatedomain_passes_handle_and_treats_no_change_as_noop(): void
{
    // whois_contact_handle passed through unchanged.
    $sent = null;
    $t = new LjpcFakeTransport(function (string $m, string $url, ?array $body) use (&$sent): array {
        $sent = $body;
        assert($m === 'PATCH');

        return ljpc_ok(['domain' => 'x.nl', 'message' => 'Domain updated successfully']);
    });
    $res = ljpc_client($t)->updateDomain('x.nl', ['whois_contact_handle' => 'HANDLE-9', 'auto_renew' => false]);
    assert($res === ['success' => true, 'message' => 'Domain updated successfully']);
    assert($sent['whois_contact_handle'] === 'HANDLE-9');
    assert($sent['auto_renew'] === false);

    // 422 "No changes to apply" is a no-op success, not an error.
    $t2 = new LjpcFakeTransport(fn () => ljpc_err(422, 'No changes to apply'));
    $noop = ljpc_client($t2)->updateDomain('x.nl', []);
    assert($noop['success'] === true);
}

function test_ljpc_savednsrecords_wraps_records_key(): void
{
    $sent = null;
    $t = new LjpcFakeTransport(function (string $m, string $url, ?array $body) use (&$sent): array {
        $sent = $body;
        assert($m === 'PUT');

        return ljpc_ok(['zone' => ['domain' => 'x.nl', 'source' => 'manual'], 'records' => [], 'synced' => false]);
    });
    $records = [['type' => 'A', 'name' => '@', 'content' => '192.0.2.10', 'ttl' => 3600, 'prio' => null]];
    $res = ljpc_client($t)->saveDnsRecords('x.nl', $records);

    assert($res['success'] === true);
    assert($sent === ['records' => $records]); // wrapped in records key
}

function test_ljpc_updatecontact_builds_write_result_without_message(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok(['success' => true]));
    $res = ljpc_client($t)->updateContact('h1', ['city' => 'NewCity']);

    assert($res['success'] === true);
    assert($res['message'] === ''); // no data.message on contact updates
}

function test_ljpc_createcontact_returns_handle(): void
{
    $sent = null;
    $t = new LjpcFakeTransport(function (string $m, string $url, ?array $body) use (&$sent): array {
        $sent = $body;

        return ljpc_created(['handle' => 'new-handle-1']);
    });
    $handle = ljpc_client($t)->createContact([
        'name' => 'A', 'address' => 'Street 1', 'postal_code' => '1234AB',
        'city' => 'C', 'country' => 'NL', 'email' => 'a@b.c', 'voice' => '+31612345678',
    ]);

    assert($handle === 'new-handle-1');
    assert($sent['name'] === 'A' && $sent['postal_code'] === '1234AB'); // snake_case forwarded as-is
}

function test_ljpc_getdomain_returns_data_shape(): void
{
    $t = new LjpcFakeTransport(fn () => ljpc_ok([
        'domain' => 'x.nl', 'status' => 'active', 'expires_at' => '2027-07-02T16:37:57+02:00',
        'nameservers' => ['ns1.ljpc.network', 'ns2.ljpc.network'], 'auto_renew' => true,
        'transfer_lock' => true, 'contact_handle' => 'apitest-owned-0001',
    ]));
    $d = ljpc_client($t)->getDomain('x.nl');

    assert($d['domain'] === 'x.nl');
    assert($d['nameservers'] === ['ns1.ljpc.network', 'ns2.ljpc.network']);
    assert($d['auto_renew'] === true && $d['transfer_lock'] === true);
    assert($d['contact_handle'] === 'apitest-owned-0001');
}

// --- Transport error mapping ----------------------------------------------------

function test_ljpc_transport_exception_propagates_as_apiexception(): void
{
    // A transport/timeout failure surfaces as the user-safe unreachable ApiException.
    $t = new LjpcFakeTransport(function (): array {
        throw new ApiException('The hosting service is unreachable, please try again.');
    });
    [$threw, $msg] = ljpc_catch(fn () => ljpc_client($t)->listTlds());

    assert($threw === true);
    assert($msg === 'The hosting service is unreachable, please try again.');
}
