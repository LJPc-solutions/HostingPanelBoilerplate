<?php $currencySymbol = $config['currency'] === 'EUR' ? '€' : $config['currency']; ?>

<section class="hero">
    <h1>Domain search</h1>
    <form action="/search" method="get" class="search-form">
        <input type="text" name="q" value="<?= e($query) ?>" placeholder="Find your domain..." aria-label="Domain name">
        <button type="submit" class="button">Search</button>
    </form>
</section>

<?php if ($results !== []): ?>
    <section class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Status</th>
                    <th>Price</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?= e($row['domain']) ?></td>
                        <td>
                            <?php if ($row['available'] === true): ?>
                                <span class="badge available">Available</span>
                            <?php elseif ($row['available'] === false): ?>
                                <span class="badge taken">Taken</span>
                            <?php else: ?>
                                <span class="badge unknown">Unknown</span>
                            <?php endif; ?>
                            <?php if ($row['premium']): ?>
                                <span class="badge premium">Premium</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['sell_price'] !== null): ?>
                                <?= e($currencySymbol) ?> <?= e(number_format($row['sell_price'], 2, ',', '.')) ?> per year
                            <?php else: ?>
                                Price unavailable
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user === null): ?>
                                <?php if ($row['available'] === true || $row['available'] === false): ?>
                                    <a class="button secondary" href="/register">Create account</a>
                                <?php endif; ?>
                            <?php elseif ($row['available'] === true): ?>
                                <form method="post" action="/cart/add" class="inline">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="type" value="domain_register">
                                    <input type="hidden" name="domain" value="<?= e($row['domain']) ?>">
                                    <input type="hidden" name="years" value="1">
                                    <button type="submit" class="button">Add to cart</button>
                                </form>
                            <?php elseif ($row['available'] === false): ?>
                                <form method="post" action="/cart/add" class="inline">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="type" value="domain_transfer">
                                    <input type="hidden" name="domain" value="<?= e($row['domain']) ?>">
                                    <input type="hidden" name="years" value="1">
                                    <input type="text" name="auth_code" placeholder="Auth code" maxlength="32" required>
                                    <button type="submit" class="button secondary">Transfer</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($user === null): ?>
            <p>Create an account to order domains.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
