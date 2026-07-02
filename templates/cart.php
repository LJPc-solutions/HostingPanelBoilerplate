<?php $currencySymbol = $config['currency'] === 'EUR' ? '€' : $config['currency']; ?>

<section class="card">
    <h1>Your cart</h1>

    <?php if ($items === []): ?>
        <p>Your cart is empty. <a href="/search">Search for a domain</a> to get started.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Price</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td><?= e($item['label']) ?></td>
                        <td><?= e($currencySymbol) ?> <?= e(number_format($item['price_cents'] / 100, 2, ',', '.')) ?></td>
                        <td>
                            <form method="post" action="/cart/remove" class="inline">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="index" value="<?= e((string) $index) ?>">
                                <button type="submit" class="link">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th><?= e($currencySymbol) ?> <?= e(number_format($total_cents / 100, 2, ',', '.')) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
        <p>Domain prices are per year. Hosting prices are per month.</p>
    <?php endif; ?>

    <?php if ($items === []): ?>
        <a class="button" href="/checkout" aria-disabled="true" tabindex="-1">Checkout</a>
    <?php else: ?>
        <a class="button" href="/checkout">Checkout</a>
    <?php endif; ?>
</section>
