<?php

declare(strict_types=1);

use Panel\Api\MockApiClient;
use Panel\OrderService;

function test_order_provisioning_with_mock(): void
{
    $pdo = panel_test_pdo();
    $mock = panel_test_mock();
    $svc = new OrderService($pdo, $mock);

    $orderId = $svc->createFromCart(1, [
        ['type' => 'domain_register', 'domain' => 'neworder.nl', 'package_id' => null, 'years' => 1, 'auth_code' => null, 'price_cents' => 999, 'label' => 'Register neworder.nl', 'contact_handle' => 'MOCKAAA'],
        ['type' => 'hosting', 'domain' => 'neworder.nl', 'package_id' => 'starter', 'years' => 1, 'auth_code' => null, 'price_cents' => 799, 'label' => 'Starter hosting', 'category_identifier' => 'hosting_5gb'],
    ], 'EUR');

    $order = $svc->get($orderId, 1);
    assert($order['status'] === 'pending_payment' && (int) $order['total_cents'] === 1798);
    assert($svc->get($orderId, 2) === null); // ownership

    $svc->markPaid($orderId);
    $svc->provision($orderId, 1);

    $order = $svc->get($orderId, 1);
    assert($order['status'] === 'provisioning'); // hosting still provisioning, domain done
    $itemStatuses = array_column($order['items'], 'status');
    assert(in_array('done', $itemStatuses, true) && in_array('provisioning', $itemStatuses, true));

    $domain = $pdo->query("SELECT * FROM domains WHERE domain = 'neworder.nl'")->fetch();
    assert($domain !== false && (int) $domain['user_id'] === 1);

    // Mock orders complete after 8 seconds; simulate by rewinding the stored creation time.
    $hostingItem = null;
    foreach ($order['items'] as $item) {
        if ($item['type'] === 'hosting') {
            $hostingItem = $item;
        }
    }
    $store = new ReflectionProperty(MockApiClient::class, 'store');
    $mockStore = $store->getValue($mock);
    $remote = $mockStore->get('order:' . $hostingItem['remote_ref']);
    $remote['created'] = time() - 60;
    $mockStore->set('order:' . $hostingItem['remote_ref'], $remote);

    $refreshed = $svc->refreshStatus($orderId, 1);
    assert($refreshed['status'] === 'completed');
    $hosting = $pdo->query("SELECT * FROM hosting WHERE domain = 'neworder.nl'")->fetch();
    assert($hosting['status'] === 'active' && $hosting['site_id'] !== null);
}

function test_order_provision_transfer_creates_pending_domain(): void
{
    $pdo = panel_test_pdo();
    $svc = new Panel\OrderService($pdo, panel_test_mock());

    $orderId = $svc->createFromCart(1, [
        ['type' => 'domain_transfer', 'domain' => 'moveme.nl', 'package_id' => null, 'years' => 1, 'auth_code' => 'AUTH1234', 'price_cents' => 999, 'label' => 'Transfer moveme.nl', 'contact_handle' => 'MOCKAAA'],
    ], 'EUR');
    $svc->markPaid($orderId);
    $svc->provision($orderId, 1);

    $order = $svc->get($orderId, 1);
    assert($order['status'] === 'completed');
    $row = $pdo->query("SELECT * FROM domains WHERE domain = 'moveme.nl'")->fetch();
    assert($row !== false && $row['status'] === 'transfer_pending' && (int) $row['user_id'] === 1);
}

function test_refresh_status_leaves_unpaid_orders_alone(): void
{
    $pdo = panel_test_pdo();
    $svc = new Panel\OrderService($pdo, panel_test_mock());

    $orderId = $svc->createFromCart(1, [
        ['type' => 'domain_register', 'domain' => 'unpaid.nl', 'package_id' => null, 'years' => 1, 'auth_code' => null, 'price_cents' => 999, 'label' => 'Register unpaid.nl', 'contact_handle' => 'MOCKAAA'],
    ], 'EUR');

    $refreshed = $svc->refreshStatus($orderId, 1);
    assert($refreshed['status'] === 'pending_payment');
    assert($refreshed['items'][0]['status'] === 'pending');
    $row = $pdo->query("SELECT status FROM orders WHERE id = {$orderId}")->fetch();
    assert($row['status'] === 'pending_payment');
}

function test_order_provision_survives_duplicate_domain(): void
{
    $pdo = panel_test_pdo();
    $svc = new Panel\OrderService($pdo, panel_test_mock());

    $stmt = $pdo->prepare('INSERT INTO domains (user_id, domain, status, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([2, 'taken-by-other.nl', 'active', date('Y-m-d H:i:s')]);

    $orderId = $svc->createFromCart(1, [
        ['type' => 'domain_register', 'domain' => 'taken-by-other.nl', 'package_id' => null, 'years' => 1, 'auth_code' => null, 'price_cents' => 999, 'label' => 'Register taken-by-other.nl', 'contact_handle' => 'MOCKAAA'],
        ['type' => 'domain_register', 'domain' => 'fine.nl', 'package_id' => null, 'years' => 1, 'auth_code' => null, 'price_cents' => 999, 'label' => 'Register fine.nl', 'contact_handle' => 'MOCKAAA'],
    ], 'EUR');
    $svc->markPaid($orderId);
    $svc->provision($orderId, 1); // must not throw

    $order = $svc->get($orderId, 1);
    assert($order['status'] === 'failed'); // one item failed
    $byLabel = [];
    foreach ($order['items'] as $item) {
        $byLabel[$item['data']['domain']] = $item;
    }
    assert($byLabel['taken-by-other.nl']['status'] === 'failed');
    assert(str_contains($byLabel['taken-by-other.nl']['data']['error'], 'already managed'));
    assert($byLabel['fine.nl']['status'] === 'done'); // later items still processed
    $fine = $pdo->query("SELECT * FROM domains WHERE domain = 'fine.nl'")->fetch();
    assert($fine !== false && (int) $fine['user_id'] === 1);
}
