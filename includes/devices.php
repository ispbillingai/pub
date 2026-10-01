<?php
/**
 * Device & payment-integration helpers.
 *
 * Loads config/devices.php (falls back to empty config if absent) and provides
 * currency/money conversion plus a device-event audit logger. Safe to include
 * from any page or API endpoint.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Return the device config array, or a single section.
 * Loads config/devices.php once. Returns [] for missing config/sections.
 */
function deviceConfig(?string $section = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/devices.php';
        $cfg = is_file($file) ? require $file : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        // Overlay printer settings configured in the admin panel (DB) on top of
        // the file defaults, so printers (and their IPs) are managed from the UI.
        require_once __DIR__ . '/settings.php';
        $dbPrinters = getSetting('printers', []);
        if (is_array($dbPrinters)) {
            foreach (['kitchen_printer', 'cashier_printer', 'fiscal_printer'] as $pk) {
                if (!empty($dbPrinters[$pk]) && is_array($dbPrinters[$pk])) {
                    $cfg[$pk] = array_merge($cfg[$pk] ?? [], $dbPrinters[$pk]);
                }
            }
        }
        // Overlay card-gateway settings from the admin Payment Gateways page on
        // top of the file defaults, so the Ingenico (pos) and Dojo terminals are
        // managed from the UI. Only non-empty fields override (a blank field in
        // the form keeps the existing/file value — this is how the masked Dojo
        // secret key survives a save where the field was left blank).
        $pg = getSetting('payment_gateways', []);
        if (is_array($pg)) {
            foreach (['pos', 'dojo'] as $gw) {
                if (!empty($pg[$gw]) && is_array($pg[$gw])) {
                    $over = array_filter($pg[$gw], static fn($v) => $v !== '' && $v !== null);
                    $cfg[$gw] = array_merge($cfg[$gw] ?? [], $over);
                }
            }
        }
    }
    if ($section === null) {
        return $cfg;
    }
    return $cfg[$section] ?? [];
}

/**
 * Load a till (station of type 'till') by id. Cached per request. Returns null
 * if missing/inactive or the stations table doesn't exist yet.
 */
function getTillById(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    try {
        $pdo  = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ? AND type = 'till' AND active = 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $cache[$id] = ($row ?: null);
    } catch (Throwable $e) {
        return $cache[$id] = null;
    }
}

/**
 * Device config for the till an order was routed to (orders.till_id), falling
 * back to the global deviceConfig($section) when there's no till or the till
 * leaves that section blank. This is how each "Cassa" uses its own hardware.
 *
 * @param array|null $order an order row (must contain 'till_id' to route)
 * @param string     $section 'fiscal_printer' | 'pos' | 'cashmatic' | 'cashier_printer'
 */
function tillConfigForOrder(?array $order, string $section): array
{
    $global = (array) deviceConfig($section);
    $till   = getTillById(isset($order['till_id']) ? (int) $order['till_id'] : 0);
    if (!$till) {
        return $global;
    }

    // The till's bill printer lives in the printer_* columns.
    if ($section === 'cashier_printer') {
        if (empty($till['printer_host'])) {
            return $global;
        }
        return array_merge($global, [
            'enabled'  => (int) $till['printer_enabled'] === 1,
            'host'     => (string) $till['printer_host'],
            'port'     => (int) $till['printer_port'],
            'width'    => (int) $till['printer_width'],
            'codepage' => (int) $till['printer_codepage'],
        ]);
    }

    // Fiscal / POS / Cashmatic live in the device_config JSON bundle.
    $dc = $till['device_config'] ?? null;
    if (is_string($dc)) {
        $dc = json_decode($dc, true);
    }
    if (!is_array($dc)) {
        return $global;
    }
    $key = $section === 'fiscal_printer' ? 'fiscal' : $section; // 'pos' | 'cashmatic'
    $sub = $dc[$key] ?? null;
    if (!is_array($sub) || empty($sub['base_url'])) {
        return $global;
    }

    // Overlay only the values the till actually provides.
    $over = [];
    foreach ($sub as $k => $v) {
        if ($v !== '' && $v !== null) {
            $over[$k] = $v;
        }
    }
    $over['enabled'] = true;
    return array_merge($global, $over);
}

/**
 * Which card gateway(s) the cashier offers, chosen on the admin Payment
 * Gateways page: 'pos' (Ingenico), 'dojo', 'both', or 'none'. Defaults to
 * 'both' when unset so existing installs keep showing whatever is configured.
 */
function activeCardGateway(): string
{
    require_once __DIR__ . '/settings.php';
    $pg = getSetting('payment_gateways', []);
    $a  = is_array($pg) ? ($pg['active'] ?? null) : null;
    return in_array($a, ['pos', 'dojo', 'both', 'none'], true) ? $a : 'both';
}

/** ISO 4217 numeric currency code (default 978 = EUR). */
function currencyCode(): int
{
    return (int) (deviceConfig('currency')['code'] ?? 978);
}

/** Currency symbol for display (default €). */
function currencySymbol(): string
{
    return (string) (deviceConfig('currency')['symbol'] ?? '€');
}

/** Convert a DECIMAL money amount (e.g. 10.50) to integer cents (1050). */
function toCents($amount): int
{
    return (int) round(((float) $amount) * 100);
}

/** Convert integer cents (1050) back to a float amount (10.50). */
function fromCents(int $cents): float
{
    return $cents / 100;
}

/** Format an amount with the configured currency symbol. */
function moneyFormat($amount): string
{
    return currencySymbol() . number_format((float) $amount, 2);
}

/**
 * Append a row to the device_events audit log. Never throws — logging must
 * never break a payment flow.
 */
function logDeviceEvent(
    string $device,
    string $eventType,
    ?int $orderId = null,
    array $details = [],
    ?int $paymentId = null
): void {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO device_events (order_id, payment_id, device, event_type, details)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $paymentId,
            $device,
            $eventType,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('logDeviceEvent failed: ' . $e->getMessage());
    }
}
