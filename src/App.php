<?php

declare(strict_types=1);

namespace Panel;

use Panel\Api\ApiClient;
use Panel\Api\ApiException;
use Panel\Api\MockApiClient;
use Panel\Api\LjpcApiClient;
use Panel\Mail\LogMailer;
use Panel\Mail\Mailer;
use Panel\Payment\ManualPayment;
use Panel\Payment\PaymentGateway;
use PDO;

final class App
{
    public readonly PDO $pdo;
    public readonly Router $router;
    public readonly View $view;
    public readonly Auth $auth;
    public readonly ApiClient $api;
    public readonly PaymentGateway $payment;
    public readonly Mailer $mailer;
    public readonly Pricing $pricing;

    public function __construct(public readonly array $config, public readonly string $rootDir, ?ApiClient $api = null)
    {
        $this->pdo = Database::connect($config['db']);
        Database::migrate($this->pdo);

        $this->auth = new Auth($this->pdo);
        // A caller may inject the API client (used by tests); otherwise pick mock or
        // live from the config key, exactly as before.
        $this->api = $api ?? ($config['api']['key'] === ''
            ? new MockApiClient($rootDir . '/var/mock')
            : new LjpcApiClient($config['api']['base_url'], $config['api']['key']));
        $this->pricing = new Pricing($config['domain_pricing'], $config['packages'], $config['currency']);
        $this->payment = new ManualPayment();
        $this->mailer = new LogMailer($rootDir . '/var/mail.log', $config['mail']['from']);
        $this->view = new View($rootDir . '/templates', ['config' => $config, 'view' => null, 'user' => null, 'csrf' => '']);
        $this->router = new Router();
        $this->routes();
    }

