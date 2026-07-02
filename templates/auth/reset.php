<div class="card">
    <h1>Choose a new password</h1>

    <?php if (!empty($error)): ?>
        <div class="flash error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/password/reset/<?= e($token) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="password">New password</label>
        <input type="password" id="password" name="password" required>
        <p>At least 10 characters</p>

        <button type="submit" class="button">Change password</button>
    </form>
</div>
