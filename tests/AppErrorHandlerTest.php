<?php

declare(strict_types=1);

use Panel\Api\ApiClient;
use Panel\Api\ApiException;
use Panel\App;

/**
 * A test-double API client whose checkDomain() always throws, simulating a live
 * outage. Every other method throws too, since this scenario must never reach them.
 */
final class AlwaysOutageApiClient implements ApiClient
{
    public function listTlds(): array
    {
        throw new RuntimeException('unexpected listTlds in app-error-handler test');
    }

    public function checkDomain(string $domain): array
    {
        throw new ApiException('The hosting service is unreachable, please try again.');
    }

    public function listDomains(): array
    {
        throw new RuntimeException('unexpected listDomains in app-error-handler test');
    }

    public function getDomain(string $domain): array
    {
        throw new RuntimeException('unexpected getDomain in app-error-handler test');
    }

    public function registerDomain(string $domain, string $contactHandle, array $opts = []): array
    {
        throw new RuntimeException('unexpected registerDomain in app-error-handler test');
    }

    public function transferDomain(string $domain, string $authCode, string $contactHandle, array $opts = []): array
    {
        throw new RuntimeException('unexpected transferDomain in app-error-handler test');
    }

    public function updateDomain(string $domain, array $changes): array
    {
        throw new RuntimeException('unexpected updateDomain in app-error-handler test');
    }

    public function getAuthCode(string $domain): string
    {
        throw new RuntimeException('unexpected getAuthCode in app-error-handler test');
    }

    public function getDnsRecords(string $domain): array
    {
        throw new RuntimeException('unexpected getDnsRecords in app-error-handler test');
    }

    public function saveDnsRecords(string $domain, array $records): array
    {
        throw new RuntimeException('unexpected saveDnsRecords in app-error-handler test');
    }

    public function listContacts(): array
    {
        throw new RuntimeException('unexpected listContacts in app-error-handler test');
    }

    public function getContact(string $handle): array
    {
        throw new RuntimeException('unexpected getContact in app-error-handler test');
    }

    public function createContact(array $data): string
    {
        throw new RuntimeException('unexpected createContact in app-error-handler test');
    }

    public function updateContact(string $handle, array $data): array
    {
        throw new RuntimeException('unexpected updateContact in app-error-handler test');
    }

    public function listPackages(): array
    {
        throw new RuntimeException('unexpected listPackages in app-error-handler test');
    }

    public function createHosting(string $domain, string $categoryIdentifier, array $opts = []): array
    {
        throw new RuntimeException('unexpected createHosting in app-error-handler test');
    }

    public function getHosting(string $siteId): array
    {
        throw new RuntimeException('unexpected getHosting in app-error-handler test');
    }

    public function getHostingLoginUrl(string $siteId): string
    {
        throw new RuntimeException('unexpected getHostingLoginUrl in app-error-handler test');
    }

    public function getOrderStatus(string $remoteOrderId): array
    {
        throw new RuntimeException('unexpected getOrderStatus in app-error-handler test');
    }
}

function panel_test_error_app(ApiClient $api): App
{
    $config = [
        'panel_name' => 'Test panel',
        'base_url' => 'http://localhost',
        'currency' => 'EUR',
        'db' => ['driver' => 'sqlite', 'sqlite_path' => ':memory:'],
        'api' => ['base_url' => 'http://example.test/api', 'key' => 'unused-because-injected'],
        'search_tlds' => ['nl'],
        'packages' => [],
        'domain_pricing' => ['margin_percent' => 30, 'overrides' => []],
        'payment' => ['gateway' => 'manual'],
        'mail' => ['mailer' => 'log', 'from' => 'noreply@example.com'],
    ];

    return new App($config, dirname(__DIR__), $api);
}

/**
 * The C1 safety net: App::handle() catches ApiException from any route whose
 * controller does not already handle it itself (domains/hosting/contacts/dns/search
 * all catch it locally) and renders templates/error.php at HTTP 503. POST /cart/add
 * for a domain_register calls ApiClient::checkDomain() with no local try/catch, so
 * it is the real uncaught path this safety net exists for.
 *
 * Drives the actual App::handle() entry point (not a re-implementation of it):
 * builds a real App with an injected always-throwing ApiClient, registers a real
 * user, starts a real PHP session ourselves (App::handle() only calls session_start()
 * when no session is already active, so pre-starting it here is what lets us seed
 * $_SESSION and have handle() see it, instead of session_start() replacing it with an
 * empty new session), sets the minimal $_SERVER/$_POST a POST /cart/add request
 * needs (including a valid CSRF token), and captures what handle() -> Response::send()
 * actually emits via output buffering and http_response_code().
 */
function test_app_handle_renders_503_error_page_on_uncaught_api_exception(): void
{
    $api = new AlwaysOutageApiClient();
    $app = panel_test_error_app($api);

    session_save_path(sys_get_temp_dir());
    session_start();
    $_SESSION = [];

    $registered = $app->auth->register('outage-tester@example.test', 'longenoughpassword', 'Outage Tester');
    assert($registered['ok'] === true);
    $_SESSION['user_id'] = $registered['user_id'];
    $csrf = $app->auth->csrfToken();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/cart/add';
    $_POST = [
        'type' => 'domain_register',
        'domain' => 'ljpc-outage-probe.nl',
        'years' => '1',
        '_csrf' => $csrf,
    ];

    ob_start();
    $app->handle();
    $body = (string) ob_get_clean();
    $status = http_response_code();

    assert($status === 503);
    // The friendly text from templates/error.php, rendered inside the layout.
    assert(str_contains($body, 'Service temporarily unavailable'));
    assert(str_contains($body, 'The hosting service is temporarily unavailable. Please try again in a moment.'));
    assert(str_contains($body, 'Go back home'));
    // Never a raw/leaked exception message.
    assert(!str_contains($body, 'unreachable'));

    $_SERVER = [];
    $_POST = [];
    $_SESSION = [];
    // Close the session we started so it does not stay ACTIVE for the rest of the
    // suite: other tests (e.g. AuthTest) assert on session_status() themselves and
    // must see the same NONE state they would without this test ever running.
    session_write_close();
}
