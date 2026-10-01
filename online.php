<?php
/**
 * Online customers — the one QR everybody scans in a shop with no tables
 * (Admin > Clienti online). New customers sign up once (code on WhatsApp),
 * returning ones type their mobile; then they order from the menu, follow
 * their dishes and pay at the till when they collect. This phone stays
 * signed in. Data comes from /api/online.php (includes/online_order.php).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/online_order.php';
require_once __DIR__ . '/includes/restaurant.php';
require_once __DIR__ . '/includes/menu_pdf.php';
i18n_prefer_browser('it');

$ws    = getDBConnection()->query("SELECT name FROM workspaces LIMIT 1")->fetch();
$brand = $ws['name'] ?? t('app_name');

$L = [
    'failed'        => t('guest_failed'),
    'ready_title'   => t('online_ready_title'),
    'ready_body'    => t('online_ready_body'),
    'self_sent'     => t('online_sent_toast'),
    'self_custom'   => t('self_custom_label'),
    'self_fixed'    => t('self_custom_fixed'),
    'self_add_basket' => t('self_add_basket'),
    'self_dishes'   => t('self_cart_dishes'),
    'self_note_ph'  => t('self_note_ph'),
    'self_resend'   => t('self_resend'),
    'self_resend_in'=> t('self_resend_in'),
    'code_sent_to'  => t('self_code_sent_to'),
    'hello'         => t('online_hello'),
    'order_no'      => t('online_order_no'),
    'paid_text'     => t('online_paid_text'),
    'currency'      => formatCurrency(0),
];
$countries = phoneCountryOptions();
$mLang     = currentLang() === 'it' ? 'it' : 'en';
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars($brand) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root { --p: #e8590c; --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --bg: #f7f5f2; --ok: #16a34a; }
* { box-sizing: border-box; }
[hidden] { display: none !important; }
body { margin: 0; font-family: 'DM Sans', system-ui, sans-serif; background: var(--bg); color: var(--ink); padding: env(safe-area-inset-top) 0 calc(110px + env(safe-area-inset-bottom)); }
header { background: var(--ink); color: #fff; padding: 18px 18px 22px; }
header .brand { font-size: .85rem; opacity: .75; letter-spacing: .04em; text-transform: uppercase; }
header h1 { margin: 4px 0 0; font-size: 1.6rem; }
.lang { float: right; font-size: .8rem; }
.lang a { color: #fff; opacity: .6; text-decoration: none; margin-left: 8px; font-weight: 700; }
.lang a.on { opacity: 1; text-decoration: underline; }
main { padding: 16px; max-width: 560px; margin: 0 auto; }
.card { background: #fff; border-radius: 14px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 14px; }
.card h2 { font-size: 1.05rem; margin: 0 0 6px; }
.card h2 i { color: var(--p); margin-right: 4px; }
.sub { color: var(--muted); font-size: .9rem; margin: 0 0 8px; }
.returning { border: 2px solid #fed7aa; }
.form label { display: block; font-size: .8rem; font-weight: 700; color: var(--muted); margin: 10px 0 4px; }
.form input, .form select, .form textarea { width: 100%; font: inherit; padding: 11px 12px; border: 2px solid var(--line); border-radius: 10px; background: #fff; }
.form textarea { min-height: 70px; resize: vertical; }
.form input:focus, .form select:focus, .form textarea:focus { outline: none; border-color: var(--p); }
.form .two { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.form .addr { display: grid; grid-template-columns: 1fr 6.5rem; gap: 8px; }
.form .phone { display: grid; grid-template-columns: 7.5rem 1fr; gap: 8px; }
.form small.opt { font-weight: 400; }
.consent { display: flex; gap: 10px; align-items: flex-start; margin-top: 14px; padding: 10px; border: 1px solid var(--line); border-radius: 10px; font-size: .82rem; color: #374151; }
.consent input { width: 20px; height: 20px; flex: 0 0 auto; margin-top: 2px; }
.consent span { white-space: pre-wrap; line-height: 1.45; }
.btn-go { display: block; width: 100%; margin-top: 14px; padding: 14px; border: 0; border-radius: 12px; font: inherit; font-weight: 700; background: var(--p); color: #fff; cursor: pointer; }
.btn-go:disabled { opacity: .6; }
.btn-no { background: #f3f4f6; color: var(--ink); }
.link-btn { background: none; border: 0; color: var(--p); font: inherit; font-weight: 700; cursor: pointer; padding: 8px; }
.link-btn:disabled { color: var(--muted); cursor: default; }
.or { text-align: center; color: var(--muted); font-size: .85rem; margin: 4px 0 14px; }
.gate { text-align: center; }
.gate-icon { font-size: 2.2rem; color: var(--p); margin-bottom: 8px; }
.code-input { width: 100%; max-width: 240px; font: inherit; font-size: 1.8rem; letter-spacing: .35em; text-align: center; padding: 12px; border: 2px solid var(--line); border-radius: 12px; }
.code-input:focus { outline: none; border-color: var(--p); }
.gate .btn-go { max-width: 240px; margin: 12px auto 0; }
.dish { display: flex; justify-content: space-between; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.dish:last-child { border-bottom: 0; }
.dish .n { font-weight: 600; }
.st { font-size: .75rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; align-self: center; }
.st-pending { background: #fef3c7; color: #92400e; }
.st-in_kitchen { background: #dbeafe; color: #1e40af; }
.st-ready { background: #dcfce7; color: #166534; }
.st-served { background: #f3f4f6; color: #4b5563; }
.total { display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: 700; padding-top: 10px; }
.pay-note { display: flex; gap: 10px; align-items: center; background: #fff7ed; border-radius: 12px; padding: 12px; margin-top: 12px; font-size: .9rem; }
.pay-note i { color: var(--p); font-size: 1.3rem; }
.all-ready { background: var(--ok); color: #fff; border-radius: 14px; padding: 16px; margin-bottom: 14px; display: flex; gap: 14px; align-items: center; font-weight: 700; }
.all-ready i { font-size: 1.8rem; }
.empty { text-align: center; color: var(--muted); padding: 18px 0; }
.toast { position: fixed; left: 50%; top: 16px; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 12px; z-index: 40; display: none; max-width: 90vw; text-align: center; }
.order-more { width: 100%; border: 0; border-radius: 12px; padding: 14px; font: inherit; font-weight: 700; background: var(--p); color: #fff; margin-bottom: 14px; cursor: pointer; }
.whoami { text-align: center; color: var(--muted); font-size: .85rem; }
.menu-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.menu-btns a { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px 6px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: .85rem; text-align: center; }
.mb-view { background: #fff7ed; color: var(--p); box-shadow: inset 0 0 0 2px #fed7aa; }
.mb-pdf { background: #eff6ff; color: #1d4ed8; box-shadow: inset 0 0 0 2px #bfdbfe; }
.contacts { text-align: center; color: var(--muted); font-size: .88rem; padding: 6px 4px 0; }
.contacts a { color: inherit; text-decoration: none; }
.contacts .c-line { margin: 4px 0; }
.contacts .c-line i { color: var(--p); margin-right: 5px; }
/* Menu and cart (as on the table page) */
.shop-cats { position: sticky; top: 0; z-index: 4; display: flex; gap: 6px; overflow-x: auto; background: var(--bg); padding: 8px 0 10px; scrollbar-width: none; }
.shop-cats::-webkit-scrollbar { display: none; }
.shop-cats button { flex: 0 0 auto; border: 2px solid var(--line); background: #fff; border-radius: 999px; padding: 7px 14px; font: inherit; font-weight: 700; font-size: .85rem; color: var(--ink); }
.shop-cats button.on { background: var(--p); border-color: var(--p); color: #fff; }
.shop-item { display: flex; gap: 10px; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--line); }
.shop-item:last-child { border-bottom: 0; }
.shop-item .info { flex: 1; min-width: 0; }
.shop-item .info strong { display: block; }
.shop-item .info small { display: block; color: var(--muted); font-size: .8rem; margin-top: 2px; }
.shop-item .info small.custom { color: var(--p); font-weight: 700; }
.shop-item .price { font-weight: 700; white-space: nowrap; }
.shop-thumb { position: relative; flex: 0 0 auto; width: 56px; height: 56px; border-radius: 10px; overflow: hidden; background: #f3f4f6; border: 0; padding: 0; }
.shop-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.shop-thumb .pl { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; background: rgba(0,0,0,.35); font-size: .95rem; }
.shop-thumb.novideo .pl { display: none; }
.qty { display: flex; align-items: center; gap: 6px; }
.qty button { width: 34px; height: 34px; border-radius: 50%; border: 0; font-size: 1.1rem; font-weight: 700; cursor: pointer; background: #f3f4f6; color: var(--ink); }
.qty button.plus { background: var(--p); color: #fff; }
.qty span { min-width: 18px; text-align: center; font-weight: 700; }
.cart-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 8; background: #fff; border-top: 1px solid var(--line); padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); display: flex; gap: 10px; align-items: center; box-shadow: 0 -6px 18px rgba(0,0,0,.08); }
.cart-bar .sum { flex: 1; line-height: 1.2; }
.cart-bar .sum small { display: block; color: var(--muted); font-size: .78rem; }
.cart-bar .sum strong { font-size: 1.1rem; }
.cart-bar button { border: 0; border-radius: 12px; padding: 13px 16px; font: inherit; font-weight: 700; background: var(--p); color: #fff; }
.cart-line { padding: 10px 0; border-bottom: 1px solid var(--line); }
.cart-line .top { display: flex; align-items: center; gap: 10px; }
.cart-line .top strong { flex: 1; }
.cart-line .top strong small.mods { display: block; font-weight: 400; font-size: .8rem; color: var(--muted); }
.cart-line .lp { font-weight: 700; white-space: nowrap; }
.cart-line input { width: 100%; margin-top: 6px; font: inherit; font-size: .85rem; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; }
.sheet-bg { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: flex-end; z-index: 10; }
.sheet-bg.on { display: flex; }
.sheet { background: #fff; width: 100%; border-radius: 18px 18px 0 0; padding: 18px 16px calc(18px + env(safe-area-inset-bottom)); max-height: 85vh; overflow-y: auto; }
.sheet h3 { margin: 0 0 12px; }
.sheet .row { display: flex; gap: 8px; margin-top: 12px; }
.sheet .row button { flex: 1; padding: 13px; border-radius: 10px; border: 0; font: inherit; font-weight: 700; margin: 0; }
.pick { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid var(--line); border-radius: 10px; margin-bottom: 8px; }
.pick:has(input:checked) { border-color: var(--p); background: #fff7ed; }
.pick .price { margin-left: auto; font-weight: 700; white-space: nowrap; }
.pick .comp-img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; flex: 0 0 auto; }
.hint-small { font-size: .8rem; color: var(--muted); margin: 4px 0 0; }
.custom-qty { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; font-weight: 700; }
.video-sheet video { width: 100%; max-height: 60vh; border-radius: 12px; background: #000; }
.ready-banner { position: fixed; left: 12px; right: 12px; top: calc(12px + env(safe-area-inset-top)); z-index: 30; background: var(--ok); color: #fff; border-radius: 16px; padding: 16px 18px; box-shadow: 0 10px 30px rgba(0,0,0,.25); display: flex; gap: 14px; align-items: center; }
.ready-banner i { font-size: 1.8rem; }
.pay-qr { text-align: center; }
.paid-card { display: flex; gap: 14px; align-items: center; background: var(--ok); color: #fff; }
.paid-card i { font-size: 2rem; }
.paid-card strong { display: block; font-size: 1.1rem; }
#payQr { display: flex; justify-content: center; padding: 8px 0 4px; }
#payQr img, #payQr canvas { width: 220px; height: 220px; }
.ready-banner strong { display: block; font-size: 1.05rem; }
.ready-banner button { margin-left: auto; background: rgba(255,255,255,.2); border: 0; color: #fff; border-radius: 10px; padding: 8px 12px; font: inherit; font-weight: 700; }
</style>
</head>
<body>
<header>
    <span class="lang">
        <?php foreach (langLabels() as $label => $code): ?>
            <a href="<?= htmlspecialchars(langSwitchUrl($code)) ?>" class="<?= $code === currentLang() ? 'on' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </span>
    <div class="brand"><?= htmlspecialchars($brand) ?></div>
    <h1><?= te('online_page_title') ?></h1>
</header>

<?php
// The menu to browse / download, and the shop's address, under the forms.
ob_start(); ?>
    <div class="card">
        <h2><i class="fas fa-book-open"></i> <?= te('menu_card_title') ?></h2>
        <div class="menu-btns">
            <a class="mb-view" href="<?= htmlspecialchars(menuViewUrl($mLang)) ?>" target="_blank" rel="noopener"><i class="fas fa-eye"></i><?= te('menu_view') ?></a>
            <a class="mb-pdf" href="<?= htmlspecialchars(menuPdfUrl($mLang, true)) ?>"><i class="fas fa-file-arrow-down"></i><?= te('menu_download') ?></a>
        </div>
    </div>
<?php
$rsInfo = restaurantInfo();
$rsAddr = restaurantAddressLine();
if ($rsAddr || !empty($rsInfo['phone'])): ?>
    <div class="contacts">
        <?php if ($rsAddr): ?><div class="c-line"><a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($brand . ', ' . $rsAddr) ?>" target="_blank" rel="noopener"><i class="fas fa-location-dot"></i><?= htmlspecialchars($rsAddr) ?></a></div><?php endif; ?>
        <?php if (!empty($rsInfo['phone'])): ?><div class="c-line"><a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $rsInfo['phone'])) ?>"><i class="fas fa-phone"></i><?= htmlspecialchars($rsInfo['phone']) ?></a></div><?php endif; ?>
    </div>
<?php endif;
$footHtml = ob_get_clean(); ?>

<main id="off" hidden>
    <div class="card gate"><i class="fas fa-store-slash gate-icon"></i><p><?= te('online_err_off') ?></p></div>
    <?= $footHtml ?>
</main>

<!-- Not signed in: returning customer (mobile only) or new customer (sign-up) -->
<main id="gate" hidden>
    <div class="card returning">
        <h2><i class="fas fa-user-check"></i> <?= te('online_returning_title') ?></h2>
        <p class="sub"><?= te('online_returning_text') ?></p>
        <form class="form" onsubmit="requestCode(event, 'login')">
            <div class="phone">
                <select id="lgCountry" aria-label="<?= te('cust_prefix') ?>">
                    <?php foreach ($countries as $c): ?><option value="<?= $c['iso'] ?>"><?= $c['flag'] ?> <?= $c['dial'] ?></option><?php endforeach; ?>
                </select>
                <input id="lgMobile" type="tel" inputmode="tel" maxlength="20" autocomplete="tel-national" placeholder="333 123 4567" required>
            </div>
            <button class="btn-go" type="submit"><i class="fab fa-whatsapp"></i> <?= te('online_login_btn') ?></button>
        </form>
    </div>

    <p class="or"><?= te('online_or_new') ?></p>

    <div class="card">
        <h2><i class="fas fa-user-plus"></i> <?= te('online_register_title') ?></h2>
        <p class="sub"><?= te('online_register_text') ?></p>
        <form class="form" onsubmit="requestCode(event, 'register')">
            <div class="two">
                <div><label for="rgFirst"><?= te('self_name') ?></label><input id="rgFirst" maxlength="60" autocomplete="given-name" required></div>
                <div><label for="rgLast"><?= te('self_surname') ?></label><input id="rgLast" maxlength="60" autocomplete="family-name" required></div>
            </div>
            <div class="addr">
                <div><label for="rgAddress"><?= te('online_address') ?></label><input id="rgAddress" maxlength="150" autocomplete="address-line1" placeholder="<?= te('online_address_ph') ?>" required></div>
                <div><label for="rgNumber"><?= te('online_street_number') ?></label><input id="rgNumber" maxlength="15" required></div>
            </div>
            <label for="rgMobile"><?= te('online_mobile') ?></label>
            <div class="phone">
                <select id="rgCountry" aria-label="<?= te('cust_prefix') ?>">
                    <?php foreach ($countries as $c): ?><option value="<?= $c['iso'] ?>"><?= $c['flag'] ?> <?= $c['dial'] ?></option><?php endforeach; ?>
                </select>
                <input id="rgMobile" type="tel" inputmode="tel" maxlength="20" autocomplete="tel-national" placeholder="333 123 4567" required>
            </div>
            <label for="rgLandline"><?= te('online_landline') ?> <small class="opt">(<?= te('self_consent_optional') ?>)</small></label>
            <input id="rgLandline" type="tel" inputmode="tel" maxlength="25" placeholder="081 123 4567">
            <label for="rgIntol"><?= te('online_intolerances') ?> <small class="opt">(<?= te('self_consent_optional') ?>)</small></label>
            <textarea id="rgIntol" maxlength="500" placeholder="<?= te('online_intolerances_ph') ?>"></textarea>
            <label class="consent">
                <input type="checkbox" id="rgConsent">
                <span><strong><?= te('self_consent_label') ?></strong> (<?= te('self_consent_optional') ?>)<br><?= htmlspecialchars(consentText('prompt', currentLang())) ?></span>
            </label>
            <button class="btn-go" type="submit"><i class="fab fa-whatsapp"></i> <?= te('online_register_btn') ?></button>
        </form>
    </div>
    <?= $footHtml ?>
</main>

<!-- The code from WhatsApp -->
<main id="codeStep" hidden>
    <div class="card gate">
        <i class="fas fa-lock gate-icon"></i>
        <h2><?= te('self_code_title') ?></h2>
        <p class="sub" id="codeText"></p>
        <form onsubmit="verifyCode(event)">
            <input id="codeInput" class="code-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="••••••" required>
            <button class="btn-go" type="submit"><?= te('self_enter') ?></button>
        </form>
        <button class="link-btn" id="resendBtn" onclick="resendCode()"></button>
        <button class="link-btn" onclick="changeData()"><?= te('self_change_number') ?></button>
    </div>
</main>

<!-- Signed in: the menu and the cart -->
<main id="shop" hidden>
    <div class="card paid-card" id="paidCard" hidden>
        <i class="fas fa-circle-check"></i>
        <div><strong><?= te('online_paid_title') ?></strong><span id="paidText"></span></div>
    </div>
    <div class="card">
        <h2><i class="fas fa-utensils"></i> <span class="hello"></span></h2>
        <p class="sub"><?= te('online_shop_intro') ?></p>
        <button class="link-btn" id="shopBack" hidden onclick="closeShop()" style="padding-left:0;"><i class="fas fa-arrow-left"></i> <?= te('online_back_to_order') ?></button>
    </div>
    <div class="shop-cats" id="shopCats"></div>
    <div id="shopMenu"><div class="card"><div class="empty"><?= te('loading') ?></div></div></div>
    <p class="whoami"><button class="link-btn" onclick="logout()"><?= te('online_not_you') ?></button></p>
</main>
<div class="cart-bar" id="cartBar" hidden>
    <div class="sum"><small id="cartCount"></small><strong id="cartTotal"></strong></div>
    <button onclick="openCart()"><i class="fas fa-basket-shopping"></i> <?= te('self_review_send') ?></button>
</div>
<div class="sheet-bg" id="customSheet" onclick="if (event.target === this) this.classList.remove('on')">
    <div class="sheet">
        <h3 id="customTitle"></h3>
        <p class="hint-small" style="margin-top:-6px;"><?= te('self_custom_hint') ?></p>
        <div id="customList"></div>
        <div class="custom-qty"><span><?= te('quantity') ?></span>
            <div class="qty"><button type="button" onclick="customQtyStep(-1)">−</button><span id="customQty">1</span><button type="button" class="plus" onclick="customQtyStep(1)">+</button></div></div>
        <div class="row">
            <button class="btn-no" onclick="$('customSheet').classList.remove('on')"><?= te('cancel') ?></button>
            <button class="btn-go" id="customAddBtn" onclick="customAdd()"></button>
        </div>
    </div>
</div>
<div class="sheet-bg" id="videoSheet" onclick="if (event.target === this) closeDishVideo()">
    <div class="sheet video-sheet">
        <h3 id="videoTitle"></h3>
        <video id="dishVideo" controls playsinline preload="metadata"></video>
        <div class="row"><button class="btn-no" onclick="closeDishVideo()"><?= te('close') ?></button></div>
    </div>
</div>
<div class="sheet-bg" id="cartSheet" onclick="if (event.target === this) this.classList.remove('on')">
    <div class="sheet">
        <h3><i class="fas fa-basket-shopping" style="color:var(--p);"></i> <?= te('self_cart_title') ?></h3>
        <div id="cartLines"></div>
        <div class="total" style="padding-top:12px;"><span><?= te('total') ?></span><span id="cartSheetTotal"></span></div>
        <p class="hint-small"><?= te('online_cart_hint') ?></p>
        <div class="row">
            <button class="btn-no" onclick="$('cartSheet').classList.remove('on')"><?= te('self_keep_ordering') ?></button>
            <button class="btn-go" id="cartSend" onclick="sendCart()"><i class="fas fa-paper-plane"></i> <?= te('online_send_order') ?></button>
        </div>
    </div>
</div>

<!-- Signed in with an order: how it is doing -->
<main id="app" hidden>
    <div class="all-ready" id="allReady" hidden><i class="fas fa-bell-concierge"></i><div><?= te('online_all_ready') ?></div></div>
    <button class="order-more" onclick="openShop()"><i class="fas fa-plus"></i> <?= te('self_order_more') ?></button>
    <div class="card">
        <h2><i class="fas fa-receipt"></i> <span class="hello"></span></h2>
        <p class="sub" id="orderNo"></p>
        <div id="dishes"></div>
        <div class="total"><span><?= te('guest_to_pay') ?></span><span id="total"></span></div>
        <div class="pay-note"><i class="fas fa-cash-register"></i><span><?= te('online_pay_at_till') ?></span></div>
    </div>
    <div class="card pay-qr">
        <h2><i class="fas fa-qrcode"></i> <?= te('online_pay_qr_title') ?></h2>
        <p class="sub"><?= te('online_pay_qr_text') ?></p>
        <div id="payQr"></div>
    </div>
    <?= $footHtml ?>
    <p class="whoami"><button class="link-btn" onclick="logout()"><?= te('online_not_you') ?></button></p>
</main>

<div class="toast" id="toast"></div>
<div class="ready-banner" id="readyBanner" hidden>
    <i class="fas fa-bell-concierge"></i>
    <div><strong id="readyTitle"></strong><span id="readyBody"></span></div>
    <button onclick="$('readyBanner').hidden = true">OK</button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const L = <?= json_encode($L, JSON_UNESCAPED_UNICODE) ?>;
const API = '/api/online.php';
let state = null;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function toast(msg) {
    const t = $('toast'); t.textContent = msg; t.style.display = 'block';
    clearTimeout(toast.h); toast.h = setTimeout(() => t.style.display = 'none', 3500);
}
function show(id) {
    ['off', 'gate', 'codeStep', 'shop', 'app'].forEach(m => { $(m).hidden = m !== id; });
    if (id !== 'shop') $('cartBar').hidden = true;
}

async function load() {
    try {
        const r = await fetch(API, { cache: 'no-store' });
        const s = await r.json();
        if (s.success) render(s);
    } catch (e) { /* offline for a moment — next poll retries */ }
}
async function send(body) {
    try {
        const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const s = await r.json();
        if (!s.success) { toast(s.message || L.failed); if (s.signed_in === false) load(); return false; }
        render(s);
        return true;
    } catch (e) { toast(L.failed); return false; }
}

function render(s) {
    if (!s.signed_in) return renderSignedOut(s);
    state = s;
    document.querySelectorAll('.hello').forEach(el => { el.textContent = L.hello.replace('{name}', s.customer.first_name); });
    if (!s.enabled && !s.items.length) { show('off'); return; }
    // The menu first (nothing ordered yet) or when asked for more.
    renderPaid(s.paid);
    if (shopOpen || !s.items.length) { show('shop'); $('shopBack').hidden = !s.items.length; renderShop(); return; }
    show('app');
    $('orderNo').textContent = s.order ? L.order_no.replace('{n}', s.order.number) : '';
    $('dishes').innerHTML = s.items.map(i => `
        <div class="dish"><div class="n">${i.quantity}× ${esc(i.name)}</div>
            <span class="st st-${esc(i.status)}">${esc(i.label)}</span></div>`).join('');
    $('total').textContent = s.total_fmt;
    $('allReady').hidden = !s.all_ready;
    renderPayQr(s.order && s.order.pay_url);
    notifyReady(s);
}

// Paid at the till: "payment received, thank you" (with the chime when it happens while the page is open).
let paidSeen = undefined;
function renderPaid(p) {
    $('paidCard').hidden = !p;
    if (p) $('paidText').textContent = L.paid_text.replace('{order}', p.number).replace('{total}', p.total_fmt);
    const key = p ? p.number : null;
    if (paidSeen !== undefined && key && key !== paidSeen) {
        playChime();
        try { navigator.vibrate && navigator.vibrate([200, 100, 200]); } catch (e) {}
        window.scrollTo(0, 0);
    }
    paidSeen = key;
}

// The order's QR for the till (drawn here, redrawn only if it changes).
function renderPayQr(url) {
    if (!url || url === renderPayQr.url || typeof QRCode === 'undefined') return;
    renderPayQr.url = url;
    $('payQr').innerHTML = '';
    new QRCode($('payQr'), { text: url, width: 440, height: 440, correctLevel: QRCode.CorrectLevel.M });
}

/* ---- Not signed in: returning (mobile) or new customer, then the code ---- */
let editing = false, lastRequest = null, resendAt = 0;
function renderSignedOut(s) {
    state = null;
    if (!s.enabled) { show('off'); return; }
    if (s.pending && !editing) {
        show('codeStep');
        $('codeText').textContent = L.code_sent_to.replace('{phone}', '•••• ' + s.pending.phone_end);
        resendAt = Date.now() + s.pending.resend_in * 1000;
        tickResend();
        return;
    }
    if ($('gate').hidden) show('gate');
}
function formData(mode) {
    if (mode === 'login') return { mode, country: $('lgCountry').value, mobile: $('lgMobile').value };
    return { mode, first_name: $('rgFirst').value, last_name: $('rgLast').value, address: $('rgAddress').value,
             street_number: $('rgNumber').value, country: $('rgCountry').value, mobile: $('rgMobile').value,
             landline: $('rgLandline').value, intolerances: $('rgIntol').value, consent: $('rgConsent').checked };
}
async function requestCode(e, mode) {
    e.preventDefault();
    lastRequest = formData(mode);
    editing = false;
    if (await send(Object.assign({ action: 'request_code' }, lastRequest))) { window.scrollTo(0, 0); setTimeout(() => $('codeInput').focus(), 50); }
}
async function resendCode() {
    if (!lastRequest) { changeData(); return; }   // page reloaded: fill the form again
    await send(Object.assign({ action: 'request_code' }, lastRequest));
}
function changeData() { editing = true; show('gate'); }
function tickResend() {
    clearTimeout(tickResend.h);
    const left = Math.ceil((resendAt - Date.now()) / 1000);
    $('resendBtn').disabled = left > 0;
    $('resendBtn').textContent = left > 0 ? L.self_resend_in.replace('{s}', left) : L.self_resend;
    if (left > 0) tickResend.h = setTimeout(tickResend, 1000);
}
async function verifyCode(e) {
    e.preventDefault();
    if (await send({ action: 'verify', code: $('codeInput').value })) {
        $('codeInput').value = '';
        if (state && state.welcome) toast(state.welcome);
    }
}
async function logout() {
    cart = []; saveCart();
    shopOpen = false; editing = false;
    await send({ action: 'logout' });
}

/* ---- The menu and the cart (kept on this phone until sent) ---- */
let shopOpen = false, shopMenu = null, shopCat = null;
const CART_KEY = 'online-cart';
let cart = [];
try { cart = JSON.parse(localStorage.getItem(CART_KEY) || '[]') || []; if (!Array.isArray(cart)) cart = []; } catch (e) {}
const saveCart = () => { try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) {} };
const money = v => L.currency.replace(/0[.,]00/, v.toFixed(2).replace('.', L.currency.includes(',') ? ',' : '.'));
const itemOf = id => (shopMenu || []).flatMap(c => c.items).find(i => i.id === id);
function lineUnit(l) {
    const it = itemOf(l.id);
    return (it ? it.amount : 0) + (l.add || []).reduce((s, cid) => s + ((it?.components || []).find(c => c.id === cid)?.extra || 0), 0);
}
function lineMods(l) {
    const comps = itemOf(l.id)?.components || [];
    const nm = cid => comps.find(c => c.id === cid)?.name;
    return [...(l.remove || []).map(cid => '− ' + nm(cid)), ...(l.add || []).map(cid => '+ ' + nm(cid))].filter(x => !x.endsWith('undefined')).join(', ');
}
function cartItems() {
    if (!shopMenu) return [];
    return cart.filter(l => l.qty > 0 && itemOf(l.id)).map(l => ({ ...l, item: itemOf(l.id), idx: cart.indexOf(l) }));
}
const itemQty = id => cart.filter(l => l.id === id).reduce((s, l) => s + l.qty, 0);
function addLine(id, add = [], remove = [], qty = 1) {
    const key = id + '|' + [...add].sort().join('.') + '|' + [...remove].sort().join('.');
    const same = cart.find(l => l.key === key);
    if (same) same.qty = Math.min(20, same.qty + qty); else cart.push({ key, id, qty, note: '', add, remove });
    saveCart();
}
function openShop() { shopOpen = true; render(state); window.scrollTo(0, 0); }
function closeShop() { shopOpen = false; render(state); }
async function loadShopMenu() {
    if (shopMenu || loadShopMenu.busy) return;
    loadShopMenu.busy = true;
    try {
        const r = await fetch(API + '?menu=1', { cache: 'no-store' });
        const s = await r.json();
        shopMenu = s.menu || [];
        shopCat = shopMenu[0] ? 0 : null;
        renderShop();
    } catch (e) { /* next render retries */ }
    loadShopMenu.busy = false;
}
function renderShop() {
    if (!shopMenu) { loadShopMenu(); return; }
    $('shopCats').innerHTML = shopMenu.map((c, i) => `<button class="${i === shopCat ? 'on' : ''}" onclick="shopCat = ${i}; renderShop(); window.scrollTo(0, 0)">${esc(c.name)}</button>`).join('');
    const c = shopMenu[shopCat];
    $('shopMenu').innerHTML = c ? `<div class="card">${c.items.map(i => {
        const q = itemQty(i.id);
        const thumb = (i.image || i.video)
            ? `<button type="button" class="shop-thumb ${i.video ? '' : 'novideo'}" ${i.video ? `onclick="openDishVideo(${i.id})" aria-label="Video"` : 'tabindex="-1"'}>
                   ${i.image ? `<img src="${esc(i.image)}" alt="" loading="lazy">` : ''}<span class="pl"><i class="fas fa-play"></i></span></button>` : '';
        return `<div class="shop-item">${thumb}
            <div class="info"><strong>${esc(i.name)}</strong>${i.description ? `<small>${esc(i.description)}</small>` : ''}${i.components?.length ? `<small class="custom"><i class="fas fa-sliders"></i> ${esc(L.self_custom)}</small>` : ''}</div>
            <div class="price">${esc(i.price)}</div>
            <div class="qty">${q ? `<button onclick="itemMinus(${i.id})" aria-label="-">−</button><span>${q}</span>` : ''}<button class="plus" onclick="itemPlus(${i.id})" aria-label="+">+</button></div>
        </div>`; }).join('')}</div>` : `<div class="card"><div class="empty"><?= te('no_items_cat') ?></div></div>`;
    renderCartBar();
}
function openDishVideo(id) {
    const item = itemOf(id);
    if (!item || !item.video) return;
    $('videoTitle').textContent = item.name;
    const v = $('dishVideo');
    v.src = item.video;
    if (item.image) v.poster = item.image; else v.removeAttribute('poster');
    $('videoSheet').classList.add('on');
    v.play().catch(() => {});
}
function closeDishVideo() { const v = $('dishVideo'); v.pause(); v.removeAttribute('src'); v.load(); $('videoSheet').classList.remove('on'); }
function itemPlus(id) {
    if (itemOf(id)?.components?.length) { openCustomize(id); return; }
    addLine(id); cartChanged();
}
function itemMinus(id) {
    for (let i = cart.length - 1; i >= 0; i--) if (cart[i].id === id) { lineInc(i, -1); return; }
}
function lineInc(idx, d) {
    const l = cart[idx];
    if (!l) return;
    l.qty = Math.max(0, Math.min(20, l.qty + d));
    if (!l.qty) cart.splice(idx, 1);
    saveCart(); cartChanged();
}
function cartChanged() {
    renderShop();
    if ($('cartSheet').classList.contains('on')) renderCartLines();
}
function cartSum() { return cartItems().reduce((s, l) => s + l.qty * lineUnit(l), 0); }

let customId = null, customQty = 1;
function openCustomize(id) {
    const it = itemOf(id);
    if (!it) return;
    customId = id; customQty = 1;
    $('customTitle').textContent = it.name;
    $('customList').innerHTML = it.components.map(c => `
        <label class="pick">
            <input type="checkbox" data-cid="${c.id}" data-default="${c.default ? 1 : 0}" ${c.default ? 'checked' : ''} ${c.default && !c.removable ? 'disabled' : ''} onchange="customPrice()">
            ${c.image ? `<img class="comp-img" src="${esc(c.image)}" alt="" loading="lazy">` : ''}
            <span>${esc(c.name)}${c.default && !c.removable ? ` <small class="hint-small">(${esc(L.self_fixed)})</small>` : ''}</span>
            <span class="price">${esc(c.extra_fmt)}</span>
        </label>`).join('');
    customPrice();
    $('customSheet').classList.add('on');
}
function customChoice() {
    const add = [], remove = [];
    document.querySelectorAll('#customList input[data-cid]').forEach(b => {
        const cid = +b.dataset.cid, def = b.dataset.default === '1';
        if (def && !b.checked) remove.push(cid);
        if (!def && b.checked) add.push(cid);
    });
    return { add, remove };
}
function customQtyStep(d) { customQty = Math.max(1, Math.min(20, customQty + d)); customPrice(); }
function customPrice() {
    const { add } = customChoice();
    $('customQty').textContent = customQty;
    $('customAddBtn').textContent = L.self_add_basket.replace('{price}', money(customQty * lineUnit({ id: customId, add })));
}
function customAdd() {
    const { add, remove } = customChoice();
    addLine(customId, add, remove, customQty);
    $('customSheet').classList.remove('on');
    cartChanged();
}
function renderCartBar() {
    const n = cartItems().reduce((s, l) => s + l.qty, 0);
    $('cartBar').hidden = $('shop').hidden || !n;
    $('cartCount').textContent = L.self_dishes.replace('{n}', n);
    $('cartTotal').textContent = money(cartSum());
}
function openCart() { renderCartLines(); $('cartSheet').classList.add('on'); }
function renderCartLines() {
    const lines = cartItems();
    if (!lines.length) { $('cartSheet').classList.remove('on'); return; }
    $('cartLines').innerHTML = lines.map(l => `
        <div class="cart-line">
            <div class="top"><strong>${esc(l.item.name)}${lineMods(l) ? `<small class="mods">${esc(lineMods(l))}</small>` : ''}</strong>
                <span class="lp">${money(l.qty * lineUnit(l))}</span>
                <div class="qty"><button onclick="lineInc(${l.idx}, -1)">−</button><span>${l.qty}</span><button class="plus" onclick="lineInc(${l.idx}, 1)">+</button></div></div>
            <input maxlength="200" placeholder="${esc(L.self_note_ph)}" value="${esc(l.note || '')}" oninput="cart[${l.idx}].note = this.value; saveCart()">
        </div>`).join('');
    $('cartSheetTotal').textContent = money(cartSum());
}
async function sendCart() {
    const lines = cartItems().map(l => ({ id: l.id, qty: l.qty, note: l.note || '', add: l.add || [], remove: l.remove || [] }));
    if (!lines.length) return;
    $('cartSend').disabled = true;
    shopOpen = false;
    const ok = await send({ action: 'send', cart: lines });
    $('cartSend').disabled = false;
    if (!ok) { shopOpen = true; return; }
    cart = []; saveCart();
    $('cartSheet').classList.remove('on');
    window.scrollTo(0, 0);
    toast(L.self_sent);
}

/* ---- "Your order is ready": banner, chime, vibration, system notification ---- */
let wasReady = null;
function notifyReady(s) {
    const ready = !!s.all_ready;
    if (wasReady === false && ready) {
        $('readyTitle').textContent = L.ready_title;
        $('readyBody').textContent = L.ready_body;
        $('readyBanner').hidden = false;
        try { navigator.vibrate && navigator.vibrate([300, 150, 300, 150, 300]); } catch (e) {}
        playChime();
        try { if ('Notification' in window && Notification.permission === 'granted') new Notification(L.ready_title, { body: L.ready_body }); } catch (e) {}
    }
    wasReady = ready;
}
let audioCtx = null;
function unlockAudio() {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    audioCtx = audioCtx || new AC();
    if (audioCtx.state !== 'running') audioCtx.resume().catch(() => {});
    try { if ('Notification' in window && Notification.permission === 'default' && state && state.items.length) Notification.requestPermission(); } catch (e) {}
}
['pointerdown', 'touchstart', 'keydown'].forEach(ev => document.addEventListener(ev, unlockAudio, { passive: true }));
function playChime() {
    if (!audioCtx) return;
    [784, 988, 1175, 1568].forEach((f, n) => {
        const t = audioCtx.currentTime + n * 0.16, o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(audioCtx.destination);
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.45, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.55);
        o.start(t); o.stop(t + 0.6);
    });
}

// Every 10 s (the dishes' progress; nothing to poll while signing up).
(function poll() {
    load().finally(() => setTimeout(poll, 10000));
})();
</script>
</body>
</html>
