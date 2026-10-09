<?php
/*
 * TEMPLATE — app/helpers/ApiV2.php  (from the api-v2-upgrade skill)
 *
 * ADAPT before use (search for "ADAPT"):
 *   - namespace / Database / Setting classes to the site's own (here: App\Core\Database, App\Models\Setting
 *     with fetchOne/fetchAll/fetchColumn/execute(=rowCount), Setting::get/set)
 *   - api key table name in writeLog()  (here: api_keys.api_key → user_id)
 *   - webhook secret table/model in ensureSecret()/regenerateSecret()  (here: api_webhooks + App\Models\ApiWebhook)
 *   - validPhone() rule for the country
 *   - APP_URL constant used by webhookUrlProblem()
 */
declare(strict_types=1);

namespace App\Helpers;

use App\Core\Database;
use App\Models\Setting;

/**
 * ApiV2 — shared plumbing for API version 2 (/api/v2/...).
 *
 * v1 (/api/...) does not use this class and keeps working exactly as before.
 *
 *  - One response shape:   {"ok":true,"data":…,"meta":{…}}  /  {"ok":false,"error":{"code","message",…},"meta":{…}}
 *  - Request id on every reply (X-Request-Id header + meta.request_id)
 *  - Idempotency keys scoped to the account, reserved BEFORE any money moves and
 *    finished inside the same database transaction as the charge (never charged twice)
 *  - Per-order details: client_reference + webhook_url (table api_v2_order_meta)
 *  - Webhook secret that exists from the moment the API key exists
 */
class ApiV2
{
    public const SCHEMA_FLAG = 'api_v2_schema';
    public const SCHEMA_VER  = '2';

    private static ?string $requestId = null;

    // ── Request log (Admin → API Request Logs) ──
    private static ?int $logUserId = null;
    private static bool $logged    = false;

    /** Idempotency key reserved by this request and not finished yet: [db, userId, key]. */
    private static ?array $pendingIdem = null;

