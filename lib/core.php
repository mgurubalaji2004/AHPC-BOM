<?php
/*
 * Core helpers: database, install/migrate, sessions, auth, flash messages,
 * URL building, template rendering and formatting filters.
 */

require_once __DIR__ . '/../config.php';

date_default_timezone_set(APP_TIMEZONE);

const BASE_DIR = __DIR__ . '/..';
const SCHEMA_VERSION = '3';
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
    if ($v === SCHEMA_VERSION) {
        return;
    }
    // only one request migrates; the others wait for it
    $pdo->query("SELECT GET_LOCK('ahpc_bom_migrate', 600)")->fetchColumn();
    try {
        $v = $pdo->query("SELECT v FROM app_meta WHERE k='schema_version'")->fetchColumn();
    } catch (PDOException $e) {
        $v = false;
    }
    if ($v !== SCHEMA_VERSION) {
        @set_time_limit(600);
        $first_time = !(bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        run_schema($pdo);
        migrate($pdo, $first_time ? 0 : (int)$v);
        $pdo->prepare("REPLACE INTO app_meta (k, v) VALUES ('schema_version', ?)")->execute([SCHEMA_VERSION]);
    }
    $pdo->query("SELECT RELEASE_LOCK('ahpc_bom_migrate')");
}

function column_exists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (bool)$st->fetch();
}

function add_column(PDO $pdo, string $table, string $col, string $decl): void
{
    if (!column_exists($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $decl");
    }
}

/** Bring an older database up to date. $from = 0 for a brand-new database. */
function migrate(PDO $pdo, int $from): void
{
    add_column($pdo, 'users', 'username', 'VARCHAR(60) NULL AFTER name');
    add_column($pdo, 'boms', 'source', 'VARCHAR(20) NULL');
    add_column($pdo, 'boms', 'source_file', 'VARCHAR(255) NULL');
    add_column($pdo, 'boms', 'fingerprint', 'CHAR(32) NULL');
    $pdo->exec("UPDATE users SET username = LOWER(SUBSTRING_INDEX(email, '@', 1)) WHERE (username IS NULL OR username = '') AND email LIKE '%@%'");
    if ($from < 2) {
        ensure_team($pdo);   // adds the new team members (existing logins are left as they are)
        // Version 2: customers and components come from the uploaded BOM library (data/bom_library)
        load_bom_library($pdo, true);
    }
    add_column($pdo, 'boms', 'quote_number', 'VARCHAR(40) NULL');
    add_column($pdo, 'users', 'theme', 'VARCHAR(20) NULL');
    if ($from < 3) {
        // Version 3: work tracker (imported from data/work_tracker) + a quotation number on every project BOM
        require_once __DIR__ . '/tracker.php';
        if (!(int)$pdo->query('SELECT COUNT(*) FROM work_items')->fetchColumn() && is_dir(BASE_DIR . '/data/work_tracker')) {
            import_work_tracker(BASE_DIR . '/data/work_tracker');
        }
        backfill_bom_tracking();
    }
}

/**
 * Replace the uploaded BOM library: removes the earlier Excel-imported BOMs that have no quotation,
 * clears customers (when $clear_customers) and components, imports every file of data/bom_library and
 * rebuilds the component database from all BOM lines. Returns [boms created, files read, skipped files].
 */
function load_bom_library(PDO $pdo, bool $clear_customers): array
{
    require_once __DIR__ . '/workflow.php';
    require_once __DIR__ . '/bom_import.php';
    $old = $pdo->query("SELECT b.id FROM boms b WHERE (b.created_by = 'Excel import' OR b.source = 'UPLOAD')
                        AND NOT EXISTS (SELECT 1 FROM quotations q WHERE q.bom_id = b.id)")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_chunk($old, 200) as $ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM bom_items WHERE bom_id IN ($in)");
        $pdo->exec("DELETE FROM remarks WHERE kind='bom' AND item_id IN ($in)");
        $pdo->exec("UPDATE boms SET parent_bom_id = NULL WHERE parent_bom_id IN ($in)");
        $pdo->exec("DELETE FROM boms WHERE id IN ($in)");
    }
    if ($clear_customers) {
        foreach (['requirements', 'boms', 'quotations'] as $t) {
            $pdo->exec("UPDATE $t SET customer_id = NULL");
        }
        $pdo->exec('DELETE FROM customers');
    }
    $result = [0, 0, []];
    if (is_dir(BASE_DIR . '/data/bom_library')) {
        $result = import_bom_folder(BASE_DIR . '/data/bom_library');
    }
    rebuild_components();
    return $result;
}

/** Create the logins listed in TEAM that do not exist yet. Existing users are never changed. */
function ensure_team(PDO $pdo): void
{
    foreach (TEAM as [$name, $username, $email, $role, $default_pw]) {
        $st = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? OR LOWER(username)=?');
        $st->execute([strtolower($email), strtolower($username)]);
        $id = $st->fetchColumn();
        if ($id === false) {
            $pdo->prepare('INSERT INTO users (name, username, role, email, password_hash, active) VALUES (?,?,?,?,?,1)')
                ->execute([$name, $username, $role, $email, password_hash($default_pw, PASSWORD_DEFAULT)]);
        } else {
            $st = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
            $st->execute([$id]);
            $h = (string)$st->fetchColumn();
            if ($h === '' || !is_php_hash($h)) {   // e.g. imported from the old Python version
                $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($default_pw, PASSWORD_DEFAULT), $id]);
            }
        }
    }
}

/** Set every TEAM member back to the starting password listed in config.php (also re-activates the account). */
function reset_team_passwords(PDO $pdo): void
{
    ensure_team($pdo);
    foreach (TEAM as [$name, $username, $email, $role, $default_pw]) {
        $pdo->prepare('UPDATE users SET password_hash=?, active=1 WHERE LOWER(email)=? OR LOWER(username)=?')
            ->execute([password_hash($default_pw, PASSWORD_DEFAULT), strtolower($email), strtolower($username)]);
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

// key => [name, swatch colour 1, swatch colour 2]
const THEMES = [
    'allway' => ['Allway (navy & orange)', '#13233f', '#e2602a'],
    'indigo' => ['Indigo (light)', '#ffffff', '#5b4ce6'],
    'ocean' => ['Ocean', '#083344', '#22b3c9'],
    'forest' => ['Forest', '#0f2a19', '#49b26a'],
    'rose' => ['Rose', '#3a0d22', '#f05d93'],
    'dark' => ['Dark', '#0e131c', '#f0763f'],
];

function current_theme(): string
{
    $t = me()['theme'] ?? ($_COOKIE['ahpc_theme'] ?? 'allway');
    return isset(THEMES[$t]) ? $t : 'allway';
}

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
