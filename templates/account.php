<?php
$field = static function (string $key) use ($old, $user): string {
    return (string) ($old[$key] ?? $user[$key] ?? '');
};
?>
<div class="card">
    <h1>Account</h1>

    <?php if (!empty($error)): ?>
        <div class="flash error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/account">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($field('name')) ?>" required>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($field('email')) ?>" required>

        <h2>Change password</h2>
        <p class="muted">Leave blank to keep your current password.</p>

        <label for="password">New password</label>
        <input type="password" id="password" name="password">
        <p>At least 10 characters</p>

        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password">
        <p>Required to set a new password</p>

        <button type="submit" class="button">Save account</button>
    </form>
</div>
