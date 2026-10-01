<?php
/**
 * Device status API (admin).
 *
 *   GET  /api/devices.php          -> current status of all devices (JSON)
 *   POST /api/devices.php {poll:1}  -> poll the router now, then return status
 *
 * The admin Devices page polls the GET endpoint every few seconds to refresh the
 * table live. The POST form lets an admin force an immediate re-check without
 * waiting for the cron.
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || $u['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$pdo = getDBConnection();

// Optional on-demand poll.
$pollResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($input['poll'])) {
        require_once __DIR__ . '/../includes/device_monitor.php';
        $pollResult = pollDevices();
    }
}

$rows = $pdo->query(
    "SELECT name, ip, status, latency_ms, last_seen_at, last_checked_at, active
       FROM devices ORDER BY sort_order, id"
)->fetchAll();

echo json_encode([
    'ok'        => true,
    'timestamp' => time(),
    'devices'   => $rows,
    'poll'      => $pollResult, // null unless a poll was requested
]);
