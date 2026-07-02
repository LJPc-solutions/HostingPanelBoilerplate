<?php
$currencySymbol = $config['currency'] === 'EUR' ? '€' : $config['currency'];
$badgeClass = static function (string $status): string {
    return match ($status) {
        'completed' => 'active',
        'failed' => 'failed',
        default => 'pending',
    };
};
?>
<section class="card">
    <h1>Dashboard</h1>

    <div class="grid">
        <div class="card">
            <h3>Domains</h3>
            <p class="price"><?= e((string) $domains_count) ?></p>
            <a href="/domains">View domains</a>
        </div>
        <div class="card">
            <h3>Hosting</h3>
            <p class="price"><?= e((string) $hosting_count) ?></p>
            <a href="/hosting">View hosting</a>
        </div>
        <div class="card">
            <h3>Open orders</h3>
            <p class="price"><?= e((string) $open_orders_count) ?></p>
        </div>
    </div>
</section>

<section class="card">
    <h2>Recent orders</h2>

    <?php if ($recent_orders === []): ?>
        <p>You don't have any orders yet.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_orders as $order): ?>
                    <tr>
                        <td>#<?= e((string) $order['id']) ?></td>
                        <td><?= e($order['created_at']) ?></td>
                        <td><?= e($currencySymbol) ?> <?= e(number_format($order['total_cents'] / 100, 2, ',', '.')) ?></td>
                        <td><span class="badge <?= e($badgeClass($order['status'])) ?>"><?= e($order['status']) ?></span></td>
                        <td><a href="/order/status/<?= e((string) $order['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Quick links</h2>
    <p>
        <a class="button" href="/search">Search domains</a>
        <a class="button" href="/hosting/order">Order hosting</a>
        <a class="button" href="/contacts/new">New contact</a>
    </p>
</section>
