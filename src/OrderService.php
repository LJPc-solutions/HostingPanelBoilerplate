<?php

declare(strict_types=1);

namespace Panel;

use Panel\Api\ApiClient;
use Panel\Api\ApiException;
use PDO;
use PDOException;
use Throwable;

final class OrderService
{
    public function __construct(private readonly PDO $pdo, private readonly ApiClient $api)
    {
    }

    /** @param list<array<string, mixed>> $cartItems */
    public function createFromCart(int $userId, array $cartItems, string $currency): int
    {
        $total = array_sum(array_map(static fn (array $item): int => (int) $item['price_cents'], $cartItems));

        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (user_id, status, total_cents, currency, remote_order_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, 'pending_payment', $total, $currency, null, date('Y-m-d H:i:s')]);
        $orderId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO order_items (order_id, type, data, price_cents, status, remote_ref)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($cartItems as $item) {
            $stmt->execute([
                $orderId,
                (string) $item['type'],
                json_encode($item, JSON_THROW_ON_ERROR),
                (int) $item['price_cents'],
                'pending',
                null,
            ]);
        }

        return $orderId;
    }

    /** @return ?array{id: int|string, user_id: int|string, status: string, total_cents: int|string, currency: string, remote_order_id: ?string, created_at: string, items: list<array<string, mixed>>} */
    public function get(int $orderId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        if ($order === false) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
        foreach ($items as $i => $item) {
            $items[$i]['data'] = json_decode((string) $item['data'], true) ?? [];
        }
        $order['items'] = $items;

        return $order;
    }

    public function markPaid(int $orderId): void
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute(['paid', $orderId]);
    }

    public function provision(int $orderId, int $userId): void
    {
        $order = $this->get($orderId, $userId);
        if ($order === null || $order['status'] !== 'paid') {
            return;
        }

        foreach ($order['items'] as $item) {
            if ($item['status'] !== 'pending') {
                continue;
            }
            try {
                match ($item['type']) {
                    'domain_register' => $this->provisionDomain($item, $userId, false),
                    'domain_transfer' => $this->provisionDomain($item, $userId, true),
                    'hosting' => $this->provisionHosting($item, $userId),
                    default => $this->failItem($item, 'Unknown order item type.'),
                };
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $this->failItem($item, 'That domain is already managed in this panel.');
                } else {
                    error_log((string) $e);
                    $this->failItem($item, 'Provisioning failed.');
                }
            } catch (Throwable $e) {
                error_log((string) $e);
                $this->failItem($item, 'Provisioning failed.');
            }
        }

        $this->recomputeOrderStatus($orderId);
    }

    /** @return array{id: int|string, user_id: int|string, status: string, total_cents: int|string, currency: string, remote_order_id: ?string, created_at: string, items: list<array<string, mixed>>} */
    public function refreshStatus(int $orderId, int $userId): array
    {
        $order = $this->get($orderId, $userId);
        if ($order === null) {
            return ['status' => 'unknown', 'items' => []];
        }
        if ($order['status'] !== 'provisioning') {
            return $order;
        }

        foreach ($order['items'] as $item) {
            if ($item['status'] !== 'provisioning' || $item['remote_ref'] === null) {
                continue;
            }

            try {
                $remote = $this->api->getOrderStatus((string) $item['remote_ref']);
            } catch (ApiException) {
                continue; // transient poll error: leave the item provisioning
            }

            $hostingId = $item['data']['hosting_id'] ?? null;

            if ($remote['status'] === 'completed') {
                $siteId = $this->firstSiteId($remote['lines']);
                if ($hostingId !== null) {
                    $stmt = $this->pdo->prepare('UPDATE hosting SET site_id = ?, status = ? WHERE id = ?');
                    $stmt->execute([$siteId, 'active', $hostingId]);
                }
                $this->setItemStatus((int) $item['id'], 'done');
            } elseif ($remote['status'] === 'failed') {
                if ($hostingId !== null) {
                    $stmt = $this->pdo->prepare('UPDATE hosting SET status = ? WHERE id = ?');
                    $stmt->execute(['failed', $hostingId]);
                }
                $this->failItem($item, 'Provisioning failed.');
            }
        }

        $this->recomputeOrderStatus($orderId);

        return $this->get($orderId, $userId) ?? ['status' => 'unknown', 'items' => []];
    }

    /** @param array<string, mixed> $item */
    private function provisionDomain(array $item, int $userId, bool $isTransfer): void
    {
        $data = $item['data'];
        $domain = (string) $data['domain'];
        $contactHandle = (string) ($data['contact_handle'] ?? '');
        $opts = ['external_reference' => (string) $userId];

        try {
            if ($isTransfer) {
                $result = $this->api->transferDomain($domain, (string) ($data['auth_code'] ?? ''), $contactHandle, $opts);
                $domainStatus = 'transfer_pending';
            } else {
                $opts['years'] = (int) ($data['years'] ?? 1);
                $result = $this->api->registerDomain($domain, $contactHandle, $opts);
                $domainStatus = 'active';
            }
        } catch (ApiException $e) {
            $this->failItem($item, $e->getMessage());

            return;
        }

        if (($result['success'] ?? false) !== true) {
            $this->failItem($item, $result['message'] ?? 'Domain provisioning failed.');

            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO domains (user_id, domain, status, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $domain, $domainStatus, date('Y-m-d H:i:s')]);

        $stmt = $this->pdo->prepare('UPDATE order_items SET status = ?, remote_ref = ? WHERE id = ?');
        $stmt->execute(['done', $domain, (int) $item['id']]);
    }

    /** @param array<string, mixed> $item */
    private function provisionHosting(array $item, int $userId): void
    {
        $data = $item['data'];
        $domain = (string) $data['domain'];
        $categoryIdentifier = (string) ($data['category_identifier'] ?? '');
        if ($categoryIdentifier === '') {
            $this->failItem($item, 'Unknown hosting package.');

            return;
        }

        try {
            $remote = $this->api->createHosting($domain, $categoryIdentifier, ['external_reference' => (string) $userId]);
        } catch (ApiException $e) {
            $this->failItem($item, $e->getMessage());

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hosting (user_id, site_id, domain, package_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, null, $domain, (string) ($data['package_id'] ?? ''), 'pending', date('Y-m-d H:i:s')]);
        $hostingId = (int) $this->pdo->lastInsertId();

        $data['hosting_id'] = $hostingId;
        $stmt = $this->pdo->prepare('UPDATE order_items SET status = ?, remote_ref = ?, data = ? WHERE id = ?');
        $stmt->execute(['provisioning', (string) $remote['order_id'], json_encode($data, JSON_THROW_ON_ERROR), (int) $item['id']]);
    }

    /** @param array<string, mixed> $item */
    private function failItem(array $item, string $message): void
    {
        $data = $item['data'];
        $data['error'] = $message;
        $stmt = $this->pdo->prepare('UPDATE order_items SET status = ?, data = ? WHERE id = ?');
        $stmt->execute(['failed', json_encode($data, JSON_THROW_ON_ERROR), (int) $item['id']]);
    }

    private function setItemStatus(int $itemId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE order_items SET status = ? WHERE id = ?');
        $stmt->execute([$status, $itemId]);
    }

    /** @param list<array{type: string, status: string, site_id: ?string}> $lines */
    private function firstSiteId(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (($line['site_id'] ?? null) !== null) {
                return (string) $line['site_id'];
            }
        }

        return null;
    }

    private function recomputeOrderStatus(int $orderId): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $status = match (true) {
            in_array('failed', $statuses, true) => 'failed',
            in_array('provisioning', $statuses, true) || in_array('pending', $statuses, true) => 'provisioning',
            default => 'completed',
        };

        $stmt = $this->pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute([$status, $orderId]);
    }
}
