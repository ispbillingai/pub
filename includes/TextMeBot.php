<?php
/**
 * WhatsApp via TextMeBot — same gateway and behaviour as the CRM. Configured in
 * admin Settings (setting 'textmebot': api_key, endpoint, min_gap_seconds).
 *
 * TextMeBot rejects a message sent too soon after the previous one on the same
 * API key ("1 message per 5 seconds"), and that message is lost. send() therefore
 * spaces ALL sends app-wide: the last send time is kept in settings (shared by
 * every request) and the remainder of min_gap_seconds is waited out before
 * calling the API; a rate-limited answer is retried up to twice after another gap.
 */

require_once __DIR__ . '/settings.php';

class TextMeBot
{
    public const DEFAULT_ENDPOINT = 'https://api.textmebot.com/send.php';
    private const RETRIES = 2;
    private const LAST_SEND_KEY = 'textmebot_last_send_at';

    private array $cfg;
    private static int $lastSendAt = 0;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? (array) getSetting('textmebot', []);
        $this->cfg = [
            'api_key'         => trim((string) ($cfg['api_key'] ?? '')),
            'endpoint'        => trim((string) ($cfg['endpoint'] ?? '')) ?: self::DEFAULT_ENDPOINT,
            'min_gap_seconds' => max(0, (int) ($cfg['min_gap_seconds'] ?? 8)),
        ];
    }

    public function enabled(): bool
    {
        return $this->cfg['api_key'] !== '';
    }

    /**
     * Phone to the international form TextMeBot wants (+393331234567).
     * Spaces/dashes are dropped, 00 becomes +, and a number with no country
     * code gets $defaultCountry (Italy).
     */
    public static function normalizePhone(string $phone, string $defaultCountry = '+39'): string
    {
        $p = preg_replace('/[^\d+]/', '', trim($phone));
        if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
        if ($p !== '' && $p[0] !== '+') $p = $defaultCountry . ltrim($p, '0');
        return $p;
    }

    /**
     * Send a WhatsApp text (optionally with an image by public URL).
     * @return array{ok:bool, http:int, body:string|false, error:string}
     */
    public function send(string $phone, string $text, ?string $mediaUrl = null): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'not_configured'];
        }
        $phone = self::normalizePhone($phone);
        if (!preg_match('/^\+\d{8,15}$/', $phone)) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'bad_phone'];
        }

        $gap = $this->cfg['min_gap_seconds'];
        $res = [];
        for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
            $this->waitForSlot($gap);
            $res = $this->callApi($phone, $text, $mediaUrl);
            $this->recordSend();
            if ($res['ok'] || !self::looksRateLimited($res)) {
                return $res;
            }
            if ($attempt < self::RETRIES) {
                sleep($gap); // rate-limited despite the gap: back off and retry
            }
        }
        return $res;
    }

    /** Wait until $gap seconds have passed since the previous send (any request). */
    private function waitForSlot(int $gap): void
    {
        if ($gap <= 0) return;
        $last = max(self::$lastSendAt, (int) getSetting(self::LAST_SEND_KEY, 0));
        if ($last > 0 && ($wait = $last + $gap - time()) > 0) {
            sleep(min($wait, $gap));
        }
    }

    private function recordSend(): void
    {
        self::$lastSendAt = time();
        setSetting(self::LAST_SEND_KEY, self::$lastSendAt);
    }

    /** TextMeBot's "too fast" answer (HTTP 403 "…limit of 1 messages per 5 seconds…"). */
    private static function looksRateLimited(array $res): bool
    {
        $body = strtolower((string) ($res['body'] ?? ''));
        $http = (int) ($res['http'] ?? 0);
        return $http === 429 || $http === 403
            || str_contains($body, 'limit of') || str_contains($body, 'per 5 seconds')
            || str_contains($body, 'wait') || str_contains($body, 'too many');
    }

    private function callApi(string $phone, string $text, ?string $mediaUrl): array
    {
        $params = ['recipient' => $phone, 'apikey' => $this->cfg['api_key'], 'text' => $text];
        if ($mediaUrl !== null && $mediaUrl !== '') {
            $params['file'] = $mediaUrl;
        }
        $ch = curl_init($this->cfg['endpoint'] . '?' . http_build_query($params));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'ok'    => $body !== false && $http === 200 && stripos((string) $body, 'success') !== false,
            'http'  => $http,
            'body'  => $body,
            'error' => $err,
        ];
    }

    /** Short human reason for a failed send, for the admin screen. */
    public static function failureReason(array $res): string
    {
        if (($res['error'] ?? '') !== '' && ($res['http'] ?? 0) === 0) return (string) $res['error'];
        $body = trim(strip_tags((string) ($res['body'] ?? '')));
        return 'HTTP ' . (int) ($res['http'] ?? 0) . ($body !== '' ? ' — ' . mb_substr($body, 0, 200) : '');
    }
}
