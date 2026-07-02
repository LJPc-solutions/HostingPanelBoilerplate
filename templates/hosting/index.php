<?php
$badgeClass = static function (string $status): string {
    return match (true) {
        str_contains($status, 'pending') => 'pending',
        str_contains($status, 'fail'), str_contains($status, 'expired'), str_contains($status, 'cancel') => 'failed',
        default => 'active',
    };
};
?>
<section class="card">
    <h1>Hosting</h1>

    <?php if ($rows === []): ?>
        <p>You don't have any hosting yet. <a href="/hosting/order">Order hosting</a> to get started.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Usage</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['domain']) ?></td>
                        <td><?= e($row['package_name']) ?></td>
                        <td><span class="badge <?= e($badgeClass($row['status'])) ?>"><?= e($row['status']) ?></span></td>
                        <td>
                            <?php if ($row['usage'] !== null && $row['usage']['unlimited']): ?>
                                Unlimited
                            <?php elseif ($row['usage'] !== null): ?>
                                <div class="usage-bar"><div class="usage-bar-fill" style="width: <?= e((string) $row['usage']['percent']) ?>%"></div></div>
                                <span class="muted"><?= e(format_bytes($row['usage']['used_bytes'])) ?> of <?= e(format_bytes($row['usage']['quota_bytes'])) ?></span>
                            <?php elseif ($row['usage_unavailable']): ?>
                                <span class="muted">Usage unavailable</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="/hosting/<?= e((string) $row['id']) ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
