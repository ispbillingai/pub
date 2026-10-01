<?php
/**
 * Test connectivity to a network area (router): logs into its RouterOS API and,
 * if that works, does one quick ping through it. Admin-only.
 *
 * Body: { area_id: <int> }
 * Returns: { ok: true, latency_ms?: number } or { ok: false, error: "…" }
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/device_monitor.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || $u['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$areaId = (int) ($input['area_id'] ?? 0);
if ($areaId < 1) {
    echo json_encode(['ok' => false, 'error' => 'bad_area']);
    exit;
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM network_areas WHERE id = ?");
$stmt->execute([$areaId]);
$row = $stmt->fetch();
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$area = [
    'host'  => (string) $row['host'],
    'port'  => (int) $row['api_port'],
    'user'  => (string) $row['api_user'],
    'pass'  => (string) $row['api_pass'],
    'count' => max(1, (int) $row['ping_count']),
];

try {
    $api = connectToArea($area);
    // Ping the router's own loopback to prove the API works end-to-end.
    [$up, $ms] = $api->ping('127.0.0.1', 1);
    $api->close();
    echo json_encode(['ok' => true, 'latency_ms' => $ms]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
