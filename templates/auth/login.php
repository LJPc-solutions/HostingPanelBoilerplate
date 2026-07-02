<div class="card">
    <h1>Log in</h1>

    <?php if (!empty($error)): ?>
        <div class="flash error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="button">Log in</button>
    </form>

    <p><a href="/register">Don't have an account? Register</a></p>
    <p><a href="/password/forgot">Forgot your password?</a></p>
</div>
