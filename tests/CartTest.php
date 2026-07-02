<?php

declare(strict_types=1);

use Panel\Cart;

function test_cart_add_replace_remove_total(): void
{
    $_SESSION = [];
    $cart = new Cart();
    assert($cart->count() === 0 && $cart->totalCents() === 0);

    $cart->add(['type' => 'domain_register', 'domain' => 'a.nl', 'package_id' => null, 'years' => 1, 'auth_code' => null, 'price_cents' => 999, 'label' => 'Register a.nl']);
    $cart->add(['type' => 'hosting', 'domain' => 'a.nl', 'package_id' => 'starter', 'years' => 1, 'auth_code' => null, 'price_cents' => 799, 'label' => 'Starter hosting for a.nl (per month)']);
    assert($cart->count() === 2 && $cart->totalCents() === 1798);

    $cart->add(['type' => 'domain_register', 'domain' => 'a.nl', 'package_id' => null, 'years' => 2, 'auth_code' => null, 'price_cents' => 1998, 'label' => 'Register a.nl (2 years)']);
    assert($cart->count() === 2 && $cart->totalCents() === 2797); // replaced, not appended

    $cart->remove(0);
    assert($cart->count() === 1);

    $cart->clear();
    assert($_SESSION['cart'] === [] && $cart->count() === 0);
}

function test_valid_domain_name(): void
{
    assert(valid_domain_name('example.com'));
    assert(valid_domain_name('my-site.co.uk'));
    assert(!valid_domain_name('bad domain.nl'));
    assert(!valid_domain_name('-bad.nl'));
    assert(!valid_domain_name('bad-.nl'));
    assert(!valid_domain_name('nodot'));
    assert(!valid_domain_name('exämple.nl'));
}
