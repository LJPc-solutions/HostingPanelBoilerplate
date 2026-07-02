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
    <h1><?= e($domain) ?></h1>
    <p>
        <span class="badge <?= e($badgeClass($live['status'])) ?>"><?= e($live['status']) ?></span>
        <?php if ($live['expires_at'] !== null): ?>
            Expires <?= e($live['expires_at']) ?>
        <?php endif; ?>
    </p>
    <a class="button secondary" href="/domains/<?= e($domain) ?>/dns">Manage DNS</a>
</section>

<section class="card">
    <h2>Settings</h2>
    <form method="post" action="/domains/<?= e($domain) ?>/settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="nameservers">Nameservers</label>
        <textarea id="nameservers" name="nameservers" rows="4" required><?= e(implode("\n", $live['nameservers'])) ?></textarea>

        <label class="checkbox-label">
            <input type="checkbox" name="auto_renew" value="1" <?= $live['auto_renew'] ? 'checked' : '' ?>>
            Auto-renew
        </label>

        <label class="checkbox-label">
            <input type="checkbox" name="transfer_lock" value="1" <?= $live['transfer_lock'] ? 'checked' : '' ?>>
            Transfer lock
        </label>

        <label for="contact_handle">WHOIS contact</label>
        <select id="contact_handle" name="contact_handle">
            <?php foreach ($contacts as $contact): ?>
                <option value="<?= e($contact['handle']) ?>" <?= $contact['handle'] === $live['contact_handle'] ? 'selected' : '' ?>><?= e($contact['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="button">Save settings</button>
    </form>
</section>

<section class="card">
    <h2>Auth code</h2>
    <?php if ($auth_code !== null): ?>
        <p><code><?= e($auth_code) ?></code></p>
        <div class="flash error">Anyone with this code can transfer your domain away.</div>
    <?php else: ?>
        <form method="post" action="/domains/<?= e($domain) ?>/authcode">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="button secondary">Reveal auth code</button>
        </form>
    <?php endif; ?>
</section>
