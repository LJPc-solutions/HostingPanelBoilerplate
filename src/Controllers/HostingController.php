<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\Api\ApiException;
use Panel\App;
use Panel\Cart;
use Panel\Response;

final class HostingController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(array $params): Response
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM hosting WHERE user_id = ? ORDER BY domain');
        $stmt->execute([$this->user()['id']]);

        $rows = array_map(fn (array $row) => $this->indexRow($row), $stmt->fetchAll());

        return Response::html($this->app->view->render('hosting/index', [
            'title' => 'Hosting',
            'rows' => $rows,
        ]));
    }

    public function order(array $params): Response
    {
        $requestedPackageId = $_GET['package'] ?? null;
        $selectedPackageId = $requestedPackageId !== null && $this->app->pricing->package((string) $requestedPackageId) !== null
            ? (string) $requestedPackageId
            : null;

        return Response::html($this->app->view->render('hosting/order', [
            'title' => 'Order hosting',
            'selected_package_id' => $selectedPackageId,
        ]));
    }

    public function addToCart(array $params): Response
    {
        $packageId = (string) ($_POST['package_id'] ?? '');
        $package = $this->app->pricing->package($packageId);
        if ($package === null) {
            return $this->backToOrder('Please choose a valid hosting package.');
        }

        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        if (!valid_domain_name($domain)) {
            return $this->backToOrder('Please enter a valid domain name.');
        }

        (new Cart())->add([
            'type' => 'hosting',
            'domain' => $domain,
            'package_id' => $packageId,
            'years' => 1,
            'auth_code' => null,
            'price_cents' => $this->app->pricing->packageMonthlyCents($packageId),
            'label' => $package['name'] . ' hosting for ' . $domain . ' (per month)',
            'category_identifier' => $package['category_identifier'],
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Added to your cart.'];

        return Response::redirect('/cart');
    }

    public function show(array $params): Response
    {
        $row = $this->ownedHosting((int) $params['id']);
        if ($row === null) {
            return $this->notFound();
        }

        $package = $this->app->pricing->package($row['package_id']);
        $pending = $row['status'] !== 'active' || $row['site_id'] === null;
        $usage = null;
        $usageUnavailable = false;

        if (!$pending) {
            try {
                $usage = $this->usageView($this->app->api->getHosting($row['site_id']));
            } catch (ApiException) {
                $usageUnavailable = true;
            }
        }

        return Response::html($this->app->view->render('hosting/show', [
            'title' => $row['domain'],
            'hosting' => $row,
            'package_name' => $package['name'] ?? $row['package_id'],
            'pending' => $pending,
            'usage' => $usage,
            'usage_unavailable' => $usageUnavailable,
        ]));
    }

    public function login(array $params): Response
    {
        $row = $this->ownedHosting((int) $params['id']);
        if ($row === null) {
            return $this->notFound();
        }

        if ($row['site_id'] === null || $row['status'] !== 'active') {
            return $this->backWithFlash($row['id'], 'Hosting is not active yet.');
        }

        try {
            $url = $this->app->api->getHostingLoginUrl($row['site_id']);
        } catch (ApiException $e) {
            return $this->backWithFlash($row['id'], $e->getMessage());
        }

        return Response::redirect($url);
    }

    /** @return array{id: int|string, domain: string, package_name: string, status: string, usage: ?array, usage_unavailable: bool} */
    private function indexRow(array $row): array
    {
        $package = $this->app->pricing->package($row['package_id']);
        $usage = null;
        $usageUnavailable = false;

        if ($row['status'] === 'active' && $row['site_id'] !== null) {
            try {
                $usage = $this->usageView($this->app->api->getHosting($row['site_id']));
            } catch (ApiException) {
                $usageUnavailable = true;
            }
        }

        return [
            'id' => $row['id'],
            'domain' => $row['domain'],
            'package_name' => $package['name'] ?? $row['package_id'],
            'status' => $row['status'],
            'usage' => $usage,
            'usage_unavailable' => $usageUnavailable,
        ];
    }

    /** @return array{used_bytes: int, quota_bytes: ?int, percent: int, unlimited: bool} */
    private function usageView(array $live): array
    {
        $used = (int) $live['disk_used_bytes'];
        $quota = $live['disk_quota_bytes'];
        $percent = ($quota === null || $quota <= 0) ? 0 : max(0, min(100, (int) round($used / $quota * 100)));

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'percent' => $percent,
            'unlimited' => $quota === null,
        ];
    }

    private function ownedHosting(int $id): ?array
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM hosting WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->user()['id']]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function backToOrder(string $message): Response
    {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $message];

        return Response::redirect('/hosting/order');
    }

    private function backWithFlash(int|string $id, string $message): Response
    {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $message];

        return Response::redirect('/hosting/' . $id);
    }

    private function notFound(): Response
    {
        return Response::html($this->app->view->render('404', ['title' => 'Page not found']), 404);
    }

    private function user(): array
    {
        return $this->app->auth->user();
    }
}
