<?php
/**
 * The till's own sales (Ordini Cassa): the "Menu cassa" products
 * (categories marked till_only, Admin > Menu cassa, seen nowhere else) as
 * buttons, plus free amounts typed on the keypad. The ticket either becomes a
 * counter sale (channel 'counter', on a hidden "BANCO" table, the cashier as
 * its waiter) or goes on an online customer's open bill; then the usual
 * payment page takes the money. These lines are handed over at the counter:
 * they never go to the kitchen (status 'served' at once).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/system_place.php';
require_once __DIR__ . '/online_order.php';

const TILL_CHANNEL    = 'counter';
const TILL_MAX_AMOUNT = 9999.99;

/** The hidden room / table counter sales hang off. */
function tillSystemIds(): array
{
    return systemOrderPlace('till_system', 'Banco', 997, 'BANCO');
}

/**
 * The hidden "Varie" item the keypad's free amounts are booked on (its price
 * is the amount typed). It sits in a disabled till-only category, so it shows
 * on no menu, not even the till's buttons.
 */
function tillFreeItemId(): int
{
    $pdo = getDBConnection();
    $id  = (int) getSetting('till_free_item', 0);
    if ($id && $pdo->query("SELECT COUNT(*) FROM menu_items WHERE id = " . $id)->fetchColumn()) return $id;
    $pdo->exec("INSERT INTO menu_categories (name, sort_order, allow_composition, active, till_only) VALUES ('Cassa (importi liberi)', 999, 0, 0, 1)");
    $catId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO menu_items (category_id, name, base_price, active) VALUES (?, 'Varie', 0, 0)")->execute([$catId]);
    $id = (int) $pdo->lastInsertId();
    setSetting('till_free_item', $id);
    return $id;
}

/** The category of the free amounts (to keep it out of Admin > Menu cassa). */
function tillFreeCategoryId(): int
{
    $stmt = getDBConnection()->prepare("SELECT category_id FROM menu_items WHERE id = ?");
    $stmt->execute([tillFreeItemId()]);
    return (int) $stmt->fetchColumn();
}

