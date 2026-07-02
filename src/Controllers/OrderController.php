<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\App;
use Panel\Cart;
use Panel\OrderService;
use Panel\Response;

final class OrderController
{
    private const DOMAIN_TYPES = ['domain_register', 'domain_transfer'];

    private readonly OrderService $orders;

    public function __construct(private readonly App $app)
    {
        $this->orders = new OrderService($app->pdo, $app->api);
    }

    public function showCheckout(array $params): Response
    {
        $items = (new Cart())->items();
        if ($items === []) {
            return Response::redirect('/cart');
        }

        $contacts = $this->userContacts();
        $needContact = $this->hasDomainItems($items) && $contacts === [];

        return Response::html($this->app->view->render('orders/checkout', [
            'title' => 'Checkout',
            'items' => $items,
            'contacts' => $contacts,
            'need_contact' => $needContact,
            'domain_types' => self::DOMAIN_TYPES,
            'total_cents' => (new Cart())->totalCents(),
        ]));
    }

    public function checkout(array $params): Response
    {
        $items = (new Cart())->items();
        if ($items === []) {
            return Response::redirect('/cart');
        }

        $ownedHandles = array_column($this->userContacts(), 'handle');
        $selected = is_array($_POST['contact'] ?? null) ? $_POST['contact'] : [];

        foreach ($items as $index => $item) {
            if (!in_array($item['type'], self::DOMAIN_TYPES, true)) {
                continue;
            }
            $handle = (string) ($selected[$index] ?? '');
            if (!in_array($handle, $ownedHandles, true)) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please select a valid contact for each domain.'];

                return Response::redirect('/checkout');
            }
            $items[$index]['contact_handle'] = $handle;
        }

        $orderId = $this->orders->createFromCart(
            (int) $this->user()['id'],
            $items,
            $this->app->config['currency'],
        );

        return Response::redirect('/order/pay/' . $orderId);
    }

    public function pay(array $params): Response
    {
        $order = $this->orders->get((int) $params['id'], (int) $this->user()['id']);
        if ($order === null) {
            return $this->notFound();
        }
        if ($order['status'] !== 'pending_payment') {
            return Response::redirect('/order/status/' . $order['id']);
        }

        $returnUrl = $this->app->config['base_url'] . '/order/return/' . $order['id'];
        $url = $this->app->payment->start($order, $returnUrl);

        return Response::redirect($url);
    }

    public function paymentReturn(array $params): Response
    {
        $userId = (int) $this->user()['id'];
        $order = $this->orders->get((int) $params['id'], $userId);
        if ($order === null) {
            return $this->notFound();
        }
        if ($order['status'] !== 'pending_payment') {
            return Response::redirect('/order/status/' . $order['id']);
        }

        if (!$this->app->payment->confirm($order, $_GET)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Payment was not completed. Please try again.'];

            return Response::redirect('/cart');
        }

        $this->orders->markPaid((int) $order['id']);
        $this->orders->provision((int) $order['id'], $userId);
        (new Cart())->clear();

        return Response::redirect('/order/status/' . $order['id']);
    }

    public function status(array $params): Response
    {
        $order = $this->orders->get((int) $params['id'], (int) $this->user()['id']);
        if ($order === null) {
            return $this->notFound();
        }

        return Response::html($this->app->view->render('orders/status', [
            'title' => 'Order #' . $order['id'],
            'order' => $order,
        ]));
    }

    public function statusJson(array $params): Response
    {
        $userId = (int) $this->user()['id'];
        if ($this->orders->get((int) $params['id'], $userId) === null) {
            return $this->notFound();
        }

        $result = $this->orders->refreshStatus((int) $params['id'], $userId);

        return Response::json([
            'status' => $result['status'],
            'items' => array_map(static fn (array $item): array => [
                'label' => (string) ($item['data']['label'] ?? ''),
                'status' => (string) $item['status'],
            ], $result['items']),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function userContacts(): array
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM contacts WHERE user_id = ? ORDER BY name');
        $stmt->execute([$this->user()['id']]);

        return $stmt->fetchAll();
    }

    /** @param list<array<string, mixed>> $items */
    private function hasDomainItems(array $items): bool
    {
        foreach ($items as $item) {
            if (in_array($item['type'], self::DOMAIN_TYPES, true)) {
                return true;
            }
        }

        return false;
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
