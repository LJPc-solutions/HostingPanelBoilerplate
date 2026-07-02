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
    <h1><?= e($hosting['domain']) ?></h1>
    <p>
        <span class="badge <?= e($badgeClass($hosting['status'])) ?>"><?= e($hosting['status']) ?></span>
        <?= e($package_name) ?>
    </p>
</section>

<?php if ($pending): ?>
    <section class="card">
        <p class="muted">This hosting account is still being set up. Usage details and the Plesk login will be available once it's active.</p>
    </section>
<?php else: ?>
    <section class="card">
        <h2>Disk usage</h2>
        <?php if ($usage !== null && $usage['unlimited']): ?>
            <p>Unlimited</p>
        <?php elseif ($usage !== null): ?>
            <div class="usage-bar"><div class="usage-bar-fill" style="width: <?= e((string) $usage['percent']) ?>%"></div></div>
            <p class="muted"><?= e(format_bytes($usage['used_bytes'])) ?> of <?= e(format_bytes($usage['quota_bytes'])) ?> used</p>
        <?php else: ?>
            <p class="muted">Usage unavailable</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <a class="button" href="/hosting/<?= e((string) $hosting['id']) ?>/login">Log in to Plesk</a>
    </section>
<?php endif; ?>
