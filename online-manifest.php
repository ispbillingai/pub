<?php
/**
 * Web app manifest of the online customers' page: lets it be added to the Home screen
 * as an app (on iPhone the only way to get notifications with the browser closed).
 */

require_once __DIR__ . '/config/database.php';

$ws    = getDBConnection()->query("SELECT name FROM workspaces LIMIT 1")->fetch();
$brand = ($ws['name'] ?? '') ?: 'Focacciami';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name'             => $brand,
    'short_name'       => mb_substr($brand, 0, 12),
    'start_url'        => '/online.php',
    'scope'            => '/online',
    'display'          => 'standalone',
    'background_color' => '#f7f5f2',
    'theme_color'      => '#7a1428',
    'icons'            => [
        ['src' => '/app-icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/app-icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
