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
 *
 * On top, the till itself (includes/till.php): the Menu cassa products as
 * buttons and a keypad for free amounts build a ticket, charged as a counter
 * sale or added to an online customer's bill.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/online_order.php';
require_once __DIR__ . '/../includes/till.php';
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

// The till: Menu cassa buttons, and counter sales left unpaid.
$tillMenu  = tillMenu();
$openSales = tillOpenSales();
$tillTargets = array_map(fn($o) => ['id' => (int) $o['id'], 'label' => $o['customer_name'] . ' · ' . $o['order_number']], $orders);

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
/* The till: product display, keypad, ticket */
.till { display: grid; grid-template-columns: minmax(0, 1fr) 270px 300px; gap: 16px; align-items: start; }
@media (max-width: 1100px) { .till { grid-template-columns: minmax(0, 1fr) 270px; } .till-ticket { grid-column: 1 / -1; } }
@media (max-width: 700px) { .till { grid-template-columns: 1fr; } }
.till-cats { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.till-cats button { border: 2px solid var(--border-color, #e5e7eb); background: #fff; border-radius: 999px; padding: 6px 14px; font-weight: 700; cursor: pointer; }
.till-cats button.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.till-products { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; }
.till-products button { min-height: 78px; border: 0; border-radius: 12px; padding: 10px 8px; background: #f3f4f6; border-left: 6px solid var(--pc, var(--primary)); text-align: left; cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; gap: 6px; font-weight: 700; }
.till-products button:active { transform: scale(.97); }
.till-products button span { font-size: .95rem; line-height: 1.2; }
.till-products button small { font-size: 1rem; color: var(--primary); }
.till-empty { color: var(--text-secondary); padding: 20px 4px; }
.keypad-display { background: #111827; color: #34d399; font-family: var(--font-display, monospace); font-size: 2rem; text-align: right; padding: 12px 14px; border-radius: 10px; margin-bottom: 8px; }
.keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.keypad button { height: 54px; border: 0; border-radius: 10px; background: #f3f4f6; font-size: 1.35rem; font-weight: 700; cursor: pointer; }
.keypad button:active { background: #e5e7eb; }
.keypad .k-clear { background: #fee2e2; color: #b91c1c; }
.keypad .k-add { grid-column: 1 / -1; background: var(--primary); color: #fff; font-size: 1rem; }
.till-ticket .t-lines { max-height: 300px; overflow-y: auto; margin-bottom: 8px; }
.t-line { display: flex; align-items: center; gap: 6px; padding: 6px 0; border-bottom: 1px dashed var(--border-color, #e5e7eb); }
.t-line .n { flex: 1; font-weight: 600; font-size: .92rem; }
.t-line .p { font-weight: 700; white-space: nowrap; }
.t-line button { width: 28px; height: 28px; border-radius: 50%; border: 0; background: #f3f4f6; font-weight: 700; cursor: pointer; }
.t-total { display: flex; justify-content: space-between; font-size: 1.4rem; font-weight: 800; margin: 8px 0; }
.till-ticket select { width: 100%; margin-bottom: 8px; }
.t-pay { width: 100%; padding: 14px; font-size: 1.1rem; }
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

<!-- The till: Menu cassa products, keypad for a free amount, the ticket -->
<div class="card mb-lg" style="padding:16px 18px;">
    <h2 style="margin:0 0 10px;font-size:1.05rem;"><i class="fas fa-cash-register"></i> <?= te('till_title') ?></h2>
    <div class="till">
        <div>
            <div class="till-cats" id="tillCats"></div>
            <div class="till-products" id="tillProducts"></div>
        </div>
        <div>
            <div class="keypad-display" id="kpDisplay">0,00</div>
            <div class="keypad">
                <?php foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '00', '0'] as $k): ?>
                    <button type="button" onclick="kpPress('<?= $k ?>')"><?= $k ?></button>
                <?php endforeach; ?>
                <button type="button" onclick="kpBack()" aria-label="⌫"><i class="fas fa-delete-left"></i></button>
                <button type="button" class="k-clear" onclick="kpClear()">C</button>
                <button type="button" class="k-add" style="grid-column: span 2;" onclick="kpAdd()"><i class="fas fa-plus"></i> <?= te('till_add_amount') ?></button>
            </div>
        </div>
        <div class="till-ticket">
            <div class="t-lines" id="tLines"></div>
            <div class="t-total"><span><?= te('total') ?></span><span id="tTotal"></span></div>
            <select id="tTarget" class="form-control" aria-label="<?= te('till_target') ?>">
                <option value=""><?= te('till_target_counter') ?></option>
                <?php foreach ($tillTargets as $tt): ?>
                    <option value="<?= $tt['id'] ?>"><?= te('till_target_online', ['order' => $tt['label']]) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="d-flex gap-sm">
                <button type="button" class="btn btn-outline" onclick="tClear()" title="<?= te('till_clear') ?>"><i class="fas fa-trash"></i></button>
                <button type="button" class="btn btn-success t-pay" id="tPay" onclick="tCheckout()"></button>
            </div>
        </div>
    </div>
</div>

<?php if ($openSales): ?>
<div class="card mb-lg">
    <div class="card-header"><h2><i class="fas fa-hourglass-half text-warning"></i> <?= te('till_open_sales') ?></h2></div>
    <table class="data-table">
        <tbody>
        <?php foreach ($openSales as $s): ?>
            <tr>
                <td><?= date('H:i', strtotime($s['created_at'])) ?></td>
                <td><?= htmlspecialchars($s['order_number']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($s['cashier']) ?></td>
                <td style="text-align:right;"><strong><?= formatCurrency($s['total']) ?></strong></td>
                <td style="text-align:right;white-space:nowrap;">
                    <a class="btn btn-sm btn-success" href="/cashier/payment.php?order=<?= (int) $s['id'] ?>"><i class="fas fa-money-bill"></i> <?= te('cash_online_collect') ?></a>
                    <button class="btn btn-sm btn-outline" onclick="tCancelSale(<?= (int) $s['id'] ?>)"><i class="fas fa-xmark"></i> <?= te('cancel') ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

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
                <span class="d-flex gap-sm">
                    <button type="button" class="btn btn-outline" onclick="tTargetOrder(<?= (int) $o['id'] ?>)" title="<?= te('till_add_to_this') ?>"><i class="fas fa-cart-plus"></i></button>
                    <a href="/cashier/payment.php?order=<?= (int) $o['id'] ?>" class="btn btn-success"><i class="fas fa-money-bill"></i> <?= te('cash_online_collect') ?></a>
                </span></div>
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
/* ---- The till: products, keypad, ticket (kept on this device until charged) ---- */
const TILL_MENU = <?= json_encode($tillMenu, JSON_UNESCAPED_UNICODE) ?>;
const TL = <?= json_encode([
    'pay' => t('till_pay'), 'empty' => t('till_ticket_empty'), 'free' => t('till_free_line'), 'none' => t('till_no_products'),
    'failed' => t('toast_update_failed'), 'cancel_q' => t('till_cancel_confirm'), 'currency' => formatCurrency(0),
], JSON_UNESCAPED_UNICODE) ?>;
const $id = id => document.getElementById(id);
const escH = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = v => TL.currency.replace(/0[.,]00/, v.toFixed(2).replace('.', TL.currency.includes(',') ? ',' : '.'));
const TICKET_KEY = 'till-ticket';
let ticket = [];       // [{id, name, unit, qty}] products, [{amount}] free amounts
try { ticket = JSON.parse(localStorage.getItem(TICKET_KEY) || '[]') || []; if (!Array.isArray(ticket)) ticket = []; } catch (e) {}
const saveTicket = () => { try { localStorage.setItem(TICKET_KEY, JSON.stringify(ticket)); } catch (e) {} };
let tillCat = 0, kpCents = 0;

function renderTillMenu() {
    $id('tillCats').innerHTML = TILL_MENU.length > 1 ? TILL_MENU.map((c, i) =>
        `<button type="button" class="${i === tillCat ? 'on' : ''}" onclick="tillCat = ${i}; renderTillMenu()">${escH(c.name)}</button>`).join('') : '';
    const c = TILL_MENU[tillCat];
    $id('tillProducts').innerHTML = c ? c.items.map(it =>
        `<button type="button" style="--pc:${escH(c.color || '')}" onclick="tAddProduct(${it.id})"><span>${escH(it.name)}</span><small>${escH(it.price)}</small></button>`).join('')
        : `<div class="till-empty">${escH(TL.none)}</div>`;
}
function tAddProduct(id) {
    const it = TILL_MENU.flatMap(c => c.items).find(i => i.id === id);
    if (!it) return;
    const same = ticket.find(l => l.id === id);
    if (same) same.qty = Math.min(99, same.qty + 1); else ticket.push({ id, name: it.name, unit: it.amount, qty: 1 });
    saveTicket(); renderTicket();
}
// Keypad, like a cash register: digits come in as cents (3, 5, 0 → 3,50).
function kpShow() { $id('kpDisplay').textContent = money(kpCents / 100); }
function kpPress(k) { const v = Number(String(kpCents) + k); if (v <= 999999) kpCents = v; kpShow(); }
function kpBack() { kpCents = Math.floor(kpCents / 10); kpShow(); }
function kpClear() { kpCents = 0; kpShow(); }
function kpAdd() {
    if (!kpCents) return;
    ticket.push({ amount: kpCents / 100 });
    kpCents = 0; kpShow(); saveTicket(); renderTicket();
}
const lineTotal = l => l.amount !== undefined ? l.amount : l.unit * l.qty;
function tStep(i, d) {
    const l = ticket[i];
    if (!l) return;
    if (l.amount !== undefined || l.qty + d < 1) ticket.splice(i, 1); else l.qty = Math.min(99, l.qty + d);
    saveTicket(); renderTicket();
}
function renderTicket() {
    $id('tLines').innerHTML = ticket.length ? ticket.map((l, i) => l.amount !== undefined
        ? `<div class="t-line"><span class="n">${escH(TL.free)}</span><span class="p">${money(l.amount)}</span><button type="button" onclick="tStep(${i}, -1)">✕</button></div>`
        : `<div class="t-line"><span class="n">${l.qty}× ${escH(l.name)}</span><span class="p">${money(lineTotal(l))}</span>
               <button type="button" onclick="tStep(${i}, -1)">−</button><button type="button" onclick="tStep(${i}, 1)">+</button></div>`).join('')
        : `<div class="till-empty">${escH(TL.empty)}</div>`;
    const tot = ticket.reduce((s, l) => s + lineTotal(l), 0);
    $id('tTotal').textContent = money(tot);
    $id('tPay').innerHTML = '<i class="fas fa-money-bill"></i> ' + escH(TL.pay.replace('{total}', money(tot)));
    $id('tPay').disabled = !ticket.length;
}
function tClear() { ticket = []; saveTicket(); renderTicket(); }
// "+ cart" on an online order: the ticket goes on that customer's bill.
function tTargetOrder(orderId) { $id('tTarget').value = String(orderId); window.scrollTo({ top: 0, behavior: 'smooth' }); }
async function tCheckout() {
    if (!ticket.length) return;
    $id('tPay').disabled = true;
    try {
        const lines = ticket.map(l => l.amount !== undefined ? { amount: l.amount } : { id: l.id, qty: l.qty });
        const r = await (await fetch('/api/till.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'checkout', lines, target_order_id: $id('tTarget').value || null }) })).json();
        if (!r.success) { showToast(r.message || TL.failed, 'error'); $id('tPay').disabled = false; return; }
        tClear();
        location.href = '/cashier/payment.php?order=' + r.order_id;
    } catch (e) { showToast(TL.failed, 'error'); $id('tPay').disabled = false; }
}
async function tCancelSale(orderId) {
    if (!confirm(TL.cancel_q)) return;
    await fetch('/api/till.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'cancel', order_id: orderId }) });
    location.reload();
}
renderTillMenu(); renderTicket(); kpShow();

// Keep the scan box ready for the scanner, and the list fresh (not while scanning
// or while a ticket / an amount is being made).
document.addEventListener('click', e => { if (!e.target.closest('input, button, a, select, #camBox')) document.getElementById('scanInput').focus(); });
setInterval(() => { if (!cam && !document.getElementById('scanInput').value && !ticket.length && !kpCents) location.reload(); }, 20000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