    private function routes(): void
    {
        $r = $this->router;
        $r->add('GET', '/', fn (array $p) => (new Controllers\HomeController($this))->index($p));
        $r->add('GET', '/register', fn (array $p) => (new Controllers\AuthController($this))->showRegister($p));
        $r->add('POST', '/register', fn (array $p) => (new Controllers\AuthController($this))->register($p));
        $r->add('GET', '/login', fn (array $p) => (new Controllers\AuthController($this))->showLogin($p));
        $r->add('POST', '/login', fn (array $p) => (new Controllers\AuthController($this))->login($p));
        $r->add('POST', '/logout', fn (array $p) => (new Controllers\AuthController($this))->logout($p));
        $r->add('GET', '/password/forgot', fn (array $p) => (new Controllers\PasswordController($this))->showForgot($p));
        $r->add('POST', '/password/forgot', fn (array $p) => (new Controllers\PasswordController($this))->sendLink($p));
        $r->add('GET', '/password/reset/{token}', fn (array $p) => (new Controllers\PasswordController($this))->showReset($p));
        $r->add('POST', '/password/reset/{token}', fn (array $p) => (new Controllers\PasswordController($this))->reset($p));
        $r->add('GET', '/search', fn (array $p) => (new Controllers\SearchController($this))->search($p));
        $r->add('GET', '/cart', fn (array $p) => (new Controllers\CartController($this))->show($p));
        $r->add('POST', '/cart/add', fn (array $p) => (new Controllers\CartController($this))->add($p));
        $r->add('POST', '/cart/remove', fn (array $p) => (new Controllers\CartController($this))->remove($p));
        $r->add('GET', '/contacts', fn (array $p) => (new Controllers\ContactsController($this))->index($p));
        $r->add('GET', '/contacts/new', fn (array $p) => (new Controllers\ContactsController($this))->showCreate($p));
        $r->add('POST', '/contacts/new', fn (array $p) => (new Controllers\ContactsController($this))->create($p));
        $r->add('GET', '/contacts/{id}/edit', fn (array $p) => (new Controllers\ContactsController($this))->showEdit($p));
        $r->add('POST', '/contacts/{id}/edit', fn (array $p) => (new Controllers\ContactsController($this))->edit($p));
        $r->add('GET', '/domains', fn (array $p) => (new Controllers\DomainsController($this))->index($p));
        $r->add('GET', '/domains/{domain}', fn (array $p) => (new Controllers\DomainsController($this))->show($p));
        $r->add('POST', '/domains/{domain}/settings', fn (array $p) => (new Controllers\DomainsController($this))->updateSettings($p));
        $r->add('POST', '/domains/{domain}/authcode', fn (array $p) => (new Controllers\DomainsController($this))->revealAuthCode($p));
        $r->add('GET', '/domains/{domain}/dns', fn (array $p) => (new Controllers\DnsController($this))->edit($p));
        $r->add('POST', '/domains/{domain}/dns', fn (array $p) => (new Controllers\DnsController($this))->save($p));
        $r->add('GET', '/hosting', fn (array $p) => (new Controllers\HostingController($this))->index($p));
        $r->add('GET', '/hosting/order', fn (array $p) => (new Controllers\HostingController($this))->order($p));
        $r->add('POST', '/hosting/order', fn (array $p) => (new Controllers\HostingController($this))->addToCart($p));
        $r->add('GET', '/hosting/{id}', fn (array $p) => (new Controllers\HostingController($this))->show($p));
        $r->add('GET', '/hosting/{id}/login', fn (array $p) => (new Controllers\HostingController($this))->login($p));
        $r->add('GET', '/checkout', fn (array $p) => (new Controllers\OrderController($this))->showCheckout($p));
        $r->add('POST', '/checkout', fn (array $p) => (new Controllers\OrderController($this))->checkout($p));
        $r->add('GET', '/order/pay/{id}', fn (array $p) => (new Controllers\OrderController($this))->pay($p));
        $r->add('GET', '/order/return/{id}', fn (array $p) => (new Controllers\OrderController($this))->paymentReturn($p));
        $r->add('GET', '/order/status/{id}', fn (array $p) => (new Controllers\OrderController($this))->status($p));
        $r->add('GET', '/order/status/{id}/json', fn (array $p) => (new Controllers\OrderController($this))->statusJson($p));
        $r->add('GET', '/dashboard', fn (array $p) => (new Controllers\DashboardController($this))->index($p));
        $r->add('GET', '/account', fn (array $p) => (new Controllers\AccountController($this))->show($p));
        $r->add('POST', '/account', fn (array $p) => (new Controllers\AccountController($this))->update($p));
        // Later tasks append routes here.
    }

    private const PUBLIC_PATHS = ['/', '/login', '/register', '/password/forgot', '/search'];

    public function handle(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
            session_start();
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

        $user = $this->auth->user();
        $this->view->share('user', $user);
        $this->view->share('csrf', $this->auth->csrfToken());
        $this->view->share('view', $this->view);

        $isPublic = in_array($path, self::PUBLIC_PATHS, true) || str_starts_with($path, '/password/reset/');
        if ($user === null && !$isPublic) {
            Response::redirect('/login')->send();

            return;
        }

        $hit = $this->router->match($method, $path);
        if ($hit === null) {
            Response::html($this->view->render('404', ['title' => 'Page not found']), 404)->send();

            return;
        }

        if ($method === 'POST' && !$this->auth->verifyCsrf($_POST['_csrf'] ?? null)) {
            Response::html('Invalid CSRF token', 419)->send();

            return;
        }

        // Safety net for any path that does not already catch ApiException
        // (domains/hosting/contacts/dns controllers handle it themselves). A live
        // API outage renders a friendly 503 instead of a raw 500. Only ApiException
        // is caught, never a generic Throwable, so real bugs still surface in dev.
        try {
            ($hit['handler'])($hit['params'])->send();
        } catch (ApiException) {
            Response::html($this->view->render('error', ['title' => 'Service temporarily unavailable']), 503)->send();
        }
    }
}
