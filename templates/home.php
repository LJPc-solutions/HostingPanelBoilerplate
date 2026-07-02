<?php $currencySymbol = $config['currency'] === 'EUR' ? '€' : $config['currency']; ?>

<section class="hero">
    <h1>Fast, reliable hosting from <?= e($config['panel_name']) ?></h1>
    <p>Find your domain and get your site live today.</p>
    <form action="/search" method="get" class="search-form">
        <input type="text" name="q" placeholder="Find your domain..." aria-label="Domain name">
        <button type="submit" class="button">Search</button>
    </form>
</section>

<section class="packages">
    <h2>Hosting packages</h2>
    <div class="grid">
        <?php foreach ($packages as $package): ?>
            <div class="card">
                <h3><?= e($package['name']) ?></h3>
                <p><?= e($package['description']) ?></p>
                <p class="price"><?= e($currencySymbol) ?> <?= e(number_format((float) $package['monthly_price'], 2, ',', '.')) ?> per month</p>
                <a class="button" href="/hosting/order?package=<?= e($package['id']) ?>">Order now</a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
