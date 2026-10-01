<?php
/**
 * "Clienti online": a shop with no tables. Everybody scans the same QR
 * (online.php). A new customer signs up once: name, surname, address, house
 * number, mobile (the code arrives there on WhatsApp), landline, intolerances
 * and the marketing consent; a returning customer types only the mobile and
 * gets a code. Then they order from the menu; the dishes go to the kitchen as
 * the waiter's would, and they pay at the till when they collect.
 *
 * Details, the IP they signed up from and every access (sign-up, login,
 * order, with its IP) are kept in online_customers / online_customer_access
 * (admin/online-customers.php). Each customer has one open order at a time:
 * more dishes sent before paying go on the same order as an ADDITION.
 *
 * An online order is a normal order with channel = 'online' (no "table free"
 * alert when paid; the slips print *** ONLINE *** and the intolerances).
 * Every screen JOINs a table, room and waiter, so, as with Glovo, the orders
 * hang off a hidden room "Clienti online" (active = 0), a table "ONLINE" that
 * is never occupied and a disabled system user. Once sent, the order shows in
 * the cashier's "bills to collect" with the customer's name.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/whatsapp_guest.php';
require_once __DIR__ . '/table_requests.php';
require_once __DIR__ . '/kitchen_ticket.php';
require_once __DIR__ . '/consent.php';
require_once __DIR__ . '/self_order.php';

const ONLINE_CHANNEL       = 'online';
const ONLINE_COOKIE        = 'online_customer';
const ONLINE_COOKIE_DAYS   = 180;   // this phone stays signed in for 6 months
const ONLINE_CODE_TTL      = 900;   // the WhatsApp code lasts 15 minutes
const ONLINE_CODE_GAP      = 60;    // a new code at most once a minute
const ONLINE_CODE_MAX_SENDS = 5;    // codes per browser session
const ONLINE_CODE_MAX_TRIES = 5;    // wrong codes before a new one is needed

/** ['enabled' => bool] */
function onlineOrderSettings(): array
{
    $s = (array) getSetting('online_order', []);
    return ['enabled' => !empty($s['enabled'])];
}

/** On, and WhatsApp can carry the code. */
function onlineOrderEnabled(): bool
{
    return onlineOrderSettings()['enabled'] && guestWhatsappEnabled();
}

/** The one QR everybody scans. */
function onlineOrderUrl(): string
{
    require_once __DIR__ . '/menu_pdf.php';
    return publicUrl('/online.php');
}

