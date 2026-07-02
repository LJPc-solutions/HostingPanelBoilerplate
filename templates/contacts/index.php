<section class="card">
    <h1>Contacts</h1>

    <?php if ($contacts === []): ?>
        <p>No contacts yet.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Handle</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <tr>
                        <td><?= e($contact['name']) ?></td>
                        <td><?= e($contact['handle']) ?></td>
                        <td><a href="/contacts/<?= e((string) $contact['id']) ?>/edit">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p><a class="button" href="/contacts/new">New contact</a></p>
</section>
