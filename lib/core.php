<?php
/*
 * Core helpers: database, install/migrate, sessions, auth, flash messages,
 * URL building, template rendering and formatting filters.
 */

require_once __DIR__ . '/../config.php';

date_default_timezone_set(APP_TIMEZONE);

const BASE_DIR = __DIR__ . '/..';
const SCHEMA_VERSION = '1';
const ROLES = ['Engineer', 'Admin'];
const KIND_TABLE = ['req' => 'requirements', 'bom' => 'boms', 'quote' => 'quotations'];
const KIND_LABEL = ['req' => 'Requirement', 'bom' => 'BOM', 'quote' => 'Quotation'];

class HttpError extends Exception {}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function db_connect(bool $with_db = true): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4' . ($with_db ? ';dbname=' . DB_NAME : '');
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = db_connect();
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1049) {   // 1049 = unknown database
                throw $e;
            }
            db_connect(false)->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = db_connect();
        }
        init_db($pdo);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function insert(string $sql, array $params = []): int
{
    q($sql, $params);
    return (int)db()->lastInsertId();
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function today_str(): string
{
    return date('Y-m-d');
}

/** '' / invalid -> NULL, otherwise 'YYYY-MM-DD' (date inputs). */
function date_or_null($v): ?string
{
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

// ---------------------------------------------------------------------------
// Install / migrate / seed
// ---------------------------------------------------------------------------

function run_schema(PDO $pdo): void
{
    $sql = file_get_contents(BASE_DIR . '/sql/schema.sql');
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

function init_db(PDO $pdo): void
{
    try {
        $v = $pdo->query("SELECT v FROM app_meta WHERE k='schema_version'")->fetchColumn();
    } catch (PDOException $e) {
        $v = false;
    }
    $want = SCHEMA_VERSION . ':' . substr(md5(serialize(TEAM)), 0, 10);   // re-run when TEAM is edited
    if ($v === $want) {
        return;
    }
    $first_time = !(bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    run_schema($pdo);
    if ($first_time) {
        seed($pdo);
    }
    ensure_team($pdo);
    $pdo->prepare("REPLACE INTO app_meta (k, v) VALUES ('schema_version', ?)")->execute([$want]);
}

function seed(PDO $pdo): void
{
    $st = $pdo->prepare('INSERT INTO customers (company, contact_person, email, phone) VALUES (?, ?, ?, ?)');
    foreach ([
        ['ABC Technologies Pvt Ltd', 'Arjun Rao', 'arjun@abctech.com', '9876543210'],
        ['XYZ Research Labs', 'Divya Menon', 'divya@xyzlabs.in', '9123456780'],
    ] as $r) {
        $st->execute($r);
    }
    $st = $pdo->prepare('INSERT INTO components (category, manufacturer, part_number, description, supplier, cost, selling_price, stock)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([
        ['CPU', 'AMD', 'EPYC 9554', '64-core Server CPU', 'Local Distributor', 250000, 285000, 6],
        ['CPU', 'Intel', 'Xeon W5-2455X', '12-core Workstation CPU', 'Local Distributor', 90000, 105000, 10],
        ['GPU', 'NVIDIA', 'H200 SXM', '141GB HBM3e AI GPU', 'OEM Partner', 2200000, 2450000, 8],
        ['GPU', 'NVIDIA', 'RTX 6000 Ada', '48GB Workstation GPU', 'OEM Partner', 420000, 470000, 4],
        ['Motherboard', 'Gigabyte', 'MW53-HP0', 'Server Motherboard', 'Local Distributor', 55000, 65000, 12],
        ['RAM', 'Samsung', '64GB DDR5 ECC', 'Server RAM Module', 'Local Distributor', 28000, 32000, 40],
        ['Storage', 'Samsung', 'PM9A3 3.84TB', 'Enterprise NVMe SSD', 'Local Distributor', 48000, 54000, 20],
        ['Networking', 'NVIDIA', 'ConnectX-7 400G', 'InfiniBand NIC', 'OEM Partner', 180000, 205000, 6],
        ['Chassis', 'Supermicro', 'SYS-4029GP', '4U GPU Server Chassis', 'OEM Partner', 150000, 172000, 3],
        ['PSU', 'Delta', '3000W Redundant', 'Server Power Supply', 'Local Distributor', 35000, 40000, 15],
    ] as $r) {
        $st->execute($r);
    }
}

/** Create the logins listed in TEAM. Existing users keep the password they have. */
function ensure_team(PDO $pdo): void
{
    $pdo->exec("DELETE FROM users WHERE email IS NULL OR email = ''");
    foreach (TEAM as [$name, $email, $role, $default_pw]) {
        $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email)=?');
        $st->execute([strtolower($email)]);
        $row = $st->fetch();
        if (!$row) {
            $pdo->prepare('INSERT INTO users (name, role, email, password_hash, active) VALUES (?,?,?,?,1)')
                ->execute([$name, $role, $email, password_hash($default_pw, PASSWORD_DEFAULT)]);
        } else {
            $pdo->prepare('UPDATE users SET name=?, role=? WHERE id=?')->execute([$name, $role, $row['id']]);
            if (!$row['password_hash'] || !is_php_hash($row['password_hash'])) {
                $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                    ->execute([password_hash($default_pw, PASSWORD_DEFAULT), $row['id']]);
            }
        }
    }
}

/** Set every team member back to the starting password listed in TEAM (also re-activates the account). */
function reset_team_passwords(PDO $pdo): void
{
    foreach (TEAM as [$name, $email, $role, $default_pw]) {
        $st = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=?');
        $st->execute([strtolower($email)]);
        $id = $st->fetchColumn();
        $hash = password_hash($default_pw, PASSWORD_DEFAULT);
        if ($id === false) {
            $pdo->prepare('INSERT INTO users (name, role, email, password_hash, active) VALUES (?,?,?,?,1)')
                ->execute([$name, $role, $email, $hash]);
        } else {
            $pdo->prepare('UPDATE users SET name=?, role=?, password_hash=?, active=1 WHERE id=?')
                ->execute([$name, $role, $hash, $id]);
        }
    }
}

function is_php_hash(string $h): bool
{
    return (password_get_info($h)['algo'] ?? null) !== null;
}

function new_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $s;
}

// ---------------------------------------------------------------------------
// Numbering
// ---------------------------------------------------------------------------

function next_number(string $table, string $col, string $prefix): string
{
    $year = date('Y');
    $last = val("SELECT $col FROM $table WHERE $col LIKE ? ORDER BY id DESC LIMIT 1", ["$prefix-$year-%"]);
    $seq = 1;
    if ($last) {
        $tail = substr(strrchr($last, '-'), 1);
        $seq = ctype_digit($tail) ? (int)$tail + 1 : 1;
    }
    return sprintf('%s-%s-%03d', $prefix, $year, $seq);
}

// ---------------------------------------------------------------------------
// Request / session / URLs
// ---------------------------------------------------------------------------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function form(string $name, $default = null)
{
    $v = $_POST[$name] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function arg(string $name, $default = null)
{
    $v = $_GET[$name] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

/** Like Flask's request.form.get(name, type=int): null when missing or not an integer. */
function int_or_null($v): ?int
{
    if ($v === null || is_array($v)) {
        return null;
    }
    $v = trim((string)$v);
    return preg_match('/^-?\d+$/', $v) ? (int)$v : null;
}

/** Relative link to a page of the app, e.g. url('/boms/5', ['q' => 'x']). */
function url(string $path = '/', array $params = []): string
{
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    $qs = http_build_query(['r' => $path] + $params);
    return 'index.php?' . str_replace('%2F', '/', $qs);
}

function base_url(): string
{
    if (APP_BASE_URL !== '') {
        return rtrim(APP_BASE_URL, '/');
    }
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

function abs_url(string $relative): string
{
    $b = base_url();
    return $b === '' ? $relative : $b . '/' . $relative;
}

function asset(string $file): string
{
    return 'static/' . $file;
}

function current_uri(): string
{
    return $_SERVER['REQUEST_URI'] ?? url('/');
}

function safe_next(?string $next): ?string
{
    $next = (string)$next;
    return ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, "\n")) ? $next : null;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 302);
    exit;
}

function redirect_back(?string $fallback = null): never
{
    redirect(safe_next(form('next')) ?? referer_path() ?? $fallback ?? url('/'));
}

function referer_path(): ?string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref === '') {
        return null;
    }
    $p = parse_url($ref);
    if (!empty($p['host']) && isset($_SERVER['HTTP_HOST']) && strcasecmp($p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $_SERVER['HTTP_HOST']) !== 0) {
        return null;
    }
    return safe_next(($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : ''));
}

