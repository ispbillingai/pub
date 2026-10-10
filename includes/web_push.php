<?php
/**
 * Web Push: notifications on the online customers' phones even with the page and the
 * browser closed. Android: always. iPhone (iOS 16.4+): once online.php is added to the
 * Home screen and opened from there. The customer turns them on in online.php ("Attiva
 * le notifiche"); the browser hands over a subscription (endpoint + keys), kept in
 * push_subscriptions (migration 054). The service worker /sw.js shows what arrives.
 *
 * Sent: order received, ready / on its way, paid (pushToCustomer, from
 * includes/online_order.php) and the promotions from Admin > Clienti online to
 * the phones that said yes to them (pushPromo).
 *
 * No library: VAPID (RFC 8292, an ES256 JWT) and the aes128gcm payload encryption
 * (RFC 8291), with PHP's openssl. The VAPID key pair is made once and kept in settings.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';

const PUSH_TTL = 86400;   // a phone that is off gets it within a day, or never
// Only the browsers' push services: the server never posts to any other address it is given.
const PUSH_HOSTS = '/(^|\.)(googleapis\.com|push\.apple\.com|mozilla\.com|mozaws\.net|notify\.windows\.com)$/i';

function pushB64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function pushB64uDecode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** A P-256 public key as its 65 raw bytes (0x04 || x || y). */
function pushRawPublic($key): string
{
    $d = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

/** The server's VAPID keys: ['private_pem', 'public' (base64url, for the browser)], made on first use. */
function pushVapid(): array
{
    static $v = null;
    if ($v) return $v;
    $v = getSetting('web_push_vapid', []);
    if (empty($v['private_pem']) || empty($v['public'])) {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $v = ['private_pem' => $pem, 'public' => pushB64u(pushRawPublic($key))];
        setSetting('web_push_vapid', $v);
    }
    return $v;
}

/**
 * Who sends (the JWT's "sub", required by Apple): the site's https address, as seen in
 * the last web request (sends from the command line have no host of their own).
 */
function pushSite(): string
{
    require_once __DIR__ . '/menu_pdf.php';
    $site = rtrim(publicUrl(''), '/');
    if (PHP_SAPI !== 'cli' && preg_match('#^https://#', $site)) {
        if (getSetting('web_push_site', '') !== $site) setSetting('web_push_site', $site);
        return $site;
    }
    return (string) getSetting('web_push_site', '');
}

/** An ECDSA DER signature as the 64 raw bytes (r || s) a JWT wants. */
function pushDerToRaw(string $der): string
{
    $o   = 2;                                   // past SEQUENCE + its (short) length
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len  = ord($der[$o + 1]);
        $out .= str_pad(ltrim(substr($der, $o + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $o   += 2 + $len;
    }
    return $out;
}

/** "vapid t=<JWT>, k=<public key>" for one push service (one JWT per service, 12 h). */
function pushVapidHeader(string $endpoint): string
{
    static $cache = [];
    $p   = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (isset($cache[$aud])) return $cache[$aud];
    $v = pushVapid();
    $jwt = pushB64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
         . pushB64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => pushSite()], JSON_UNESCAPED_SLASHES));
    openssl_sign($jwt, $der, $v['private_pem'], OPENSSL_ALGO_SHA256);
    return $cache[$aud] = 'vapid t=' . $jwt . '.' . pushB64u(pushDerToRaw($der)) . ', k=' . $v['public'];
}

/** The browser's public key (65 raw bytes) as PEM, for openssl. */
function pushPublicPem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * The message encrypted for one browser (RFC 8291, aes128gcm, one record). $salt and
 * $local (our one-time key) are random; given only to check against the RFC's example.
 */
function pushEncrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, $local = null): string
{
    $local    = $local ?: openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPublic = pushRawPublic($local);
    $ecdh     = openssl_pkey_derive(openssl_pkey_get_public(pushPublicPem($uaPublic)), $local, 32);
    if ($ecdh === false) throw new RuntimeException('ecdh');
    $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
    $ikm    = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
    $salt   = $salt ?? random_bytes(16);
    $prk    = hash_hmac('sha256', $ikm, $salt, true);
    $cek    = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce  = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/**
 * Send one message {title, body, url, tag} to subscriptions (push_subscriptions rows),
 * 50 at a time in parallel. Ones the service says are gone (404/410), or failing 5 times
 * in a row, are switched off. Returns [sent, failed].
 */
function pushSend(array $subs, array $msg, string $urgency = 'normal'): array
{
    if (!$subs || !function_exists('curl_multi_init')) return [0, count($subs)];
    $payload = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo     = getDBConnection();
    $ok      = $pdo->prepare("UPDATE push_subscriptions SET last_ok_at = NOW(), failures = 0 WHERE id = ?");
    $bad     = $pdo->prepare("UPDATE push_subscriptions SET failures = failures + 1, active = IF(? OR failures >= 5, 0, active) WHERE id = ?");
    $sent = $failed = 0;
    foreach (array_chunk($subs, 50) as $chunk) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($chunk as $s) {
            try {
                $body = pushEncrypt($payload, pushB64uDecode($s['p256dh']), pushB64uDecode($s['auth']));
            } catch (Throwable $e) {
                $failed++;
                $bad->execute([0, (int) $s['id']]);
                continue;
            }
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['TTL: ' . PUSH_TTL, 'Urgency: ' . $urgency, 'Content-Type: application/octet-stream',
                                       'Content-Encoding: aes128gcm', 'Authorization: ' . pushVapidHeader($s['endpoint'])],
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[(int) $s['id']] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1);
        } while ($running && $status === CURLM_OK);
        foreach ($hs as $id => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code >= 200 && $code < 300) {
                $sent++;
                $ok->execute([$id]);
            } else {
                $failed++;
                $gone = in_array($code, [404, 410], true);   // permission taken back, app removed
                $bad->execute([$gone ? 1 : 0, $id]);
                if (!$gone) error_log('[web-push] subscription ' . $id . ': HTTP ' . $code . ' ' . substr((string) curl_multi_getcontent($ch), 0, 200));
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return [$sent, $failed];
}

/**
 * This browser says yes (or says it again, e.g. after signing in as someone else):
 * $sub = PushSubscription.toJSON() {endpoint, keys: {p256dh, auth}}; $promos: promotions too.
 */
function pushSubscribe(int $customerId, array $sub, bool $promos): bool
{
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $p256dh   = (string) ($sub['keys']['p256dh'] ?? '');
    $auth     = (string) ($sub['keys']['auth'] ?? '');
    $host     = (string) parse_url($endpoint, PHP_URL_HOST);
    if (!preg_match('#^https://#i', $endpoint) || strlen($endpoint) > 1000 || !preg_match(PUSH_HOSTS, $host)
        || strlen(pushB64uDecode($p256dh)) !== 65 || strlen(pushB64uDecode($auth)) !== 16) {
        return false;
    }
    getDBConnection()->prepare("
        INSERT INTO push_subscriptions (online_customer_id, endpoint, endpoint_hash, p256dh, auth, promos, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE online_customer_id = VALUES(online_customer_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh),
                                auth = VALUES(auth), promos = VALUES(promos), user_agent = VALUES(user_agent), active = 1, failures = 0
    ")->execute([$customerId, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, $promos ? 1 : 0,
                 mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    pushSite();   // remember the site's address for sends made outside a web request
    return true;
}

/** Signed out on this phone: its notifications stop (they were the previous customer's). */
function pushForget(string $endpoint): void
{
    if ($endpoint === '') return;
    getDBConnection()->prepare("UPDATE push_subscriptions SET active = 0, online_customer_id = NULL WHERE endpoint_hash = ?")
        ->execute([hash('sha256', $endpoint)]);
}

/** A notification about their order to every phone of one customer. Returns how many got it. */
function pushToCustomer(int $customerId, string $title, string $body, string $tag = 'order'): int
{
    $st = getDBConnection()->prepare("SELECT * FROM push_subscriptions WHERE online_customer_id = ? AND active = 1");
    $st->execute([$customerId]);
    $subs = $st->fetchAll();
    if (!$subs) return 0;
    return pushSend($subs, ['title' => $title, 'body' => $body, 'url' => '/online.php', 'tag' => $tag], 'high')[0];
}

/** How many phones get notifications: ['devices', 'customers', 'promos'] (promos = phones that want promotions). */
function pushStats(): array
{
    return getDBConnection()->query("
        SELECT COUNT(*) AS devices, COUNT(DISTINCT s.online_customer_id) AS customers, COALESCE(SUM(s.promos), 0) AS promos
        FROM push_subscriptions s JOIN online_customers c ON c.id = s.online_customer_id
        WHERE s.active = 1 AND c.active = 1
    ")->fetch();
}

/**
 * A notification from Admin > Clienti online, kept (push_promos): tapping it opens
 * online.php?promo=<id>, the offer again with its link as a button.
 * $customerIds null: every phone that said yes to promotions. A list: the phones of those
 * customers only (with notifications on), who alone see it in their offers box
 * (push_promo_targets). Returns [sent, failed].
 */
function pushPromo(string $title, string $body, string $url = '', ?array $customerIds = null): array
{
    $pdo = getDBConnection();
    $pdo->prepare("INSERT INTO push_promos (title, body, url, created_by) VALUES (?, ?, ?, ?)")
        ->execute([$title, $body, $url !== '' ? $url : null, $_SESSION['user_id'] ?? null]);
    $id = (int) $pdo->lastInsertId();
    if ($customerIds === null) {
        $subs = $pdo->query("
            SELECT s.* FROM push_subscriptions s JOIN online_customers c ON c.id = s.online_customer_id
            WHERE s.active = 1 AND s.promos = 1 AND c.active = 1
        ")->fetchAll();
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        $add = $pdo->prepare("INSERT IGNORE INTO push_promo_targets (promo_id, customer_id) VALUES (?, ?)");
        foreach ($ids as $cid) $add->execute([$id, $cid]);
        $subs = [];
        if ($ids) {
            $st = $pdo->prepare("SELECT s.* FROM push_subscriptions s JOIN online_customers c ON c.id = s.online_customer_id
                                 WHERE s.active = 1 AND c.active = 1 AND s.online_customer_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute($ids);
            $subs = $st->fetchAll();
        }
    }
    $res = pushSend($subs, ['title' => $title, 'body' => $body, 'url' => '/online.php?promo=' . $id, 'tag' => 'promo-' . $id]);
    $pdo->prepare("UPDATE push_promos SET sent = ?, failed = ? WHERE id = ?")->execute([$res[0], $res[1], $id]);
    logActivity('push_promo_sent', 'push_promos', $id, ['title' => $title, 'customers' => $customerIds, 'sent' => $res[0], 'failed' => $res[1]]);
    return $res;
}

/** A test to one customer's phones only (only they see it). Returns [sent, failed]. */
function pushPromoTest(int $customerId, string $title, string $body, string $url = ''): array
{
    return pushPromo($title, $body, $url, [$customerId]);
}

/**
 * The customers whose notifications are on, to choose from in Admin > Clienti online:
 * [['id', 'name', 'mobile', 'devices', 'promos' (on at least one phone)]], by name.
 */
function pushRecipients(): array
{
    return getDBConnection()->query("
        SELECT c.id, TRIM(CONCAT(c.first_name, ' ', c.last_name)) AS name, c.mobile,
               COUNT(*) AS devices, MAX(s.promos) AS promos
        FROM push_subscriptions s JOIN online_customers c ON c.id = s.online_customer_id
        WHERE s.active = 1 AND c.active = 1
        GROUP BY c.id ORDER BY c.first_name, c.last_name
    ")->fetchAll();
}

/** One promotion sent (for online.php?promo=), or null. */
function pushPromoById(int $id): ?array
{
    $st = getDBConnection()->prepare("SELECT id, title, body, url, created_at FROM push_promos WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * The offers still running (sent in the last $days days), newest first, for the coloured
 * box at the top of online.php: [['id', 'title', 'body', 'url', 'date']]. One sent to
 * chosen customers (push_promo_targets) shows to them only.
 */
function pushPromosActive(int $customerId, int $days = 7, int $limit = 3): array
{
    $st = getDBConnection()->prepare("SELECT p.id, p.title, p.body, p.url, p.created_at FROM push_promos p
                                      WHERE p.created_at >= NOW() - INTERVAL ? DAY
                                        AND (NOT EXISTS (SELECT 1 FROM push_promo_targets t WHERE t.promo_id = p.id)
                                             OR EXISTS (SELECT 1 FROM push_promo_targets t WHERE t.promo_id = p.id AND t.customer_id = ?))
                                      ORDER BY p.id DESC LIMIT " . max(1, $limit));
    $st->execute([$days, $customerId]);
    return array_map(fn($p) => ['id' => (int) $p['id'], 'title' => $p['title'], 'body' => $p['body'], 'url' => (string) $p['url'],
                                'date' => t('promo_of', ['date' => date('d/m/Y', strtotime($p['created_at']))])], $st->fetchAll());
}

/**
 * The last notifications sent, newest first (Admin > Clienti online), with 'targets':
 * the names of the chosen customers ('' = everybody who accepts promotions).
 */
function pushPromosRecent(int $limit = 5): array
{
    return getDBConnection()->query("
        SELECT p.*, (SELECT GROUP_CONCAT(TRIM(CONCAT(c.first_name, ' ', c.last_name)) ORDER BY c.first_name SEPARATOR ', ')
                     FROM push_promo_targets t JOIN online_customers c ON c.id = t.customer_id WHERE t.promo_id = p.id) AS targets
        FROM push_promos p ORDER BY p.id DESC LIMIT " . max(1, $limit)
    )->fetchAll();
}
