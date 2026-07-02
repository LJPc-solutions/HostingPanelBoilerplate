<?php
$types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];
?>
<section class="card">
    <h1>DNS records for <?= e($domain) ?></h1>

    <?php if ($errors !== []): ?>
        <div class="flash error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/domains/<?= e($domain) ?>/dns">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <table class="table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Content</th>
                    <th>TTL</th>
                    <th>Priority</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody id="dns-rows">
                <?php foreach ($records as $i => $record): ?>
                    <tr data-row>
                        <td>
                            <select name="records[<?= e((string) $i) ?>][type]">
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= e($type) ?>" <?= $record['type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="records[<?= e((string) $i) ?>][name]" value="<?= e($record['name']) ?>"></td>
                        <td><input type="text" name="records[<?= e((string) $i) ?>][content]" value="<?= e($record['content']) ?>"></td>
                        <td><input type="number" name="records[<?= e((string) $i) ?>][ttl]" value="<?= e($record['ttl']) ?>"></td>
                        <td><input type="text" name="records[<?= e((string) $i) ?>][prio]" value="<?= e($record['prio']) ?>"></td>
                        <td><label class="checkbox-label"><input type="checkbox" name="records[<?= e((string) $i) ?>][delete]" value="1"> Delete</label></td>
                    </tr>
                <?php endforeach; ?>
                <tr id="dns-row-template" hidden>
                    <td>
                        <select name="records[__N__][type]" disabled>
                            <?php foreach ($types as $type): ?>
                                <option value="<?= e($type) ?>"><?= e($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="records[__N__][name]" disabled></td>
                    <td><input type="text" name="records[__N__][content]" disabled></td>
                    <td><input type="number" name="records[__N__][ttl]" value="3600" disabled></td>
                    <td><input type="text" name="records[__N__][prio]" disabled></td>
                    <td><label class="checkbox-label"><input type="checkbox" name="records[__N__][delete]" value="1" disabled> Delete</label></td>
                </tr>
            </tbody>
        </table>

        <button type="button" id="dns-add-row" class="button secondary">Add row</button>
        <button type="submit" class="button">Save DNS records</button>
    </form>
</section>
