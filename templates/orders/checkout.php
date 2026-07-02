<?php $currencySymbol = $config['currency'] === 'EUR' ? '€' : $config['currency']; ?>

<section class="card">
    <h1>Checkout</h1>

    <?php if ($need_contact): ?>
        <p>You need a WHOIS contact before you can register or transfer a domain.</p>
        <p><a class="button" href="/contacts/new">Create a contact first</a></p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['label']) ?></td>
                        <td><?= e($currencySymbol) ?> <?= e(number_format($item['price_cents'] / 100, 2, ',', '.')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th><?= e($currencySymbol) ?> <?= e(number_format($total_cents / 100, 2, ',', '.')) ?></th>
                </tr>
            </tfoot>
        </table>
        <p>Domain prices are per year. Hosting prices are per month.</p>

        <form method="post" action="/checkout">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <?php foreach ($items as $index => $item): ?>
                <?php if (in_array($item['type'], $domain_types, true)): ?>
                    <label for="contact-<?= e((string) $index) ?>">Contact for <?= e($item['domain']) ?></label>
                    <select id="contact-<?= e((string) $index) ?>" name="contact[<?= e((string) $index) ?>]" required>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?= e($contact['handle']) ?>"><?= e($contact['name']) ?> (<?= e($contact['handle']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            <?php endforeach; ?>

            <button type="submit" class="button">Continue to payment</button>
        </form>
    <?php endif; ?>
</section>
