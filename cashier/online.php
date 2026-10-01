<?php
/**
 * Cassa — Ordini online: the till's own panel for the online customers'
 * orders (the shop with no tables). Every open online order with the
 * customer, their intolerances, the dishes and whether the kitchen has it
 * ready; "Collect" opens the usual payment page.
 *
 * The customer shows the order's QR (their page, or the "ready" WhatsApp):
 * a barcode scanner types its link into the scan box, or the tablet's camera
 * reads it; ?pay=<token> (also what a phone camera opens) goes straight to
 * that order's payment.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/online_order.php';
requireRole(['admin', 'cashier']);

$pdo = getDBConnection();

// A scanned QR: straight to the order's payment.
$scanError = null;
$scan = trim((string) ($_GET['pay'] ?? $_GET['scan'] ?? ''));
if ($scan !== '') {
    $token = onlinePayTokenFromScan($scan);
    $order = $token ? onlineOrderByPayToken($token) : null;
    if (!$order) {
        $scanError = t('cash_online_scan_unknown');
    } elseif ($order['status'] === 'paid') {
        $scanError = t('cash_online_scan_paid', ['order' => $order['order_number'], 'name' => $order['customer_name']]);
    } elseif ($order['status'] === 'cancelled') {
        $scanError = t('cash_online_scan_cancelled', ['order' => $order['order_number']]);
    } else {
        logActivity('online_order_scanned', 'orders', (int) $order['id']);
        header('Location: /cashier/payment.php?order=' . (int) $order['id']);
        exit;
    }
}

// Open online orders, oldest first, with their dishes.
$orders = $pdo->prepare("
    SELECT o.*, c.address, c.street_number, c.landline, c.intolerances
    FROM orders o LEFT JOIN online_customers c ON c.id = o.online_customer_id
    WHERE o.channel = ? AND o.status NOT IN ('paid', 'cancelled')
    ORDER BY o.created_at ASC
");
$orders->execute([ONLINE_CHANNEL]);
$orders = $orders->fetchAll();
foreach ($orders as &$o) {
    $o['items'] = array_values(array_filter(getOrderItems((int) $o['id']), fn($i) => $i['status'] !== 'cancelled'));
    $o['ready'] = $o['items'] && !array_filter($o['items'], fn($i) => !in_array($i['status'], ['ready', 'served'], true));
}
unset($o);

// Paid today, for reference.
$paid = $pdo->prepare("
    SELECT o.id, o.order_number, o.customer_name, o.total, o.closed_at,
           (SELECT GROUP_CONCAT(DISTINCT p.method) FROM payments p WHERE p.order_id = o.id) AS methods
    FROM orders o WHERE o.channel = ? AND o.status = 'paid' AND DATE(o.closed_at) = CURDATE()
    ORDER BY o.closed_at DESC
");
$paid->execute([ONLINE_CHANNEL]);
$paid = $paid->fetchAll();

$readyCount = count(array_filter($orders, fn($o) => $o['ready']));
$pageTitle  = t('cash_online_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.scan-box { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.scan-box input { flex: 1; min-width: 220px; font-size: 1.1rem; padding: 12px 14px; }
#camBox { margin-top: 12px; max-width: 420px; }
.online-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-lg); }
.oo-card { background: #fff; border-radius: var(--radius-md, 12px); box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.08)); padding: 16px; display: flex; flex-direction: column; gap: 10px; border-top: 5px solid var(--info, #2563eb); }
.oo-card.ready { border-top-color: var(--success, #16a34a); }
.oo-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
.oo-name { font-size: 1.15rem; font-weight: 800; }
.oo-sub { font-size: .82rem; color: var(--text-secondary); }
.oo-intol { background: #fef2f2; color: #b91c1c; font-weight: 700; border-radius: 8px; padding: 6px 10px; font-size: .88rem; }
.oo-items { font-size: .92rem; border-top: 1px dashed var(--border-color, #e5e7eb); padding-top: 8px; }
.oo-items div { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }
.oo-total { display: flex; justify-content: space-between; align-items: center; font-size: 1.3rem; font-weight: 800; margin-top: auto; }
.oo-state { font-size: .78rem; font-weight: 700; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
.oo-state.cooking { background: #dbeafe; color: #1e40af; }
.oo-state.ready { background: #dcfce7; color: #166534; }
</style>

<div class="page-header">
    <h1><i class="fas fa-globe"></i> <?= te('cash_online_title') ?></h1>
    <a href="/cashier/index.php" class="btn btn-outline"><i class="fas fa-cash-register"></i> <?= te('nav_cashier') ?></a>
</div>

<!-- The customer's QR: scanner (types the link + Enter) or camera -->
<div class="card mb-lg" style="padding:16px 18px;">
    <h2 style="margin:0 0 6px;font-size:1.05rem;"><i class="fas fa-qrcode"></i> <?= te('cash_online_scan_title') ?></h2>
    <p class="text-muted" style="margin:0 0 10px;font-size:.9rem;"><?= te('cash_online_scan_hint') ?></p>
    <?php if ($scanError): ?>
        <div class="alert alert-danger" style="background:rgba(220,38,38,.08);color:var(--danger);padding:10px 14px;border-radius:8px;margin-bottom:10px;"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($scanError) ?></div>
    <?php endif; ?>
    <form method="GET" class="scan-box" autocomplete="off">
        <input type="text" name="scan" id="scanInput" class="form-control" placeholder="<?= te('cash_online_scan_ph') ?>" autofocus>
        <button class="btn btn-primary"><i class="fas fa-arrow-right"></i> <?= te('cash_online_scan_go') ?></button>
        <button type="button" class="btn btn-outline" id="camBtn" onclick="toggleCamera()"><i class="fas fa-camera"></i> <?= te('cash_online_camera') ?></button>
    </form>
    <div id="camBox" hidden></div>
</div>

<div class="stats-grid mb-lg">
    <div class="stat-card"><div class="stat-icon primary"><i class="fas fa-receipt"></i></div>
        <div><div class="stat-value"><?= count($orders) ?></div><div class="stat-label"><?= te('cash_online_open') ?></div></div></div>
    <div class="stat-card"><div class="stat-icon success"><i class="fas fa-bell-concierge"></i></div>
        <div><div class="stat-value"><?= $readyCount ?></div><div class="stat-label"><?= te('cash_online_ready') ?></div></div></div>
    <div class="stat-card"><div class="stat-icon warning"><i class="fas fa-euro-sign"></i></div>
        <div><div class="stat-value"><?= formatCurrency(array_sum(array_column($paid, 'total'))) ?></div><div class="stat-label"><?= te('cash_online_paid_today', ['n' => count($paid)]) ?></div></div></div>
</div>

<?php if (!$orders): ?>
    <div class="card" style="padding:50px;text-align:center;"><p class="text-muted"><?= te('cash_online_none') ?></p></div>
<?php else: ?>
<div class="online-grid mb-lg">
    <?php foreach ($orders as $o):
        $min = (int) round((time() - strtotime($o['created_at'])) / 60); ?>
        <div class="oo-card <?= $o['ready'] ? 'ready' : '' ?>">
            <div class="oo-head">
                <div>
                    <div class="oo-name"><?= htmlspecialchars((string) $o['customer_name']) ?></div>
                    <div class="oo-sub"><?= htmlspecialchars($o['order_number']) ?> · <?= date('H:i', strtotime($o['created_at'])) ?> (<?= $min ?> <?= te('minutes_short') ?>)</div>
                    <div class="oo-sub"><i class="fas fa-mobile-screen"></i> <?= htmlspecialchars((string) $o['customer_phone']) ?>
                        <?php if (!empty($o['landline'])): ?> · <i class="fas fa-phone"></i> <?= htmlspecialchars($o['landline']) ?><?php endif; ?></div>
                    <?php if (!empty($o['address'])): ?><div class="oo-sub"><i class="fas fa-location-dot"></i> <?= htmlspecialchars($o['address'] . ', ' . $o['street_number']) ?></div><?php endif; ?>
                </div>
                <span class="oo-state <?= $o['ready'] ? 'ready' : 'cooking' ?>"><?= te($o['ready'] ? 'cash_online_st_ready' : 'cash_online_st_cooking') ?></span>
            </div>
            <?php if (!empty($o['intolerances'])): ?><div class="oo-intol"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($o['intolerances']) ?></div><?php endif; ?>
            <div class="oo-items">
                <?php foreach ($o['items'] as $it): ?>
                    <div><span><?= (int) $it['quantity'] ?>× <?= htmlspecialchars($it['item_name']) ?></span><span><?= formatCurrency($it['total_price']) ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="oo-total"><span><?= formatCurrency($o['total']) ?></span>
                <a href="/cashier/payment.php?order=<?= (int) $o['id'] ?>" class="btn btn-success"><i class="fas fa-money-bill"></i> <?= te('cash_online_collect') ?></a></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($paid): ?>
<div class="card">
    <div class="card-header"><h2><i class="fas fa-check-circle" style="color:var(--success);"></i> <?= te('cash_online_paid_title') ?></h2></div>
    <table class="data-table">
        <thead><tr><th><?= te('time') ?></th><th><?= te('order_no') ?></th><th><?= te('cust_name') ?></th><th><?= te('cash_online_method') ?></th><th style="text-align:right;"><?= te('total') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($paid as $p): ?>
            <tr>
                <td><?= date('H:i', strtotime($p['closed_at'])) ?></td>
                <td><?= htmlspecialchars($p['order_number']) ?></td>
                <td><?= htmlspecialchars((string) $p['customer_name']) ?></td>
                <td><?= htmlspecialchars((string) $p['methods']) ?></td>
                <td style="text-align:right;"><strong><?= formatCurrency($p['total']) ?></strong></td>
                <td><a class="btn btn-sm btn-outline" href="/cashier/receipt.php?order=<?= (int) $p['id'] ?>"><i class="fas fa-receipt"></i></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
// Camera: read the customer's QR and go to its payment.
let cam = null;
async function toggleCamera() {
    const box = document.getElementById('camBox');
    if (cam) { await cam.stop().catch(() => {}); cam = null; box.hidden = true; box.innerHTML = ''; return; }
    if (typeof Html5Qrcode === 'undefined') return;
    box.hidden = false;
    box.innerHTML = '<div id="camView"></div>';
    cam = new Html5Qrcode('camView');
    cam.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, text => {
        cam.stop().catch(() => {});
        location.href = '/cashier/online.php?scan=' + encodeURIComponent(text);
    }).catch(() => { box.innerHTML = '<p class="text-muted">' + <?= json_encode(t('cash_online_camera_err')) ?> + '</p>'; cam = null; });
}
// Keep the scan box ready for the scanner, and the list fresh (not while scanning).
document.addEventListener('click', e => { if (!e.target.closest('input, button, a, #camBox')) document.getElementById('scanInput').focus(); });
setInterval(() => { if (!cam && !document.getElementById('scanInput').value) location.reload(); }, 20000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
