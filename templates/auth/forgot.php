<div class="card">
    <h1>Reset password</h1>

    <form method="post" action="/password/forgot">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>

        <button type="submit" class="button">Send reset link</button>
    </form>

    <p><a href="/login">Back to log in</a></p>
</div>