    // ── Response helpers ─────────────────────────────────────

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = 'req_' . bin2hex(random_bytes(10));
            if (!headers_sent()) header('X-Request-Id: ' . self::$requestId);
            // A crash (no JSON reply) is still recorded so support can see it
            register_shutdown_function(static function (): void {
                if (self::$logged) return;
                $err = error_get_last();
                $msg = $err ? ($err['message'] . ' in ' . basename((string)$err['file']) . ':' . $err['line']) : 'No reply was sent.';
                self::writeLog((int)(http_response_code() ?: 500), null, '', 'SERVER_CRASH: ' . $msg);
            });
        }
        return self::$requestId;
    }

    /** @return never */
    public static function ok(mixed $data, int $http = 200, array $meta = []): void
    {
        self::send($http, ['ok' => true, 'data' => $data, 'meta' => ['request_id' => self::requestId(), 'api_version' => '2'] + $meta]);
    }

    /** @return never */
    public static function fail(string $code, string $message, int $http = 400, array $extra = []): void
    {
        self::send($http, ['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra,
                           'meta' => ['request_id' => self::requestId(), 'api_version' => '2']]);
    }

    /** Build (not send) an error body — used for stored idempotent replies and bulk items. */
    public static function errorBody(string $code, string $message, array $extra = []): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra,
                'meta' => ['request_id' => self::requestId(), 'api_version' => '2']];
    }

    public static function okBody(mixed $data, array $meta = []): array
    {
        return ['ok' => true, 'data' => $data, 'meta' => ['request_id' => self::requestId(), 'api_version' => '2'] + $meta];
    }

    /** @return never */
    public static function setUser(int $userId): void
    {
        self::$logUserId = $userId;
    }

    public static function send(int $http, array $body): void
    {
        self::requestId();
        // An error reply before anything was charged frees the idempotency key again
        if (self::$pendingIdem && empty($body['ok'])) {
            [$pdb, $puid, $pkey] = self::$pendingIdem;
            self::$pendingIdem = null;
            self::idemRelease($pdb, $puid, $pkey);
        }
        if (!headers_sent()) {
            http_response_code($http);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
            header('X-Api-Version: 2');
        }
        $out = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::writeLog($http, $body, $out);
        echo $out;
        exit;
    }

    // ── Request log ──────────────────────────────────────────
    public const LOG_SETTING      = 'api_request_log_enabled';
    public const LOG_KEEP_SETTING = 'api_request_log_keep_days';
    private const LOG_MAX_BODY    = 60000;

    public static function logEnabled(): bool
    {
        try { return (string)Setting::get(self::LOG_SETTING, '1') !== '0'; } catch (\Throwable) { return true; }
    }

    public static function ensureLogTable(Database $db): void
    {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS api_request_logs (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                request_id      VARCHAR(40)  NOT NULL,
                user_id         INT UNSIGNED NULL,
                api_key_hint    VARCHAR(40)  NULL,
                method          VARCHAR(10)  NOT NULL,
                path            VARCHAR(255) NOT NULL,
                query_string    VARCHAR(1000) NULL,
                ip              VARCHAR(45)  NULL,
                ip_chain        VARCHAR(255) NULL,
                user_agent      VARCHAR(500) NULL,
                request_headers TEXT         NULL,
                request_body    MEDIUMTEXT   NULL,
                http_status     SMALLINT     NOT NULL,
                ok              TINYINT(1)   NOT NULL DEFAULT 0,
                error_code      VARCHAR(60)  NULL,
                error_message   VARCHAR(500) NULL,
                response_body   MEDIUMTEXT   NULL,
                reference       VARCHAR(80)  NULL,
                idempotency_key VARCHAR(100) NULL,
                duration_ms     INT UNSIGNED NULL,
                created_at      DATETIME     NOT NULL,
                UNIQUE KEY uq_request (request_id),
                KEY idx_user_time (user_id, created_at),
                KEY idx_time (created_at),
                KEY idx_reference (reference)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** Record this request + the exact reply. Never breaks the API (all errors swallowed). */
    private static function writeLog(int $http, ?array $body, string $out, ?string $note = null): void
    {
        if (self::$logged) return;
        self::$logged = true;
        try {
            if (!self::logEnabled()) return;
            $db = Database::getInstance();

            $key  = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
            $hint = $key === '' ? null : (strlen($key) > 12 ? substr($key, 0, 6) . '…' . substr($key, -4) : '…' . substr($key, -3));
            $uid  = self::$logUserId;
            if ($uid === null && $key !== '') {
                try {
                    // ADAPT: the site's API key table
                    $r = $db->fetchOne('SELECT user_id FROM api_keys WHERE api_key = ? LIMIT 1', [$key]);
                    if ($r) $uid = (int)$r['user_id'];
                } catch (\Throwable) {}
            }

            $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
            $chain = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            $headers = [];
            foreach ($_SERVER as $k => $v) {
                if (!str_starts_with($k, 'HTTP_') || in_array($k, ['HTTP_COOKIE', 'HTTP_AUTHORIZATION'], true)) continue;
                $name = str_replace('_', '-', substr($k, 5));
                $headers[$name] = $k === 'HTTP_X_API_KEY' ? (string)$hint : mb_substr((string)$v, 0, 300);
            }
            if (!empty($_SERVER['CONTENT_TYPE'])) $headers['CONTENT-TYPE'] = (string)$_SERVER['CONTENT_TYPE'];
            ksort($headers);

            $raw  = (string)@file_get_contents('php://input');
            // Never store secrets in the log
            $masked = json_decode($raw, true);
            if (is_array($masked)) {
                array_walk_recursive($masked, static function (&$v, $k): void {
                    if (is_string($k) && preg_match('/pass|secret|token|pin|api_?key|otp/i', $k)) $v = '***';
                });
                $raw = (string)json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $ref  = $data['order']['reference'] ?? $data['registration']['reference'] ?? $data['transfer']['reference']
                 ?? $data['reference'] ?? $data['batch_id'] ?? null;
            $idem = null;
            $in   = json_decode($raw, true);
            if (is_array($in) && isset($in['idempotency_key'])) $idem = mb_substr((string)$in['idempotency_key'], 0, 100);
            $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
            $start = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

            $row = [
                self::requestId(), $uid, $hint,
                substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 10),
                mb_substr((string)(strtok($uri, '?') ?: $uri), 0, 255),
                mb_substr((string)($_SERVER['QUERY_STRING'] ?? ''), 0, 1000) ?: null,
                mb_substr($ip, 0, 45) ?: null, mb_substr($chain, 0, 255) ?: null,
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
                json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $raw === '' ? null : mb_strcut($raw, 0, self::LOG_MAX_BODY),
                $http, !empty($body['ok']) ? 1 : 0,
                $body['error']['code'] ?? ($note ? 'SERVER_CRASH' : null),
                mb_substr((string)($body['error']['message'] ?? $note ?? ''), 0, 500) ?: null,
                $out === '' ? null : mb_strcut($out, 0, self::LOG_MAX_BODY),
                is_string($ref) ? mb_substr($ref, 0, 80) : null, $idem,
                (int)max(0, round((microtime(true) - $start) * 1000)),
            ];
            $sql = 'INSERT INTO api_request_logs (request_id, user_id, api_key_hint, method, path, query_string, ip, ip_chain, user_agent,
                        request_headers, request_body, http_status, ok, error_code, error_message, response_body, reference, idempotency_key,
                        duration_ms, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
            try {
                $db->execute($sql, $row);
            } catch (\Throwable $e) {
                self::ensureLogTable($db);   // first use: create the table, then try again
                $db->execute($sql, $row);
            }
            if (mt_rand(1, 200) === 1) {
                $days = (int)Setting::get(self::LOG_KEEP_SETTING, '30');
                if ($days > 0) $db->execute('DELETE FROM api_request_logs WHERE created_at < NOW() - INTERVAL ' . $days . ' DAY');
            }
        } catch (\Throwable $e) {
            error_log('[ApiV2 request log] ' . $e->getMessage());
        }
    }

    public static function money(float $v): float
    {
        return round($v, 2);
    }

    public static function iso(?string $dt): ?string
    {
        if ($dt === null || $dt === '' || str_starts_with($dt, '0000')) return null;
        $t = strtotime($dt);
        return $t ? date('c', $t) : null;
    }

    /** JSON body. Invalid JSON is refused instead of silently treated as empty. */
    public static function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        if (trim($raw) === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::fail('INVALID_JSON', 'The request body must be valid JSON.', 400);
        }
        return $data;
    }

    public static function boolParam(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }

    // ADAPT: phone rule for the site's country (Ghana: 0 + 9 digits)
    public static function validPhone(string $p): bool
    {
        return (bool)preg_match('/^0[0-9]{9}$/', $p);
    }

    /** client_reference: up to 64 of A–Z a–z 0–9 _ - (safe in URLs). '' = not sent. */
    public static function cleanClientRef(mixed $v): ?string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') return '';
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v) ? $v : null;
    }

    /** Pagination from ?page=&limit= (max 100). */
    public static function paging(): array
    {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 25)));
        return [$page, $limit, ($page - 1) * $limit];
    }

    public static function pageMeta(int $total, int $page, int $limit): array
    {
        $pages = (int)ceil($total / max(1, $limit));
        return ['pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit,
                                 'total_pages' => $pages, 'has_more' => $page < $pages]];
    }

    /** ?from=YYYY-MM-DD&to=YYYY-MM-DD → [fromDateTime|null, toDateTime|null] */
    public static function dateRange(): array
    {
        $out = [null, null];
        foreach (['from' => 0, 'to' => 1] as $k => $i) {
            $v = trim((string)($_GET[$k] ?? ''));
            if ($v === '') continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) {
                self::fail('VALIDATION_ERROR', "{$k} must be a date like 2026-10-08.", 422, ['fields' => [$k => 'Use YYYY-MM-DD.']]);
            }
            $out[$i] = $i === 0 ? $v . ' 00:00:00' : $v . ' 23:59:59';
        }
        return $out;
    }

    // ── Schema (created on first use, additive only) ─────────

    public static function ensureSchema(Database $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            if ((string)Setting::get(self::SCHEMA_FLAG, '') === self::SCHEMA_VER) return;
        } catch (\Throwable) {}

        $db->execute(
            "CREATE TABLE IF NOT EXISTS api_v2_idempotency (
                user_id      INT UNSIGNED NOT NULL,
                idem_key     VARCHAR(100) NOT NULL,
                request_hash CHAR(64)     NOT NULL,
                status       VARCHAR(12)  NOT NULL DEFAULT 'processing',
                http_code    SMALLINT     NULL,
                response     MEDIUMTEXT   NULL,
                created_at   DATETIME     NOT NULL,
                updated_at   DATETIME     NOT NULL,
                PRIMARY KEY (user_id, idem_key)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->execute(
            "CREATE TABLE IF NOT EXISTS api_v2_order_meta (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id          INT UNSIGNED NOT NULL,
                kind             VARCHAR(20)  NOT NULL,
                order_id         INT UNSIGNED NOT NULL,
                reference        VARCHAR(64)  NOT NULL,
                client_reference VARCHAR(64)  NULL,
                webhook_url      VARCHAR(500) NULL,
                created_at       DATETIME     NOT NULL,
                UNIQUE KEY uq_kind_order (kind, order_id),
                UNIQUE KEY uq_user_cref (user_id, client_reference),
                KEY idx_user_kind (user_id, kind)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->execute(
            "CREATE TABLE IF NOT EXISTS api_v2_counters (
                user_id      INT UNSIGNED NOT NULL,
                bucket       VARCHAR(40)  NOT NULL,
                window_start DATETIME     NOT NULL,
                used         INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, bucket, window_start)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        try {
            $has = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'webhook_logs' AND column_name = 'per_order'"
            );
            if (!$has) {
                $db->execute("ALTER TABLE webhook_logs ADD COLUMN per_order TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $e) {
            error_log('[ApiV2::ensureSchema] webhook_logs: ' . $e->getMessage());
        }
        try { Setting::set(self::SCHEMA_FLAG, self::SCHEMA_VER); } catch (\Throwable) {}
    }

    // ── Per-minute allowance for item-heavy calls (e.g. numbers checked) ─
    /** Adds $n to this minute's count. Returns seconds to wait when over $max, or 0 when allowed. */
    public static function allowPerMinute(Database $db, int $userId, string $bucket, int $n, int $max): int
    {
        $win = date('Y-m-d H:i:00');
        try {
            $used = (int)$db->fetchColumn('SELECT used FROM api_v2_counters WHERE user_id = ? AND bucket = ? AND window_start = ?', [$userId, $bucket, $win]);
            if ($used + $n > $max) return max(1, 60 - (int)date('s'));
            $db->execute(
                'INSERT INTO api_v2_counters (user_id, bucket, window_start, used) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE used = used + VALUES(used)',
                [$userId, $bucket, $win, $n]
            );
            if (mt_rand(1, 50) === 1) $db->execute('DELETE FROM api_v2_counters WHERE window_start < NOW() - INTERVAL 1 HOUR');
        } catch (\Throwable $e) {
            error_log('[ApiV2::allowPerMinute] ' . $e->getMessage());
        }
        return 0;
    }

    // ── Webhook secret (exists as soon as the API key exists) ─

    /** Returns the account's webhook secret, creating it (no URL, inactive) when missing. */
    // ADAPT: the site's webhook settings table / secret generator
    public static function ensureSecret(Database $db, int $userId): string
    {
        $row = $db->fetchOne('SELECT secret FROM api_webhooks WHERE user_id = ? LIMIT 1', [$userId]);
        if ($row && !empty($row['secret'])) return (string)$row['secret'];

        $secret = (new \App\Models\ApiWebhook())->generateSecret();
        if ($row) {
            $db->execute('UPDATE api_webhooks SET secret = ? WHERE user_id = ?', [$secret, $userId]);
        } else {
            // url '' + inactive: nothing is sent to a saved URL until the client saves one
            $db->execute(
                "INSERT IGNORE INTO api_webhooks (user_id, url, secret, status) VALUES (?, '', ?, 'inactive')",
                [$userId, $secret]
            );
            $row = $db->fetchOne('SELECT secret FROM api_webhooks WHERE user_id = ? LIMIT 1', [$userId]);
            if ($row && !empty($row['secret'])) return (string)$row['secret'];
        }
        return $secret;
    }

    public static function regenerateSecret(Database $db, int $userId): string
    {
        self::ensureSecret($db, $userId);
        $secret = (new \App\Models\ApiWebhook())->generateSecret();
        $db->execute('UPDATE api_webhooks SET secret = ? WHERE user_id = ?', [$secret, $userId]);
        return $secret;
    }

    /**
     * A per-order webhook_url must be public HTTPS. Addresses that point at this server
     * or a private network are refused (stops the site being used to reach them).
     * Returns null when fine, or the reason.
     */
    public static function webhookUrlProblem(string $url): ?string
    {
        if (strlen($url) > 500) return 'webhook_url is too long (max 500 characters).';
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
            return 'webhook_url must be a valid https:// address.';
        }
        $parts = parse_url($url);
        $host  = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === '' || !empty($parts['user']) || !empty($parts['pass'])) return 'webhook_url must be a plain https:// address.';
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'webhook_url cannot point to a local or private address.';
        }
        $self = strtolower((string)parse_url(APP_URL, PHP_URL_HOST));
        if ($host === $self || $host === 'www.' . $self) return 'webhook_url cannot point to this site.';

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (!$ips) return 'webhook_url host could not be found.';
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'webhook_url cannot point to a local or private address.';
            }
        }
        return null;
    }

    // ── Idempotency (scoped to the account) ──────────────────

    /** Validates the key. '' = no key sent. Fails the request on a bad key. */
    public static function idemKey(mixed $v): string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') return '';
        if (!preg_match('/^[A-Za-z0-9_\-:.]{1,100}$/', $v)) {
            self::fail('VALIDATION_ERROR', 'idempotency_key may only use letters, numbers and _ - : . (max 100).', 422,
                ['fields' => ['idempotency_key' => 'Invalid characters or too long.']]);
        }
        return $v;
    }

    /**
     * Reserve a key before doing anything that costs money.
     * - same key + same request already finished → replays the stored reply (exits)
     * - same key + different request              → 409 IDEMPOTENCY_MISMATCH (exits)
     * - same key still being processed            → 409 IDEMPOTENCY_IN_PROGRESS (exits)
     * Returns normally when the caller may go ahead.
     */
    public static function idemBegin(Database $db, int $userId, string $key, string $scope, array $payload): void
    {
        if ($key === '') return;
        unset($payload['idempotency_key'], $payload['dry_run']);
        ksort($payload);
        $hash = hash('sha256', $scope . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $n = $db->execute(
            "INSERT IGNORE INTO api_v2_idempotency (user_id, idem_key, request_hash, status, created_at, updated_at)
             VALUES (?, ?, ?, 'processing', NOW(), NOW())",
            [$userId, $key, $hash]
        );
        if ($n > 0) { self::$pendingIdem = [$db, $userId, $key]; return; }   // reserved by this request

        $row = $db->fetchOne(
            'SELECT *, (updated_at < NOW() - INTERVAL 90 SECOND) AS stale FROM api_v2_idempotency WHERE user_id = ? AND idem_key = ? LIMIT 1',
            [$userId, $key]
        );
        if (!$row) { self::idemBegin($db, $userId, $key, $scope, $payload); return; }   // released meanwhile

        if (!hash_equals((string)$row['request_hash'], $hash)) {
            self::fail('IDEMPOTENCY_MISMATCH', 'This idempotency_key was already used for a different request. Use a new key for a new order.', 409);
        }
        if ($row['status'] === 'done') {
            $body = json_decode((string)$row['response'], true);
            if (is_array($body)) {
                $body['meta'] = ($body['meta'] ?? []) + ['idempotent_replay' => true];
                $body['meta']['request_id'] = self::requestId();
                self::send((int)($row['http_code'] ?: 200), $body);
            }
        }
        // 'processing': a crashed request never charged (the charge and "done" are one transaction),
        // so after 90 s the key may be taken over safely.
        if (!empty($row['stale'])) {
            $taken = $db->execute(
                "UPDATE api_v2_idempotency SET updated_at = NOW() WHERE user_id = ? AND idem_key = ? AND status = 'processing' AND updated_at < NOW() - INTERVAL 90 SECOND",
                [$userId, $key]
            );
            if ($taken > 0) { self::$pendingIdem = [$db, $userId, $key]; return; }
        }
        self::fail('IDEMPOTENCY_IN_PROGRESS', 'A request with this idempotency_key is still being processed. Retry in a few seconds.', 409, ['retry_after' => 3]);
    }

    /** Store the final reply. Call INSIDE the charge transaction so charge + key are saved together. */
    public static function idemFinish(Database $db, int $userId, string $key, int $http, array $body): void
    {
        if ($key === '') return;
        self::$pendingIdem = null;
        $db->execute(
            "UPDATE api_v2_idempotency SET status = 'done', http_code = ?, response = ?, updated_at = NOW() WHERE user_id = ? AND idem_key = ?",
            [$http, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId, $key]
        );
    }

    /** Nothing was charged: free the key so the client can retry with it (e.g. after topping up). */
    public static function idemRelease(Database $db, int $userId, string $key): void
    {
        if ($key === '') return;
        try {
            $db->execute("DELETE FROM api_v2_idempotency WHERE user_id = ? AND idem_key = ? AND status = 'processing'", [$userId, $key]);
        } catch (\Throwable) {}
    }

    // ── Per-order details (client_reference, webhook_url) ────

    public static function clientRefTaken(Database $db, int $userId, string $cref): ?array
    {
        if ($cref === '') return null;
        return $db->fetchOne(
            'SELECT kind, reference FROM api_v2_order_meta WHERE user_id = ? AND client_reference = ? LIMIT 1',
            [$userId, $cref]
        );
    }

    public static function saveMeta(Database $db, int $userId, string $kind, int $orderId, string $reference, string $cref, string $webhookUrl): void
    {
        $db->execute(
            'INSERT INTO api_v2_order_meta (user_id, kind, order_id, reference, client_reference, webhook_url, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$userId, $kind, $orderId, $reference, $cref !== '' ? $cref : null, $webhookUrl !== '' ? $webhookUrl : null]
        );
    }

    /** @return array<int,array> order_id => meta row */
    public static function metaFor(Database $db, string $kind, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$orderIds) return [];
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $out = [];
        try {
            foreach ($db->fetchAll("SELECT * FROM api_v2_order_meta WHERE kind = ? AND order_id IN ($in)", [$kind, ...$orderIds]) as $r) {
                $out[(int)$r['order_id']] = $r;
            }
        } catch (\Throwable) {}
        return $out;
    }

    /** Order id for a reference OR client_reference of this account. */
    public static function findOrderId(Database $db, int $userId, string $kind, string $slug, string $table): ?int
    {
        $row = $db->fetchOne("SELECT id FROM {$table} WHERE reference = ? AND user_id = ? LIMIT 1", [$slug, $userId]);
        if ($row) return (int)$row['id'];
        $m = $db->fetchOne(
            'SELECT order_id FROM api_v2_order_meta WHERE user_id = ? AND kind = ? AND client_reference = ? LIMIT 1',
            [$userId, $kind, $slug]
        );
        return $m ? (int)$m['order_id'] : null;
    }
}
