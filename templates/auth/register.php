<div class="card">
    <h1>Create your account</h1>

    <?php if (!empty($error)): ?>
        <div class="flash error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/register">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($old['name'] ?? '') ?>" required>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
        <p>At least 10 characters</p>

        <button type="submit" class="button">Create account</button>
    </form>

    <p><a href="/login">Already have an account? Log in</a></p>
</div>
