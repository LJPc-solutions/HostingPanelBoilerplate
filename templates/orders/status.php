<?php
$currencySymbol = $config['currency'] === 'EUR' ? '€' : $config['currency'];
$badgeClass = static function (string $status): string {
    return match ($status) {
        'done' => 'active',
        'failed' => 'failed',
        default => 'pending',
    };
};
?>

<section class="card">
    <h1>Order #<?= e((string) $order['id']) ?></h1>

    <?php if ($order['status'] === 'completed'): ?>
        <div class="flash success">Your order is complete.</div>
    <?php elseif ($order['status'] === 'provisioning'): ?>
        <div class="flash info" data-order-status-url="/order/status/<?= e((string) $order['id']) ?>/json">
            We are setting things up. This page updates automatically.
        </div>
    <?php elseif ($order['status'] === 'failed'): ?>
        <div class="flash error">Some items could not be completed. See the details below.</div>
    <?php endif; ?>

    <table class="table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Price</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($order['items'] as $item): ?>
                <tr>
                    <td>
                        <?= e((string) ($item['data']['label'] ?? $item['type'])) ?>
                        <?php if ($item['status'] === 'failed' && isset($item['data']['error'])): ?>
                            <div class="muted"><?= e((string) $item['data']['error']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($currencySymbol) ?> <?= e(number_format($item['price_cents'] / 100, 2, ',', '.')) ?></td>
                    <td><span class="badge <?= e($badgeClass($item['status'])) ?>"><?= e($item['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th>Total</th>
                <th><?= e($currencySymbol) ?> <?= e(number_format($order['total_cents'] / 100, 2, ',', '.')) ?></th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    <p><a href="/domains">View your domains</a> or <a href="/hosting">manage your hosting</a>.</p>
</section>