function abort(int $code = 404): never
{
    throw new HttpError('', $code);
}

function flash(string $msg, string $category = 'success'): void
{
    $_SESSION['_flashes'][] = [$category, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['_csrf'];
}

function check_csrf(): void
{
    if (!is_post()) {
        return;
    }
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        flash('Your session expired - please try again.', 'error');
        redirect(referer_path() ?? url('/'));
    }
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

$CURRENT_USER = null;

/** Re-read the logged-in user from the DB on every request (role changes / deactivation apply at once). */
function load_current_user(): void
{
    global $CURRENT_USER;
    $CURRENT_USER = null;
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) {
        $u = one('SELECT * FROM users WHERE id=? AND active=1', [$uid]);
        if ($u === null) {
            $_SESSION = [];
        } else {
            $CURRENT_USER = $u;
            $_SESSION['user_name'] = $u['name'];
            $_SESSION['role'] = $u['role'];
        }
    }
}

function me(): ?array
{
    global $CURRENT_USER;
    return $CURRENT_USER;
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function current_user_name(): ?string
{
    return $_SESSION['user_name'] ?? null;
}

function login_required(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect(url('/login', ['next' => current_uri()]));
    }
}

function roles_required(string ...$roles): void
{
    login_required();
    $role = current_role();
    if (!in_array($role, $roles, true) && $role !== 'Admin') {
        flash('This action requires role: ' . implode(', ', $roles), 'error');
        redirect(url('/'));
    }
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Render a template inside the page layout. */
function render(string $template, array $vars = [], string $title = 'AHPC BOM System', int $status = 200): never
{
    http_response_code($status);
    $content = render_partial($template, $vars);
    $html = render_partial('layout', ['content' => $content, 'title' => $title, 'flashes' => take_flashes()]);
    echo add_csrf_fields($html);
    exit;
}

function render_partial(string $template, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include BASE_DIR . '/templates/' . $template . '.php';
    return ob_get_clean();
}

/** Put the CSRF token into every POST form of the page. */
function add_csrf_fields(string $html): string
{
    $field = '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    return preg_replace('/(<form\b[^>]*\bmethod="post"[^>]*>)/i', '$1' . $field, $html);
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/** Python round(): halves go to the even number. */
function pyround($x, int $digits = 0): float
{
    return round((float)$x, $digits, PHP_ROUND_HALF_EVEN);
}

/** Indian digit grouping: 514050 -> 5,14,050 (no decimals unless asked). */
function inr($value, int $decimals = 0): string
{
    if (!is_numeric($value ?? 0)) {
        return (string)$value;
    }
    $v = (float)($value ?? 0);
    $s = number_format(abs($v), $decimals, '.', '');
    [$whole, $dec] = array_pad(explode('.', $s, 2), 2, '');
    if (strlen($whole) > 3) {
        $head = substr($whole, 0, -3);
        $tail = substr($whole, -3);
        $parts = [];
        while (strlen($head) > 2) {
            array_unshift($parts, substr($head, -2));
            $head = substr($head, 0, -2);
        }
        if ($head !== '') {
            array_unshift($parts, $head);
        }
        $whole = implode(',', $parts) . ',' . $tail;
    }
    return ($v < 0 ? '-' : '') . $whole . ($dec !== '' ? '.' . $dec : '');
}

/** Python "%g" formatting: 18.00 -> 18, 12.50 -> 12.5 */
function fmt_g($x): string
{
    return sprintf('%g', (float)$x);
}

/** Python float repr of a rounded number: 15 -> 15.0, 12.35 -> 12.35 */
function fmt_round($x, int $digits): string
{
    $s = fmt_g(pyround($x, $digits));
    return str_contains($s, '.') ? $s : $s . '.0';
}

/** HTML-escape text and wrap every search word in <mark>. */
function hl($text, array $words): string
{
    $out = e($text);
    $words = array_unique(array_map('strval', $words));
    usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($words as $w) {
        $out = preg_replace('/(' . preg_quote(e($w), '/') . ')/iu', '<mark>$1</mark>', $out);
    }
    return $out;
}

/** Parse '3', '3.0', '1,50,000' ... into a whole number. */
function to_int($value, int $default = 0, ?int $minimum = null): int
{
    $s = trim(str_replace(',', '', (string)($value ?? '')));
    $n = is_numeric($s) ? (int)pyround($s) : $default;
    if ($minimum !== null) {
        $n = max($minimum, $n);
    }
    return $n;
}

function badge_status_class(string $s): string
{
    return match ($s) {
        'APPROVED' => 'green',
        'QUOTED' => 'blue',
        'REVISION_REQUIRED' => 'red',
        'UNDER_REVIEW' => 'orange',
        default => 'gray',
    };
}
