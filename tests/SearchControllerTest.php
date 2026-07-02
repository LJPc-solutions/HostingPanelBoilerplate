<?php

declare(strict_types=1);

use Panel\Api\ApiClient;
use Panel\Api\ApiException;
use Panel\App;
use Panel\Controllers\SearchController;
use Panel\Response;

/**
 * A test-double API client that counts the calls the search makes. Only listTlds
 * and checkDomain are ever expected; every other method throws so an accidental
 * extra call is caught. listTlds returns a large catalog to prove the controller
 * no longer fans out across it.
 */
final class CountingSearchApiClient implements ApiClient
{
    public int $checkCalls = 0;
    public int $tldCalls = 0;
    /** @var list<string> */
    public array $checkedDomains = [];
    // When set, checkDomain throws this as an outage instead of returning a verdict.
    public ?string $checkThrows = null;

    public function listTlds(): array
    {
        $this->tldCalls++;
        $out = [];
        foreach (range(1, 500) as $i) {
            $out[] = ['tld' => 't' . $i, 'price' => 1.0, 'currency' => 'EUR', 'transfer_supported' => true];
        }

        return $out;
    }

    public function checkDomain(string $domain): array
    {
        $this->checkCalls++;
        $this->checkedDomains[] = $domain;
        if ($this->checkThrows !== null) {
            throw new ApiException($this->checkThrows);
        }

        return ['available' => true, 'premium' => false, 'price' => 5.0];
    }

    public function listDomains(): array
    {
        throw new RuntimeException('unexpected listDomains in search test');
    }

    public function getDomain(string $domain): array
    {
        throw new RuntimeException('unexpected getDomain in search test');
    }

    public function registerDomain(string $domain, string $contactHandle, array $opts = []): array
    {
        throw new RuntimeException('unexpected registerDomain in search test');
    }

    public function transferDomain(string $domain, string $authCode, string $contactHandle, array $opts = []): array
    {
        throw new RuntimeException('unexpected transferDomain in search test');
    }

    public function updateDomain(string $domain, array $changes): array
    {
        throw new RuntimeException('unexpected updateDomain in search test');
    }

    public function getAuthCode(string $domain): string
    {
        throw new RuntimeException('unexpected getAuthCode in search test');
    }

    public function getDnsRecords(string $domain): array
    {
        throw new RuntimeException('unexpected getDnsRecords in search test');
    }

    public function saveDnsRecords(string $domain, array $records): array
    {
        throw new RuntimeException('unexpected saveDnsRecords in search test');
    }

    public function listContacts(): array
    {
        throw new RuntimeException('unexpected listContacts in search test');
    }

    public function getContact(string $handle): array
    {
        throw new RuntimeException('unexpected getContact in search test');
    }

    public function createContact(array $data): string
    {
        throw new RuntimeException('unexpected createContact in search test');
    }

    public function updateContact(string $handle, array $data): array
    {
        throw new RuntimeException('unexpected updateContact in search test');
    }

    public function listPackages(): array
    {
        throw new RuntimeException('unexpected listPackages in search test');
    }

    public function createHosting(string $domain, string $categoryIdentifier, array $opts = []): array
    {
        throw new RuntimeException('unexpected createHosting in search test');
    }

    public function getHosting(string $siteId): array
    {
        throw new RuntimeException('unexpected getHosting in search test');
    }

    public function getHostingLoginUrl(string $siteId): string
    {
        throw new RuntimeException('unexpected getHostingLoginUrl in search test');
    }

    public function getOrderStatus(string $remoteOrderId): array
    {
        throw new RuntimeException('unexpected getOrderStatus in search test');
    }
}

function panel_test_search_app(CountingSearchApiClient $api): App
{
    $config = [
        'panel_name' => 'Test panel',
        'base_url' => 'http://localhost',
        'currency' => 'EUR',
        'db' => ['driver' => 'sqlite', 'sqlite_path' => ':memory:'],
        'api' => ['base_url' => 'http://example.test/api', 'key' => 'unused-because-injected'],
        'search_tlds' => ['com', 'net', 'org', 'nl', 'eu', 'dev', 'io', 'app'],
        'packages' => [],
        'domain_pricing' => ['margin_percent' => 30, 'overrides' => []],
        'payment' => ['gateway' => 'manual'],
        'mail' => ['mailer' => 'log', 'from' => 'noreply@example.com'],
    ];

    return new App($config, dirname(__DIR__), $api);
}

function test_search_bounds_checkdomain_calls_to_configured_tlds(): void
{
    $api = new CountingSearchApiClient();
    $app = panel_test_search_app($api);
    $controller = new SearchController($app);
    $tldCount = count($app->config['search_tlds']);
    $_SESSION = [];

    // Bare name: exactly one checkDomain per configured TLD, and listTlds is never
    // called (the whole point of the fix).
    $_GET = ['q' => 'example'];
    $controller->search([]);
    assert($api->checkCalls === $tldCount);
    assert($api->tldCalls === 0);
    assert($api->checkedDomains === ['example.com', 'example.net', 'example.org', 'example.nl', 'example.eu', 'example.dev', 'example.io', 'example.app']);

    // Dotted query whose TLD is configured: the exact domain replaces that TLD's
    // row, so the total stays at count(search_tlds).
    $api->checkCalls = 0;
    $api->checkedDomains = [];
    $_GET = ['q' => 'example.com'];
    $controller->search([]);
    assert($api->checkCalls === $tldCount);
    assert($api->checkedDomains[0] === 'example.com');
    assert(count(array_keys($api->checkedDomains, 'example.com', true)) === 1); // no duplicate exact row

    // Dotted query whose TLD is NOT configured: exact domain plus every configured
    // TLD, so exactly count(search_tlds) + 1 (the documented +1 case).
    $api->checkCalls = 0;
    $api->checkedDomains = [];
    $_GET = ['q' => 'example.xyz'];
    $controller->search([]);
    assert($api->checkCalls === $tldCount + 1);
    assert($api->checkedDomains[0] === 'example.xyz');

    // Invalid query: no lookups at all, and still no listTlds. The layout renders
    // (and then clears) the validation flash, so assert on the returned HTML.
    $api->checkCalls = 0;
    $api->tldCalls = 0;
    $_GET = ['q' => 'has space'];
    $response = $controller->search([]);
    assert($api->checkCalls === 0);
    assert($api->tldCalls === 0);
    assert(str_contains($response->body, 'Please enter a valid domain')); // validation flash preserved

    $_GET = [];
    $_SESSION = [];
}

function test_search_flashes_and_shows_no_rows_when_api_is_down(): void
{
    // A real outage (checkDomain throws ApiException) must not 500 the page: the
    // controller returns a Response, flashes a friendly message, and renders no
    // partial/garbage result rows.
    $api = new CountingSearchApiClient();
    $api->checkThrows = 'The hosting service is unreachable, please try again.';
    $app = panel_test_search_app($api);
    $controller = new SearchController($app);
    $_SESSION = [];
    $_GET = ['q' => 'example'];

    $response = $controller->search([]); // must not throw

    assert($response instanceof Response);
    assert($response->status === 200);
    // Friendly outage flash rendered (layout.php prints and clears it).
    assert(str_contains($response->body, 'Domain search is temporarily unavailable'));
    // No result table rows leaked: neither an availability badge nor an action.
    assert(!str_contains($response->body, 'badge available'));
    assert(!str_contains($response->body, 'Add to cart'));
    // listTlds is still never touched, and the search aborted on the first failure.
    assert($api->tldCalls === 0);

    $_GET = [];
    $_SESSION = [];
}
