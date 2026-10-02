<?php
/**
 * Till API — the ticket of Ordini Cassa (includes/till.php).
 *
 * POST {action: 'checkout', lines: [{id, qty} | {amount}], target_order_id?} → {order_id}: then the payment page
 * POST {action: 'cancel', order_id}                                         → drop an unpaid counter sale
 * POST {action: 'customer', order_id, first_name, last_name, address, street_number, country, phone} → the customer's details on the order
 * POST {action: 'lookup', country, phone}                                   → someone already known by that phone, to fill the box
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/till.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !hasRole(['admin', 'cashier'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'POST only'], 405);
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string) ($input['action'] ?? '');

if ($action === 'checkout') {
    $res = tillCheckout((array) ($input['lines'] ?? []), !empty($input['target_order_id']) ? (int) $input['target_order_id'] : null,
                        (int) getCurrentUser()['id']);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(['success' => true, 'order_id' => $res['ok']]);
}
if ($action === 'customer') {
    $res = tillSaveCustomer((int) ($input['order_id'] ?? 0), $input);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(['success' => true]);
}
if ($action === 'lookup') {
    jsonResponse(['success' => true, 'customer' => tillCustomerLookup((string) ($input['country'] ?? 'IT'), (string) ($input['phone'] ?? ''))]);
}
if ($action === 'cancel') {
    jsonResponse(['success' => tillCancelSale((int) ($input['order_id'] ?? 0))]);
}
jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
