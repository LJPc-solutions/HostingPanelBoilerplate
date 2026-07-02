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
    <h1>Domains</h1>

    <?php if ($domains === []): ?>
        <p>You don't have any domains yet. <a href="/search">Search for a domain</a> to get started.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Status</th>
                    <th>Expires</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($domains as $row): ?>
                    <tr>
                        <td><?= e($row['domain']) ?></td>
                        <td>
                            <span class="badge <?= e($badgeClass($row['status'])) ?>"><?= e($row['status']) ?></span>
                            <?php if ($row['unavailable']): ?>
                                <span class="muted">Details unavailable</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['expires_at'] !== null ? e($row['expires_at']) : 'Unknown' ?></td>
                        <td><a href="/domains/<?= e($row['domain']) ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
