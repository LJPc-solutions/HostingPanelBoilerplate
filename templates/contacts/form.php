<?php
$field = static function (string $key) use ($old, $contact): string {
    return (string) ($old[$key] ?? $contact[$key] ?? '');
};
?>
<div class="card">
    <h1><?= e($title) ?></h1>

    <?php if (!empty($error)): ?>
        <div class="flash error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($field('name')) ?>" required>

        <label for="organization">Organization</label>
        <input type="text" id="organization" name="organization" value="<?= e($field('organization')) ?>">

        <label for="address">Address</label>
        <input type="text" id="address" name="address" value="<?= e($field('address')) ?>" required>

        <label for="postal_code">Postal code</label>
        <input type="text" id="postal_code" name="postal_code" value="<?= e($field('postal_code')) ?>" required>

        <label for="city">City</label>
        <input type="text" id="city" name="city" value="<?= e($field('city')) ?>" required>

        <label for="country">Country</label>
        <select id="country" name="country" required>
            <option value="">Choose a country</option>
            <?php foreach ($countries as $code): ?>
                <option value="<?= e($code) ?>" <?= $field('country') === $code ? 'selected' : '' ?>><?= e($code) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($field('email')) ?>" required>

        <label for="voice">Phone</label>
        <input type="text" id="voice" name="voice" value="<?= e($field('voice')) ?>" placeholder="+31612345678" required>

        <button type="submit" class="button">Save contact</button>
    </form>
</div>