/** The till's buttons: [['id', 'name', 'color', 'items' => [['id', 'name', 'price', 'amount', 'image', 'barcode']]]]. */
function tillMenu(): array
{
    $rows = getDBConnection()->query("
        SELECT mc.id AS category_id, mc.name AS category, mc.color, mi.id, mi.name, mi.base_price, mi.image_url, mi.barcode
        FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE mc.till_only = 1 AND mc.active = 1 AND mi.active = 1
        ORDER BY mc.sort_order, mc.name, mi.sort_order, mi.name
    ")->fetchAll();
    $menu = [];
    foreach ($rows as $r) {
        $cid = (int) $r['category_id'];
        $menu[$cid] ??= ['id' => $cid, 'name' => $r['category'], 'color' => $r['color'] ?: null, 'items' => []];
        $menu[$cid]['items'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'price' => formatCurrency($r['base_price']), 'amount' => (float) $r['base_price'],
                                     'image' => $r['image_url'] ?: null, 'barcode' => $r['barcode'] ?: null];
    }
    return array_values($menu);
}

/**
 * Book the ticket: [['id' => till product, 'qty' => n] | ['amount' => euros]].
 * $targetOrderId: an online customer's open order to add it to, or null for a
 * new counter sale. Returns ['ok' => order id] or ['error' => lang key].
 */
function tillCheckout(array $lines, ?int $targetOrderId, int $userId): array
{
    $pdo  = getDBConnection();
    $prod = $pdo->prepare("SELECT mi.id, mi.base_price FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                           WHERE mi.id = ? AND mi.active = 1 AND mc.active = 1 AND mc.till_only = 1");
    $book = [];                                         // [menu item id, qty, unit price]
    foreach (array_slice($lines, 0, 100) as $l) {
        if (isset($l['amount'])) {
            $amount = round((float) $l['amount'], 2);
            if ($amount <= 0 || $amount > TILL_MAX_AMOUNT) return ['error' => 'till_err_amount'];
            $book[] = [tillFreeItemId(), 1, $amount];
            continue;
        }
        $qty = (int) ($l['qty'] ?? 0);
        if ($qty < 1) continue;
        $prod->execute([(int) ($l['id'] ?? 0)]);
        if (!$p = $prod->fetch()) return ['error' => 'till_err_product'];
        $book[] = [(int) $p['id'], min($qty, 99), (float) $p['base_price']];
    }
    if (!$book) return ['error' => 'till_err_empty'];

    if ($targetOrderId) {
        $order = getOrderById($targetOrderId);
        if (!$order || ($order['channel'] ?? '') !== ONLINE_CHANNEL || in_array($order['status'], ['paid', 'cancelled'], true)) {
            return ['error' => 'till_err_target'];
        }
        $orderId = (int) $order['id'];
    } else {
        $sys = tillSystemIds();
        $pdo->prepare("
            INSERT INTO orders (order_number, table_id, table_label, room_id, waiter_id, number_of_people, cover_charge_per_person, status, channel)
            VALUES (?, ?, 'BANCO', ?, ?, 0, 0, 'bill_requested', ?)
        ")->execute([generateOrderNumber(), $sys['table_id'], $sys['room_id'], $userId, TILL_CHANNEL]);
        $orderId = (int) $pdo->lastInsertId();
    }

    // Handed over at the counter: served at once, never on the kitchen display or a slip.
    $add = $pdo->prepare("INSERT INTO order_items (order_id, seat, menu_item_id, quantity, unit_price, total_price, status, served_at)
                          VALUES (?, NULL, ?, ?, ?, ?, 'served', NOW())");
    foreach ($book as [$itemId, $qty, $unit]) {
        $add->execute([$orderId, $itemId, $qty, $unit, $unit * $qty]);
    }
    calculateOrderTotals($orderId);
    logActivity($targetOrderId ? 'till_added_to_online_order' : 'till_counter_sale', 'orders', $orderId, ['lines' => count($book)]);
    return ['ok' => $orderId];
}

/** Orders paid from Ordini Cassa: counter sales and online customers' orders. */
function isTillOrder(?array $order): bool
{
    return $order && in_array($order['channel'] ?? '', [TILL_CHANNEL, ONLINE_CHANNEL], true);
}

/**
 * The customer's details as the payment page's "Customer" box shows them:
 * ['first_name', 'last_name', 'address', 'street_number', 'country', 'phone' (national)].
 * An online order not edited at the till yet starts from the customer's sign-up.
 */
function tillOrderCustomer(array $order): array
{
    $name = trim((string) ($order['customer_name'] ?? ''));
    $c = [
        'first_name' => (string) strtok($name, ' '), 'last_name' => trim((string) substr($name, strlen((string) strtok($name, ' ')))),
        'address' => (string) ($order['customer_address'] ?? ''), 'street_number' => (string) ($order['customer_street_number'] ?? ''),
        'country' => (string) ($order['customer_country'] ?: 'IT'), 'phone' => '',
    ];
    if (!empty($order['online_customer_id']) && $c['address'] === '' && ($oc = onlineCustomerById((int) $order['online_customer_id']))) {
        $c = ['first_name' => $oc['first_name'], 'last_name' => $oc['last_name'], 'address' => $oc['address'],
              'street_number' => $oc['street_number'], 'country' => $oc['mobile_country'] ?: 'IT', 'phone' => ''];
        $order['customer_phone'] = $oc['mobile'];
    }
    if (!empty($order['customer_phone'])) $c['phone'] = nationalPhone($c['country'], $order['customer_phone']);
    return $c;
}

/** Save the details typed at the till on the order. Returns ['ok' => true] or ['error' => lang key]. */
function tillSaveCustomer(int $orderId, array $in): array
{
    $order = getOrderById($orderId);
    if (!isTillOrder($order) || in_array($order['status'], ['paid', 'cancelled'], true)) return ['error' => 'till_err_target'];
    $f       = fn($k, $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $name    = trim($f('first_name', 60) . ' ' . $f('last_name', 60));
    $country = strtoupper($f('country', 2)) ?: 'IT';
    $phone   = null;
    if ($f('phone', 20) !== '' && !($phone = internationalPhone($country, $f('phone', 20)))) return ['error' => 'cust_bad_phone'];
    getDBConnection()->prepare("
        UPDATE orders SET customer_name = ?, customer_address = ?, customer_street_number = ?, customer_country = ?, customer_phone = ? WHERE id = ?
    ")->execute([$name !== '' ? $name : null, $f('address', 150) ?: null, $f('street_number', 15) ?: null, $phone ? $country : null, $phone, $orderId]);
    logActivity('till_customer_saved', 'orders', $orderId);
    return ['ok' => true];
}

/**
 * Someone we already know by this phone (an online customer, or the latest
 * order that took their details at the till), to fill in the box; or null.
 */
function tillCustomerLookup(string $country, string $phone): ?array
{
    $e164 = internationalPhone(strtoupper($country) ?: 'IT', $phone);
    if (!$e164) return null;
    if ($oc = onlineCustomerByMobile($e164)) {
        return ['first_name' => $oc['first_name'], 'last_name' => $oc['last_name'], 'address' => $oc['address'], 'street_number' => $oc['street_number']];
    }
    $stmt = getDBConnection()->prepare("SELECT customer_name, customer_address, customer_street_number FROM orders
                                        WHERE customer_phone = ? AND customer_name IS NOT NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$e164]);
    if (!$o = $stmt->fetch()) return null;
    $first = (string) strtok((string) $o['customer_name'], ' ');
    return ['first_name' => $first, 'last_name' => trim(substr((string) $o['customer_name'], strlen($first))),
            'address' => (string) $o['customer_address'], 'street_number' => (string) $o['customer_street_number']];
}

/** A product's code as typed or scanned: trimmed, '' = none. */
function tillBarcode(string $code): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1F]+/', '', $code)), 0, 64);
}

/** Another product already using this code (its name), or null. */
function tillBarcodeTakenBy(string $code, int $exceptItemId = 0): ?string
{
    if ($code === '') return null;
    $stmt = getDBConnection()->prepare("SELECT name FROM menu_items WHERE barcode = ? AND id <> ? LIMIT 1");
    $stmt->execute([$code, $exceptItemId]);
    return $stmt->fetchColumn() ?: null;
}

/** Counter sales booked but not paid yet (the payment was left half-way). */
function tillOpenSales(): array
{
    $stmt = getDBConnection()->prepare("
        SELECT o.id, o.order_number, o.total, o.created_at, u.full_name AS cashier
        FROM orders o JOIN users u ON u.id = o.waiter_id
        WHERE o.channel = ? AND o.status NOT IN ('paid', 'cancelled') ORDER BY o.id
    ");
    $stmt->execute([TILL_CHANNEL]);
    return $stmt->fetchAll();
}

/** Drop a counter sale nobody paid. */
function tillCancelSale(int $orderId): bool
{
    $stmt = getDBConnection()->prepare("UPDATE orders SET status = 'cancelled', closed_at = NOW()
                                        WHERE id = ? AND channel = ? AND status NOT IN ('paid', 'cancelled')");
    $stmt->execute([$orderId, TILL_CHANNEL]);
    if ($stmt->rowCount()) logActivity('till_counter_sale_cancelled', 'orders', $orderId);
    return $stmt->rowCount() > 0;
}
