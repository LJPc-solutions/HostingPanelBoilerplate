<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\App;
use Panel\Response;

final class DashboardController
{
    private const OPEN_ORDER_STATUSES = ['pending_payment', 'paid', 'provisioning'];

    public function __construct(private readonly App $app)
    {
    }

    public function index(array $params): Response
    {
        $userId = (int) $this->user()['id'];

        $stmt = $this->app->pdo->prepare('SELECT COUNT(*) FROM domains WHERE user_id = ?');
        $stmt->execute([$userId]);
        $domainsCount = (int) $stmt->fetchColumn();

        $stmt = $this->app->pdo->prepare('SELECT COUNT(*) FROM hosting WHERE user_id = ?');
        $stmt->execute([$userId]);
        $hostingCount = (int) $stmt->fetchColumn();

        $placeholders = implode(',', array_fill(0, count(self::OPEN_ORDER_STATUSES), '?'));
        $stmt = $this->app->pdo->prepare(
            "SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ({$placeholders})"
        );
        $stmt->execute([$userId, ...self::OPEN_ORDER_STATUSES]);
        $openOrdersCount = (int) $stmt->fetchColumn();

        $stmt = $this->app->pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5');
        $stmt->execute([$userId]);
        $recentOrders = $stmt->fetchAll();

        return Response::html($this->app->view->render('dashboard', [
            'title' => 'Dashboard',
            'domains_count' => $domainsCount,
            'hosting_count' => $hostingCount,
            'open_orders_count' => $openOrdersCount,
            'recent_orders' => $recentOrders,
        ]));
    }

    private function user(): array
    {
        return $this->app->auth->user();
    }
}
