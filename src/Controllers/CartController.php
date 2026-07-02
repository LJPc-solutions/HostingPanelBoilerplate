<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\App;
use Panel\Cart;
use Panel\Response;

final class CartController
{
    private const TYPES = ['domain_register', 'domain_transfer', 'hosting'];

    public function __construct(private readonly App $app)
    {
    }

    public function add(array $params): Response
    {
        $type = (string) ($_POST['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return $this->back('Please choose a valid item type.');
        }

        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        if (!valid_domain_name($domain)) {
            return $this->back('Please enter a valid domain name.');
        }

        $years = min(10, max(1, (int) ($_POST['years'] ?? 1)));

        if ($type === 'hosting') {
            $item = $this->hostingItem($domain, $years);
        } else {
            $item = $this->domainItem($type, $domain, $years);
        }

        if (is_string($item)) {
            return $this->back($item);
        }

        (new Cart())->add($item);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Added to your cart.'];

        return Response::redirect('/cart');
    }

    public function remove(array $params): Response
    {
        $cart = new Cart();
        $cart->remove((int) ($_POST['index'] ?? -1));
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Item removed from your cart.'];

        return Response::redirect('/cart');
    }

    public function show(array $params): Response
    {
        $cart = new Cart();

        return Response::html($this->app->view->render('cart', [
            'title' => 'Cart',
            'items' => $cart->items(),
            'total_cents' => $cart->totalCents(),
        ]));
    }

    /** @return array|string item on success, error message on failure */
    private function domainItem(string $type, string $domain, int $years): array|string
    {
        $authCode = null;
        if ($type === 'domain_transfer') {
            $authCode = trim((string) ($_POST['auth_code'] ?? ''));
            $length = strlen($authCode);
            if ($length < 4 || $length > 32) {
                return 'Please provide a valid auth code (4 to 32 characters).';
            }
        }

        $check = $this->app->api->checkDomain($domain);
        $sellPrice = $this->app->pricing->sellPriceForDomain($domain, $check['price']);
        if ($sellPrice === null) {
            return 'Unable to determine a price for that domain.';
        }

        if ($type === 'domain_register' && $check['available'] !== true) {
            return 'That domain is not available for registration.';
        }

        if ($type === 'domain_transfer' && $check['available'] !== false) {
            return 'That domain cannot be transferred.';
        }

        $label = $type === 'domain_transfer'
            ? 'Transfer ' . $domain
            : ('Register ' . $domain . ($years > 1 ? " ({$years} years)" : ''));

        return [
            'type' => $type,
            'domain' => $domain,
            'package_id' => null,
            'years' => $years,
            'auth_code' => $authCode,
            'price_cents' => (int) round($sellPrice * 100),
            'label' => $label,
        ];
    }

    /** @return array|string item on success, error message on failure */
    private function hostingItem(string $domain, int $years): array|string
    {
        $packageId = (string) ($_POST['package_id'] ?? '');
        $package = $this->app->pricing->package($packageId);
        if ($package === null) {
            return 'Please choose a valid hosting package.';
        }

        return [
            'type' => 'hosting',
            'domain' => $domain,
            'package_id' => $packageId,
            'years' => $years,
            'auth_code' => null,
            'price_cents' => $this->app->pricing->packageMonthlyCents($packageId),
            'label' => $package['name'] . ' hosting for ' . $domain . ' (per month)',
            'category_identifier' => $package['category_identifier'],
        ];
    }

    private function back(string $message): Response
    {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $message];

        return Response::redirect('/search');
    }
}