/** The customer's IP address (Apache talks to the phone directly: no proxy). */
function onlineClientIp(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/**
 * Hidden room / table / user every online order hangs off. Created on first
 * use and remembered in settings.online_system (like glovoSystemIds()).
 *
 * @return array{room_id:int, table_id:int, user_id:int}
 */
function onlineSystemIds(): array
{
    $pdo = getDBConnection();
    $ids = getSetting('online_system', []);
    if (is_array($ids) && !empty($ids['room_id']) && !empty($ids['table_id']) && !empty($ids['user_id'])) {
        $ok = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM rooms WHERE id = ?) +
                (SELECT COUNT(*) FROM tables_restaurant WHERE id = ?) +
                (SELECT COUNT(*) FROM users WHERE id = ?)");
        $ok->execute([$ids['room_id'], $ids['table_id'], $ids['user_id']]);
        if ((int) $ok->fetchColumn() === 3) {
            return array_map('intval', $ids);
        }
    }

    $workspaceId = (int) ($pdo->query("SELECT id FROM workspaces ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
    $pdo->prepare("INSERT INTO rooms (workspace_id, name, sort_order, active) VALUES (?, 'Clienti online', 998, 0)")
        ->execute([$workspaceId]);
    $roomId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO tables_restaurant (room_id, table_number, capacity, status) VALUES (?, 'ONLINE', 0, 'free')")
        ->execute([$roomId]);
    $tableId = (int) $pdo->lastInsertId();

    $userId = (int) ($pdo->query("SELECT id FROM users WHERE username = 'cliente_online' LIMIT 1")->fetchColumn() ?: 0);
    if (!$userId) {
        $pdo->prepare("INSERT INTO users (username, password, full_name, role, active) VALUES ('cliente_online', ?, 'Cliente online', 'waiter', 0)")
            ->execute([password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
    }

    $ids = ['room_id' => $roomId, 'table_id' => $tableId, 'user_id' => $userId];
    setSetting('online_system', $ids);
    return $ids;
}

function onlineCustomerById(int $id): ?array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM online_customers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function onlineCustomerByMobile(string $mobile): ?array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM online_customers WHERE mobile = ?");
    $stmt->execute([$mobile]);
    return $stmt->fetch() ?: null;
}

/** Sign-up, login, order: with the IP it came from. */
function onlineLogAccess(int $customerId, string $event, ?int $orderId = null): void
{
    $pdo = getDBConnection();
    $ip  = onlineClientIp();
    $pdo->prepare("INSERT INTO online_customer_access (customer_id, event, ip_address, user_agent, order_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$customerId, $event, $ip, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $orderId]);
    $pdo->prepare("UPDATE online_customers SET last_ip = ?, last_seen_at = NOW() WHERE id = ?")->execute([$ip, $customerId]);
}

/* ---- Staying signed in: the session, plus a signed cookie so the phone
 * goes straight to the menu next time (no code to type again). ---- */

function onlineCookieSig(int $id, int $exp, string $mobile): string
{
    return hash_hmac('sha256', 'online|' . $id . '|' . $exp . '|' . $mobile, appSecret());
}

function onlineSignIn(array $customer): void
{
    session_regenerate_id(true);
    $_SESSION['online_customer_id'] = (int) $customer['id'];
    $exp = time() + ONLINE_COOKIE_DAYS * 86400;
    setcookie(ONLINE_COOKIE, $customer['id'] . '.' . $exp . '.' . onlineCookieSig((int) $customer['id'], $exp, $customer['mobile']), [
        'expires' => $exp, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function onlineSignOut(): void
{
    unset($_SESSION['online_customer_id'], $_SESSION['online_reg']);
    setcookie(ONLINE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

/** The signed-in customer (active ones only), or null. */
function onlineCurrentCustomer(): ?array
{
    $id = (int) ($_SESSION['online_customer_id'] ?? 0);
    if (!$id && preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', (string) ($_COOKIE[ONLINE_COOKIE] ?? ''), $m) && (int) $m[2] > time()) {
        $c = onlineCustomerById((int) $m[1]);
        if ($c && hash_equals(onlineCookieSig((int) $c['id'], (int) $m[2], $c['mobile']), $m[3])) {
            $id = (int) $c['id'];
            $_SESSION['online_customer_id'] = $id;
            onlineLogAccess($id, 'login');
        }
    }
    if (!$id) return null;
    $c = onlineCustomerById($id);
    if (!$c || !$c['active']) { onlineSignOut(); return null; }
    return $c;
}

/**
 * Step 1, new customer ($in['mode'] = 'register': every field) or returning
 * one ('login': just the mobile). Checks, keeps it in this browser's session
 * and sends the code on WhatsApp. Returns ['ok' => true] or ['error' => lang key].
 */
function onlineRequestCode(array $in): array
{
    if (!onlineOrderEnabled()) return ['error' => 'online_err_off'];
    $mode    = ($in['mode'] ?? '') === 'login' ? 'login' : 'register';
    $country = strtoupper(trim((string) ($in['country'] ?? 'IT')));
    $mobile  = internationalPhone($country, (string) ($in['mobile'] ?? ''));
    if (!$mobile) return ['error' => 'cust_bad_phone'];
    $known = onlineCustomerByMobile($mobile);

    $data = ['mode' => $mode, 'mobile' => $mobile, 'country' => $country];
    if ($mode === 'login') {
        if (!$known) return ['error' => 'online_err_not_found'];
        if (!$known['active']) return ['error' => 'online_err_disabled'];
    } else {
        if ($known) return ['error' => 'online_err_already'];
        $f = fn($k, $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $data += [
            'first_name' => $f('first_name', 60), 'last_name' => $f('last_name', 60),
            'address' => $f('address', 150), 'street_number' => $f('street_number', 15),
            'landline' => $f('landline', 25), 'intolerances' => $f('intolerances', 500),
            'consent' => !empty($in['consent']),
        ];
        if ($data['first_name'] === '' || $data['last_name'] === '' || $data['address'] === '' || $data['street_number'] === '') {
            return ['error' => 'online_err_fields'];
        }
        if ($data['landline'] !== '' && !preg_match('/^\+?[\d\s\/.-]{5,25}$/', $data['landline'])) {
            return ['error' => 'online_err_landline'];
        }
    }

    $prev = $_SESSION['online_reg'] ?? null;
    if ($prev && time() - $prev['sent_at'] < ONLINE_CODE_GAP) return ['error' => 'self_err_wait'];
    $sends = ($prev['sends'] ?? 0) + 1;
    if ($sends > ONLINE_CODE_MAX_SENDS) return ['error' => 'online_err_too_many'];
    // The same number can't be flooded from several browsers either.
    $stmt = getDBConnection()->prepare("SELECT 1 FROM whatsapp_outbox WHERE kind = 'online_code' AND phone = ? AND created_at > NOW() - INTERVAL ? SECOND LIMIT 1");
    $stmt->execute([$mobile, ONLINE_CODE_GAP]);
    if ($stmt->fetchColumn()) return ['error' => 'self_err_wait'];

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['online_reg'] = $data + [
        'lang' => currentLang() === 'it' ? 'it' : 'en',
        'code' => $code, 'sent_at' => time(), 'sends' => $sends, 'tries' => 0,
    ];
    queueGuestWhatsapp(null, null, 'online_code', $mobile, tIn(guestLang($country), 'online_code_text', [
        'restaurant' => restaurantName(), 'code' => $code,
    ]), null, null, 20);
    return ['ok' => true];
}

/** What step 1 left in this browser (for the page), or null. */
function onlineCodePending(): ?array
{
    $r = $_SESSION['online_reg'] ?? null;
    if (!$r || time() - $r['sent_at'] > ONLINE_CODE_TTL) return null;
    return ['phone_end' => substr($r['mobile'], -4), 'mode' => $r['mode'],
            'resend_in' => max(0, ONLINE_CODE_GAP - (time() - $r['sent_at']))];
}

/**
 * Step 2: the code. Right → the new customer is saved (with their IP and
 * consent) or the returning one let in, and this phone stays signed in.
 * Returns ['ok' => customer] or ['error' => lang key].
 */
function onlineVerifyCode(string $code): array
{
    $r = $_SESSION['online_reg'] ?? null;
    if (!$r || time() - $r['sent_at'] > ONLINE_CODE_TTL) return ['error' => 'self_err_expired'];
    if ($r['tries'] >= ONLINE_CODE_MAX_TRIES) return ['error' => 'self_err_too_many_tries'];
    if (!hash_equals($r['code'], preg_replace('/\D/', '', $code))) {
        $_SESSION['online_reg']['tries']++;
        return ['error' => 'guest_code_bad'];
    }
    unset($_SESSION['online_reg']);

    $pdo      = getDBConnection();
    $customer = onlineCustomerByMobile($r['mobile']);
    if ($r['mode'] === 'register' && !$customer) {
        $pdo->prepare("
            INSERT INTO online_customers (first_name, last_name, address, street_number, mobile, mobile_country, landline,
                                          intolerances, marketing_consent, registration_ip)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $r['first_name'], $r['last_name'], $r['address'], $r['street_number'], $r['mobile'], $r['country'],
            $r['landline'] !== '' ? $r['landline'] : null, $r['intolerances'] !== '' ? $r['intolerances'] : null,
            $r['consent'] ? 1 : 0, onlineClientIp(),
        ]);
        $customer = onlineCustomerById((int) $pdo->lastInsertId());
        onlineLogAccess((int) $customer['id'], 'register');
        logActivity('online_customer_registered', 'online_customers', (int) $customer['id'], ['phone_end' => substr($r['mobile'], -4)]);
        // The marketing consent as they gave it (with its text, as proof), in
        // the same register the campaigns read.
        setConsent($r['mobile'], $r['consent'] ? 'granted' : 'declined', 'online', consentText('prompt', $r['lang']), null, $r['lang']);
        if ($r['consent']) sendConsentConfirmation($r['mobile']);
    } elseif (!$customer || !$customer['active']) {
        return ['error' => 'online_err_disabled'];
    } else {
        onlineLogAccess((int) $customer['id'], 'login');
    }
    onlineSignIn($customer);
    return ['ok' => $customer];
}

/** The customer's order still to collect/pay, or null. */
function onlineOpenOrder(array $customer): ?array
{
    $stmt = getDBConnection()->prepare("
        SELECT id FROM orders WHERE online_customer_id = ? AND channel = ? AND status NOT IN ('paid', 'cancelled')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([(int) $customer['id'], ONLINE_CHANNEL]);
    $id = $stmt->fetchColumn();
    return $id ? getOrderById((int) $id) : null;
}

/** "ONLINE · Mario R." — what the kitchen, the slips and the till show instead of a table. */
function onlineOrderLabel(array $customer): string
{
    return mb_substr('ONLINE · ' . $customer['first_name'] . ' ' . mb_substr($customer['last_name'], 0, 1) . '.', 0, 100);
}

/**
 * The cart goes to the kitchen: on the customer's open order, or a new one.
 * The intolerances go in the order notes (printed on every slip). The order
 * then waits at the till as a bill to collect. Returns ['ok' => dishes] or ['error' => lang key].
 */
function onlineSendCart(array $customer, array $cart): array
{
    if (!onlineOrderEnabled()) return ['error' => 'online_err_off'];
    $pdo   = getDBConnection();
    $order = onlineOpenOrder($customer);
    $new   = !$order;
    if ($new) {
        $sys   = onlineSystemIds();
        $notes = trim((string) $customer['intolerances']) !== '' ? 'INTOLLERANZE: ' . trim($customer['intolerances']) : null;
        $pdo->prepare("
            INSERT INTO orders (order_number, table_id, table_label, room_id, waiter_id, number_of_people, cover_charge_per_person,
                                status, notes, channel, customer_name, customer_country, customer_phone, created_by_guest, online_customer_id)
            VALUES (?, ?, ?, ?, ?, 0, 0, 'open', ?, ?, ?, ?, ?, 1, ?)
        ")->execute([
            generateOrderNumber(), $sys['table_id'], onlineOrderLabel($customer), $sys['room_id'], $sys['user_id'], $notes,
            ONLINE_CHANNEL, trim($customer['first_name'] . ' ' . $customer['last_name']), $customer['mobile_country'],
            $customer['mobile'], (int) $customer['id'],
        ]);
        $order = getOrderById((int) $pdo->lastInsertId());
    }
    $n = addGuestCartItems((int) $order['id'], $cart);
    if (!$n) {
        if ($new) $pdo->prepare("UPDATE orders SET status = 'cancelled', closed_at = NOW() WHERE id = ?")->execute([(int) $order['id']]);
        return ['error' => 'self_err_empty'];
    }
    calculateOrderTotals((int) $order['id']);
    sendPendingToKitchen((int) $order['id']);
    // Paid at the till on collection: it shows (and the cashiers are told) at once.
    markOrderBillRequested((int) $order['id'], null, true);
    onlineLogAccess((int) $customer['id'], 'order', (int) $order['id']);
    logActivity('online_order_sent', 'orders', (int) $order['id'], ['dishes' => $n]);
    return ['ok' => $n];
}

/** What the customer sees: their order's dishes and how each is doing, the total. */
function onlineOrderState(array $customer): array
{
    $order = onlineOpenOrder($customer);
    $items = [];
    if ($order) {
        foreach (getOrderItems((int) $order['id']) as $r) {
            if ($r['status'] === 'cancelled') continue;
            $items[] = [
                'id'       => (int) $r['id'],
                'name'     => $r['item_name'],
                'quantity' => (int) $r['quantity'],
                'status'   => $r['status'],
                'label'    => t('online_st_' . $r['status']),
            ];
        }
    }
    return [
        'success'  => true,
        'enabled'  => onlineOrderEnabled(),
        'customer' => ['first_name' => $customer['first_name']],
        'order'    => $order ? ['number' => $order['order_number']] : null,
        'items'    => $items,
        'all_ready'=> $items && !array_filter($items, fn($i) => !in_array($i['status'], ['ready', 'served'], true)),
        'total_fmt'=> formatCurrency($order['total'] ?? 0),
    ];
}
