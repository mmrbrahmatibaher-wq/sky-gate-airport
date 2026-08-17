<?php
declare(strict_types=1);

// Security headers (safe defaults for dashboard + API)
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: https: blob:; style-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com; script-src 'self' https://unpkg.com https://cdnjs.cloudflare.com; connect-src 'self' https://fr24api.flightradar24.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cross-Origin-Opener-Policy: same-origin');
}


if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// API scripts set JSON themselves; do not force Content-Type here (breaks HTML pages).
// No Access-Control-Allow-Origin: * (credentialed same-origin app).


function db_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/database.php';
    }
    return $cfg;
}

function db_driver(): string
{
    $d = strtolower((string)(db_config()['driver'] ?? 'mysql'));
    return $d === 'sqlite' ? 'sqlite' : 'mysql';
}

function is_sqlite(): bool
{
    return db_driver() === 'sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = db_config();
    if (db_driver() === 'sqlite') {
        $path = (string)($c['sqlite_path'] ?? (__DIR__ . '/../data/skygate_atl.sqlite'));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], $c['port'], $c['dbname'], $c['charset']
        );
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** SQL fragment: current timestamp (MySQL NOW() / SQLite datetime('now')) */
function sql_now(): string
{
    return is_sqlite() ? "datetime('now')" : 'NOW()';
}

/** SQL fragment: current date */
function sql_curdate(): string
{
    return is_sqlite() ? "date('now')" : 'CURDATE()';
}

/** datetime expr minus N minutes */
function sql_dt_minus(string $expr, int $minutes): string
{
    if (is_sqlite()) {
        return "datetime({$expr}, '-{$minutes} minutes')";
    }
    return "DATE_SUB({$expr}, INTERVAL {$minutes} MINUTE)";
}

/** datetime expr plus N minutes */
function sql_dt_plus(string $expr, int $minutes): string
{
    if (is_sqlite()) {
        return "datetime({$expr}, '+{$minutes} minutes')";
    }
    return "DATE_ADD({$expr}, INTERVAL {$minutes} MINUTE)";
}

/** ORDER BY FIELD(col, a, b, c) compatible with SQLite */
function sql_field_order(string $col, array $values): string
{
    if (!is_sqlite()) {
        $list = implode(',', array_map(static fn($v) => "'" . str_replace("'", "''", (string)$v) . "'", $values));
        return "FIELD({$col},{$list})";
    }
    $parts = [];
    foreach (array_values($values) as $i => $v) {
        $ev = str_replace("'", "''", (string)$v);
        $parts[] = "WHEN '{$ev}' THEN {$i}";
    }
    return 'CASE ' . $col . ' ' . implode(' ', $parts) . ' ELSE 999 END';
}

/** IF(cond, a, b) compatible */
function sql_if(string $cond, string $a, string $b): string
{
    if (is_sqlite()) {
        return "(CASE WHEN ({$cond}) THEN {$a} ELSE {$b} END)";
    }
    return "IF({$cond},{$a},{$b})";
}

/** List table names for setup checks */
function sql_list_tables(PDO $pdo): array
{
    if (is_sqlite()) {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
    }
    return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}

function json_ok($data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function require_login(): array
{
    if (empty($_SESSION['user'])) {
        json_err('Unauthorized', 401);
    }
    return $_SESSION['user'];
}


function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(?array $body = null): void
{
    $token = '';
    if (is_array($body) && isset($body['csrf'])) {
        $token = (string)$body['csrf'];
    } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    if ($sess === '' || $token === '' || !hash_equals($sess, $token)) {
        json_err('Invalid CSRF token', 403);
    }
}

function require_admin(): array
{
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') {
        json_err('Forbidden', 403);
    }
    return $u;
}

const ALL_SECTIONS = [
    'overview', 'flights', 'addflight', 'gates', 'airside', 'global', 'airspace',
    'terminal', 'baggage', 'staff', 'fuel', 'transit', 'safety', 'weather',
];


/** Simple session-based login rate limit (brute-force mitigation). */
function login_rate_limited(): bool
{
    $max = 8;
    $window = 900; // 15 minutes
    $now = time();
    if (empty($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $_SESSION['login_attempts'] = array_values(array_filter(
        $_SESSION['login_attempts'],
        static fn($t) => ($now - (int)$t) < $window
    ));
    return count($_SESSION['login_attempts']) >= $max;
}

function login_attempt_record(): void
{
    if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $_SESSION['login_attempts'][] = time();
}

function login_attempt_clear(): void
{
    $_SESSION['login_attempts'] = [];
}


/** Atlanta (America/New_York) clock helpers — shared across API. */
if (!function_exists('atl_tz')) {
    function atl_tz(): DateTimeZone { return new DateTimeZone('America/New_York'); }
    function atl_now(): DateTime { return new DateTime('now', atl_tz()); }
    function atl_now_ts(): int { return atl_now()->getTimestamp(); }
    function atl_now_str(string $fmt = 'Y-m-d H:i:s'): string { return atl_now()->format($fmt); }
    function atl_parse_ts(?string $naive): int {
        if (!$naive) return 0;
        try { return (new DateTime($naive, atl_tz()))->getTimestamp(); }
        catch (Throwable $e) { $t = strtotime((string)$naive); return $t ?: 0; }
    }
}

