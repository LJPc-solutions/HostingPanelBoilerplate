// Panel JS

document.addEventListener('DOMContentLoaded', function () {
    var addBtn = document.getElementById('dns-add-row');
    var body = document.getElementById('dns-rows');
    var tpl = document.getElementById('dns-row-template');
    if (!addBtn || !body || !tpl) return;
    var n = body.querySelectorAll('tr[data-row]').length;
    addBtn.addEventListener('click', function () {
        var row = tpl.cloneNode(true);
        row.removeAttribute('id');
        row.hidden = false;
        row.setAttribute('data-row', '');
        row.querySelectorAll('[disabled]').forEach(function (el) {
            el.disabled = false;
            el.name = el.name.replace('__N__', String(n));
        });
        body.insertBefore(row, tpl);
        n++;
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const el = document.querySelector('[data-order-status-url]');
    if (!el) return;
    const maxFailures = 100;
    let failures = 0;
    const poll = () => fetch(el.dataset.orderStatusUrl)
        .then(r => {
            if (!r.ok) throw new Error('poll failed');
            return r.json();
        })
        .then(data => {
            failures = 0;
            if (data.status !== 'provisioning') {
                window.location.reload();
            } else {
                setTimeout(poll, 3000);
            }
        })
        .catch(() => {
            failures++;
            if (failures < maxFailures) {
                setTimeout(poll, 5000);
            }
        });
    setTimeout(poll, 3000);
});
