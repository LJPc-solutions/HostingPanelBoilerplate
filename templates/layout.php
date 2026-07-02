<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $config['panel_name']) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/"><?= e($config['panel_name']) ?></a>
    <nav>
        <?php if ($user !== null): ?>
            <a href="/dashboard">Dashboard</a>
            <a href="/domains">Domains</a>
            <a href="/hosting">Hosting</a>
            <a href="/contacts">Contacts</a>
            <a href="/cart">Cart</a>
            <a href="/account"><?= e($user['name']) ?></a>
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="link">Log out</button>
            </form>
        <?php else: ?>
            <a href="/login">Log in</a>
            <a href="/register">Register</a>
        <?php endif; ?>
    </nav>
</header>
<main>
    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="flash <?= e($_SESSION['flash']['type']) ?>"><?= e($_SESSION['flash']['message']) ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
    <?= $content ?>
</main>
<footer class="site-footer">Powered by <?= e($config['panel_name']) ?></footer>
<script src="/assets/panel.js"></script>
</body>
</html>
