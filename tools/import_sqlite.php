<?php
/*
 * One-time import of the data from the old Python/SQLite version (ahpc.db) into MySQL.
 *
 *   php tools/import_sqlite.php /path/to/ahpc.db            (into a fresh install)
 *   php tools/import_sqlite.php /path/to/ahpc.db --force    (also when MySQL already has BOMs / quotes)
 *
 * All MySQL tables are emptied first and then filled from the SQLite file (same ids).
 *
 * Passwords: the old version stored scrypt hashes, which PHP cannot check. After the import every
 * team member gets the starting password listed in TEAM (config.php) - the list is printed below.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../lib/core.php';

$file = $argv[1] ?? '';
$force = in_array('--force', $argv, true);
if ($file === '' || !is_file($file)) {
    exit("Usage: php tools/import_sqlite.php /path/to/ahpc.db [--force]\n");
}

// parents first, so ids line up
const TABLES = ['users', 'customers', 'requirements', 'components', 'boms', 'bom_items', 'quotations',
                'orders', 'procurement', 'remarks', 'email_log'];
const DATE_COLS = ['req_date', 'expected_delivery', 'due_date', 'quote_date', 'delivery_date'];
const DATETIME_COLS = ['created_at', 'assigned_at', 'updated_at'];

$src = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$dst = db();

$non_empty = [];
foreach (['requirements', 'boms', 'bom_items', 'quotations', 'remarks'] as $t) {
    if ((int)$dst->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) {
        $non_empty[] = $t;
    }
}
if ($non_empty && !$force) {
    exit('MySQL already has data in: ' . implode(', ', $non_empty) . "\nRe-run with --force to wipe these tables and import.\n");
}

$dst->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (array_reverse(TABLES) as $t) {
    $dst->exec("DELETE FROM $t");
}

$src_tables = $src->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (TABLES as $t) {
    if (!in_array($t, $src_tables, true)) {
        echo str_pad($t, 14) . " not in the SQLite file - skipped\n";
        continue;
    }
    $dst_cols = $dst->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN);
    $rows = $src->query("SELECT * FROM $t ORDER BY id")->fetchAll();
    $n = 0;
    foreach ($rows as $row) {
        $row = array_intersect_key($row, array_flip($dst_cols));
        foreach ($row as $col => $v) {
            if (in_array($col, DATE_COLS, true)) {
                $row[$col] = date_or_null(substr((string)$v, 0, 10));
            } elseif (in_array($col, DATETIME_COLS, true)) {
                $v = substr(str_replace('T', ' ', (string)$v), 0, 19);
                $row[$col] = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) ? $v : null;
            } elseif ($t === 'bom_items' && $col === 'quantity') {
                $row[$col] = max(1, (int)pyround($v ?? 1));
            }
        }
        $cols = array_keys($row);
        $sql = "INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $dst->prepare($sql)->execute(array_values($row));
        $n++;
    }
    echo str_pad($t, 14) . " $n rows\n";
}
$dst->exec('SET FOREIGN_KEY_CHECKS=1');

// scrypt hashes from the Python version cannot be verified by PHP -> starting passwords from TEAM
ensure_team($dst);
$dst->exec("UPDATE users SET password_hash=NULL WHERE password_hash LIKE 'scrypt:%' OR password_hash LIKE 'pbkdf2:%'");
$dst->exec("DELETE FROM app_meta WHERE k='schema_version'");

echo "\nImport finished. Log in with these starting passwords (each person can change it under 'Password'):\n";
foreach (TEAM as [$n, $u, $e, $r, $p]) {
    printf("   %-15s %-14s %-9s %-30s %s\n", $n, $u, $r, $e, $p);
}
