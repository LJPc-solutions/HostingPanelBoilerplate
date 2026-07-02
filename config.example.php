<?php

declare(strict_types=1);

// Copy this file to config.php and edit the values. config.php is gitignored and
// is the single source of truth for what this panel sells and at what price.

return [
    'panel_name' => 'Example hosting',
    'base_url' => 'https://hostingpanel.ddev.site',
    'currency' => 'EUR',

    'db' => [
        'driver' => 'sqlite', // sqlite|mysql
        'sqlite_path' => __DIR__ . '/var/panel.sqlite',
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'panel',
            'username' => 'panel',
            'password' => '',
        ],
    ],

    'api' => [
        'base_url' => 'https://ljpc-hosting.nl/api',
        'key' => '', // empty = mock mode with placeholder data
    ],

    // The hosting products you sell. Each entry is one product on your storefront.
    // `category_identifier` maps the product to an LJPc hosting product, and
    // `monthly_price` is your own retail price (what you charge your customer). You
    // pay LJPc your account price for that product (including any valuepack
    // discount) and keep the difference as your margin. Add, remove, or rename
    // these freely; the panel only ever offers the products listed here.
    'packages' => [
        [
            'id' => 'starter',
            'name' => 'Starter',
            'description' => '5 GB disk space, ideal for a small website.',
            'monthly_price' => 7.99,
            'category_identifier' => 'hosting_5gb',
        ],
        [
            'id' => 'business',
            'name' => 'Business',
            'description' => '20 GB disk space for growing websites.',
            'monthly_price' => 19.99,
            'category_identifier' => 'hosting_20gb',
        ],
        [
            'id' => 'pro',
            'name' => 'Pro',
            'description' => '50 GB disk space for demanding sites.',
            'monthly_price' => 34.99,
            'category_identifier' => 'hosting_50gb',
        ],
    ],

    // The TLDs your storefront offers. The domain search only ever checks the TLDs
    // listed here, so this is also what bounds the per-search API work: every TLD
    // costs two live lookups per search (one availability, one price). The full
    // sellable catalog is around 1400 TLDs, far too many to fan out across, so keep
    // this to the handful you actually promote. Each entry must be a TLD your LJPc
    // account can sell.
    'search_tlds' => ['com', 'net', 'org', 'nl', 'eu', 'dev', 'io', 'app'],

    // Your retail domain pricing. The panel takes the price you pay LJPc for a
    // domain and turns it into the price your customer sees:
    //   - `margin_percent` is added on top for every TLD (30 means your cost times
    //     1.30).
    //   - `overrides` sets an absolute yearly retail price for a specific TLD, which
    //     wins over the margin for that TLD.
    'domain_pricing' => [
        'margin_percent' => 30,
        'overrides' => [
            // 'nl' => 9.99,
        ],
    ],

    'payment' => [
        'gateway' => 'manual', // placeholder gateway; see src/Payment/
    ],

    'mail' => [
        'mailer' => 'log', // placeholder mailer; writes to var/mail.log
        'from' => 'noreply@example.com',
    ],
];
