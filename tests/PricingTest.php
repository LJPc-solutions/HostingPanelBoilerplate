<?php

declare(strict_types=1);

use Panel\Pricing;

function panel_test_pricing(): Pricing
{
    return new Pricing(
        ['margin_percent' => 30, 'overrides' => ['nl' => 9.99]],
        [['id' => 'starter', 'name' => 'Starter', 'monthly_price' => 7.99, 'category_identifier' => 'hosting_5gb', 'description' => '']],
        'EUR'
    );
}

function test_pricing_margin_and_overrides(): void
{
    $p = panel_test_pricing();
    assert($p->sellPriceForTld('com', 10.00) === 13.00);
    assert($p->sellPriceForTld('nl', 6.50) === 9.99);
    assert($p->sellPriceForTld('xyz', null) === null);
    assert($p->sellPriceForDomain('shop.example.co.uk', 10.00) === 13.00); // tld = co.uk -> after first dot
}

function test_pricing_packages(): void
{
    $p = panel_test_pricing();
    assert($p->package('starter')['category_identifier'] === 'hosting_5gb');
    assert($p->package('nope') === null);
    assert($p->packageMonthlyCents('starter') === 799);
}
