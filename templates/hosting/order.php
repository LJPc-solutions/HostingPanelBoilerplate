<?php $currencySymbol = $config['currency'] === 'EUR' ? '€' : $config['currency']; ?>

<section class="card">
    <h1>Order hosting</h1>
    <form method="post" action="/hosting/order">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="grid">
            <?php foreach ($config['packages'] as $index => $package): ?>
                <label class="card">
                    <?php $isChecked = $selected_package_id !== null ? $package['id'] === $selected_package_id : $index === 0; ?>
                    <input type="radio" name="package_id" value="<?= e($package['id']) ?>" <?= $isChecked ? 'checked' : '' ?> required>
                    <h2><?= e($package['name']) ?></h2>
                    <p><?= e($package['description']) ?></p>
                    <p><?= e($currencySymbol) ?> <?= e(number_format($package['monthly_price'], 2, ',', '.')) ?> per month</p>
                </label>
            <?php endforeach; ?>
        </div>

        <label for="domain">Domain</label>
        <input type="text" id="domain" name="domain" placeholder="example.com" required>
        <p class="muted">This can be a domain already in this panel, or any other domain you own.</p>

        <button type="submit" class="button">Add to cart</button>
    </form>
</section>
