<?php
/**
 * Admin — Network Devices
 * Live up/down status of the shop devices (192.168.100.0/24), polled from the
 * MikroTik router by bin/poll-devices.php and refreshed here via /api/devices.php.
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();
$devices = $pdo->query(
    "SELECT name, ip, status, latency_ms, last_seen_at, last_checked_at
       FROM devices ORDER BY sort_order, id"
)->fetchAll();

// A device is "stale" (poller not reporting) if not checked in this many seconds.
$staleAfter = 180;

/** Human "time ago" from a datetime string. */
function device_ago(?string $ts): string
{
    if (!$ts) {
        return te('dev_never');
    }
    $secs = time() - strtotime($ts);
    if ($secs < 0)     { $secs = 0; }
    if ($secs < 60)    { return $secs . 's'; }
    if ($secs < 3600)  { return floor($secs / 60) . 'm'; }
    if ($secs < 86400) { return floor($secs / 3600) . 'h'; }
    return floor($secs / 86400) . 'd';
}

$pageTitle = t('devices_title');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h1><i class="fas fa-network-wired"></i> <?= te('devices_title') ?></h1>
    <button id="devCheckNow" class="btn btn-primary">
        <i class="fas fa-rotate"></i> <?= te('dev_check_now') ?>
    </button>
</div>
<p class="text-muted" style="margin-top:-8px;"><?= te('devices_subtitle') ?></p>

<div class="card">
    <table class="data-table" id="devTable">
        <thead>
            <tr>
                <th><?= te('dev_device') ?></th>
                <th><?= te('dev_ip') ?></th>
                <th><?= te('dev_status') ?></th>
                <th><?= te('dev_latency') ?></th>
                <th><?= te('dev_last_seen') ?></th>
                <th><?= te('dev_last_check') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($devices)): ?>
                <tr><td colspan="6" class="text-center text-muted" style="padding:40px;"><?= te('dev_none') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($devices as $d):
                $stale = !$d['last_checked_at'] || (time() - strtotime($d['last_checked_at']) > $staleAfter);
                $st = $stale ? 'unknown' : $d['status'];
                $badge = $st === 'up' ? 'success' : ($st === 'down' ? 'danger' : 'warning');
                $label = $st === 'up' ? te('dev_up') : ($st === 'down' ? te('dev_down') : te('dev_unknown'));
            ?>
                <tr data-ip="<?= htmlspecialchars($d['ip']) ?>">
                    <td><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($d['ip']) ?></code></td>
                    <td class="dev-status"><span class="badge badge-<?= $badge ?>"><?= $label ?></span></td>
                    <td class="dev-latency"><?= ($st === 'up' && $d['latency_ms'] !== null) ? number_format((float)$d['latency_ms'], 1) . ' ms' : '<span class="text-muted">-</span>' ?></td>
                    <td class="dev-seen text-muted"><small><?= htmlspecialchars(device_ago($d['last_seen_at'])) ?></small></td>
                    <td class="dev-check text-muted"><small><?= htmlspecialchars(device_ago($d['last_checked_at'])) ?></small></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    var STALE_AFTER = <?= (int) $staleAfter ?>;
    var L = {
        up: <?= json_encode(te('dev_up')) ?>,
        down: <?= json_encode(te('dev_down')) ?>,
        unknown: <?= json_encode(te('dev_unknown')) ?>,
        never: <?= json_encode(te('dev_never')) ?>
    };

    function ago(ts) {
        if (!ts) { return L.never; }
        var s = Math.floor((Date.now() - new Date(ts.replace(' ', 'T')).getTime()) / 1000);
        if (s < 0) { s = 0; }
        if (s < 60) { return s + 's'; }
        if (s < 3600) { return Math.floor(s / 60) + 'm'; }
        if (s < 86400) { return Math.floor(s / 3600) + 'h'; }
        return Math.floor(s / 86400) + 'd';
    }

    function paint(d) {
        var row = document.querySelector('#devTable tr[data-ip="' + d.ip + '"]');
        if (!row) { return; }
        var stale = !d.last_checked_at ||
            (Date.now() - new Date(d.last_checked_at.replace(' ', 'T')).getTime() > STALE_AFTER * 1000);
        var st = stale ? 'unknown' : d.status;
        var badge = st === 'up' ? 'success' : (st === 'down' ? 'danger' : 'warning');
        var label = st === 'up' ? L.up : (st === 'down' ? L.down : L.unknown);
        row.querySelector('.dev-status').innerHTML = '<span class="badge badge-' + badge + '">' + label + '</span>';
        row.querySelector('.dev-latency').innerHTML =
            (st === 'up' && d.latency_ms !== null) ? (parseFloat(d.latency_ms).toFixed(1) + ' ms') : '<span class="text-muted">-</span>';
        row.querySelector('.dev-seen').innerHTML = '<small>' + ago(d.last_seen_at) + '</small>';
        row.querySelector('.dev-check').innerHTML = '<small>' + ago(d.last_checked_at) + '</small>';
    }

    function refresh() {
        fetch('/api/devices.php', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (j && j.ok && j.devices) { j.devices.forEach(paint); } })
            .catch(function () {});
    }

    var btn = document.getElementById('devCheckNow');
    if (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            var original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + <?= json_encode(te('dev_checking')) ?>;
            fetch('/api/devices.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ poll: 1 })
            })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.devices) { j.devices.forEach(paint); }
                    if (j && j.poll && j.poll.ok === false && window.showToast) {
                        showToast(<?= json_encode(te('dev_router_unreachable')) ?>, 'error');
                    }
                })
                .catch(function () {})
                .finally(function () { btn.disabled = false; btn.innerHTML = original; });
        });
    }

    setInterval(refresh, 10000); // live refresh every 10s
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
