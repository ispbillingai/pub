<?php
/**
 * Admin: test that a card gateway's credentials reach the provider.
 *   { gateway: "pos" }  -> PosClient::status()   (Ingenico via RTS)
 *   { gateway: "dojo" } -> DojoClient::testConnection() (Dojo Cloud API)
 * Uses the SAVED config (file + admin overlay), so save before testing.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/PosClient.php';
require_once __DIR__ . '/../includes/DojoClient.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || ($u['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$gw    = (string) ($input['gateway'] ?? '');

if ($gw === 'pos') {
    $r = (new PosClient(deviceConfig('pos')))->status();
    echo json_encode(['ok' => !empty($r['ok']), 'state' => $r['state'] ?? '', 'error' => $r['error'] ?? null]);
    exit;
}
if ($gw === 'dojo') {
    // No terminal id saved yet -> the result carries the account's terminals.
    $r = (new DojoClient(deviceConfig('dojo')))->testConnection();
    echo json_encode(['ok' => !empty($r['ok']), 'state' => $r['state'] ?? '', 'error' => $r['error'] ?? null,
                      'terminals' => $r['terminals'] ?? null]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_gateway']);
