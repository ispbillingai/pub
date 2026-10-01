<?php
/**
 * CLI poller — pings the shop devices via the MikroTik RouterOS API and records
 * up/down status in the `devices` table. Run from cron on the server, e.g.:
 *
 *   * * * * * php /var/www/html/ristorante/bin/poll-devices.php >/dev/null 2>&1
 *
 * Prints a one-line summary (and a per-device table) so it's easy to run by hand
 * for a quick check. Exits non-zero if the router was unreachable.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../includes/device_monitor.php';

$res = pollDevices();

echo 'Device poll @ ' . date('Y-m-d H:i:s') . "\n";
if (!$res['ok']) {
    fwrite(STDERR, 'ERROR: router unreachable — ' . ($res['error'] ?? 'unknown') . "\n");
    exit(2);
}

foreach ($res['results'] as $r) {
    printf(
        "  %-16s %-16s %-6s %s\n",
        $r['name'],
        $r['ip'],
        $r['up'] ? 'UP' : 'DOWN',
        $r['up'] && $r['latency_ms'] !== null ? number_format((float) $r['latency_ms'], 1) . ' ms' : '-'
    );
}
printf("%d checked, %d up, %d down\n", $res['checked'], $res['up'], $res['down']);

exit($res['down'] > 0 ? 1 : 0);
