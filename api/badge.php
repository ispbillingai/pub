<?php
/**
 * Staff badge (RFID) — assets/js/badge.js.
 *
 * POST {code, page} → the badge's user takes over this session (staffBadgeLogin):
 * {success, name, redirect}. redirect is the page it was read on when their role
 * may use it (another operator at the same till), otherwise their role's start page.
 * Works logged out too (the login page).
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'POST only'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$user  = staffBadgeLogin((string) ($input['code'] ?? ''));
if (!$user) jsonResponse(['success' => false, 'message' => t('badge_unknown')]);

// The till pages an operator switching at the same device stays on.
$pageRoles = [
    '/cashier/online.php' => ['admin', 'cashier', TILL_OPERATOR_ROLE],
    '/cashier/index.php'  => ['admin', 'cashier'],
];
$page = (string) ($input['page'] ?? '');
jsonResponse([
    'success'  => true,
    'name'     => $user['full_name'],
    'redirect' => in_array($user['role'], $pageRoles[$page] ?? [], true) ? $page : roleHome($user['role']),
]);
