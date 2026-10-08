<?php
/*
 * Work tracker: one row per job (requirement / BOM / quotation) with S.No., dates, quotation number,
 * customer, requirement, engineer, PO status, status, remarks, follow-up and contact.
 * Rows come from the old "WorkTracker" sheet (import) and are added / updated automatically for every
 * project BOM: each BOM gets the next quotation number, its customer name and engineer name.
 */

require_once __DIR__ . '/core.php';

const STAGES = ['OPEN' => 'In progress', 'DONE' => 'Completed', 'CANCELLED' => 'Cancelled'];

/** '23-02-2026', '16/02/26', '1/10/2026', '2026-02-23' -> '2026-02-23' (null when not a date). */
function parse_any_date($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v) && $v > 30000 && $v < 70000) {        // spreadsheet serial number
        return date('Y-m-d', (int)(($v - 25569) * 86400));
    }
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        $y = $y < 100 ? 2000 + $y : $y;
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** 'theepti' -> Theepthithan, 'saravana' -> Saravana Kumar, 'muthumathan' -> Muthumadhan. Returns [user id|null, name]. */
function match_engineer(string $name): array
{
    static $users = null;
    $users ??= all('SELECT id, name, username FROM users');
    $n = strtolower(trim($name));
    if ($n === '') {
        return [null, ''];
    }
    $key = substr(preg_replace('/[^a-z]/', '', $n), 0, 4);
    foreach ($users as $u) {
        foreach ([strtolower($u['name']), strtolower((string)$u['username'])] as $cand) {
            $c = preg_replace('/[^a-z]/', '', $cand);
            if ($c !== '' && strlen($key) >= 3 && str_starts_with($c, $key)) {
                return [(int)$u['id'], $u['name']];
            }
        }
    }
    // typing mistakes: "Guubalaji", "Thepthithan" (close to a first name), "MADHAN" (end of a name)
    $flat = preg_replace('/[^a-z]/', '', $n);
    foreach ($users as $u) {
        $first = preg_replace('/[^a-z]/', '', strtolower(explode(' ', $u['name'])[0]));
        if ($first !== '' && strlen($flat) >= 5 && (levenshtein($flat, $first) <= 2 || (strlen($flat) >= 6 && str_ends_with($first, $flat)))) {
            return [(int)$u['id'], $u['name']];
        }
    }
    return [null, ucwords(strtolower(trim($name)))];
}

/** Merge spellings of the same non-user name ("Lakhmanan", "Lashmann" -> "Lakshmanan"). */
function tidy_engineer_names(): void
{
    $names = all('SELECT engineer_name n, COUNT(*) c FROM work_items WHERE engineer_id IS NULL AND engineer_name IS NOT NULL
                  AND engineer_name <> "" GROUP BY engineer_name ORDER BY c DESC');
    $keep = [];
    foreach ($names as $r) {
        $low = strtolower($r['n']);
        foreach ($keep as $k) {
            if (levenshtein($low, strtolower($k)) <= 2) {
                q('UPDATE work_items SET engineer_name=? WHERE engineer_name=? AND engineer_id IS NULL', [$k, $r['n']]);
                continue 2;
            }
        }
        $keep[] = $r['n'];
    }
}

function stage_from_status(string $status): string
{
    $s = strtolower($status);
    if (preg_match('/cancel|drop|lost|not required|regret/', $s)) {
        return 'CANCELLED';
    }
    if (preg_match('/sent|complet|closed|po received|order|delivered|done/', $s)) {
        return 'DONE';
    }
    return 'OPEN';
}

function work_insert(array $row): int
{
    $row += ['source' => 'MANUAL', 'created_at' => now_str(), 'updated_at' => now_str()];
    $row['stage'] ??= stage_from_status((string)($row['status'] ?? ''));
    $cols = array_keys($row);
    return insert('INSERT INTO work_items (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                  array_values($row));
}

function work_update(int $id, array $row): void
{
    if (!$row) {
        return;
    }
    $row['updated_at'] = now_str();
    q('UPDATE work_items SET ' . implode(', ', array_map(fn($c) => "$c=?", array_keys($row))) . ' WHERE id=?',
      array_merge(array_values($row), [$id]));
}

// ---------------------------------------------------------------------------
// Import of the old sheet (Zoho HTML export: WORKTRACKER / On Progress / COMPLETED)
// ---------------------------------------------------------------------------

function import_work_tracker(string $dir): int
{
    require_once __DIR__ . '/bom_import.php';
    $n = 0;
    $files = glob($dir . '/*.htm*') ?: [];
    usort($files, fn($a, $b) => [stripos($a, 'WORKTRACKER') === false, stripos($a, 'COMPLETED') !== false]
                                <=> [stripos($b, 'WORKTRACKER') === false, stripos($b, 'COMPLETED') !== false]);
    foreach ($files as $f) {
        foreach (read_zoho_html($f) as $sheet => $grid) {
            $n += import_tracker_grid($grid, basename($f));
        }
    }
    tidy_engineer_names();
    return $n;
}

function import_tracker_grid(array $grid, string $file): int
{
    $cols = null;
    $tender = false;
    $n = 0;
    $get = function (array $cells, string $k) use (&$cols) {
        return isset($cols[$k]) ? ($cells[$cols[$k]] ?? null) : null;
    };
    $txt = fn($v) => $v === null ? null : trim((string)$v);
    foreach ($grid as $cells) {
        // header rows decide which column is which
        $labels = array_map(fn($v) => strtolower(trim((string)$v)), $cells);
        if (in_array('customer name', $labels, true) || in_array('customer', $labels, true) || in_array('tender', $labels, true)) {
            $cols = [];
            $tender = in_array('tender', $labels, true);
            foreach ($labels as $c => $l) {
                $key = match (true) {
                    $l === 's.no.' || $l === 's.no' => 'sno',
                    $l === 'date' => 'entry_date',
                    $l === 'received date' => 'received_date',
                    str_starts_with($l, 'quotation n') => 'quote_number',
                    $l === 'customer name' || $l === 'customer' => 'customer_name',
                    $l === 'company name' => 'company_name',
                    $l === 'region' => 'region',
                    $l === 'requirements' || $l === 'product' => 'requirement',
                    $l === 'quantity' => 'quantity',
                    $l === 'quote sent date' || $l === 'sent date' => 'quote_sent_date',
                    $l === 'assigned engineer' || $l === 'assigned to' => 'engineer',
                    $l === 'po status' => 'po_status',
                    $l === 'start date' => 'start_date',
                    $l === 'status' || $l === 'current status' => 'status',
                    $l === 'remarks' => 'remarks',
                    $l === 'followup' => 'followup',
                    $l === 'contact mail id' => 'contact',
                    $l === 'due date' => 'due_date',
                    default => null,
                };
                if ($key) {
                    $cols[$key] = $c;
                }
            }
            if ($tender) {   // "Tender | due date" block: date, name, -, due date
                $cols = ['entry_date' => 0, 'customer_name' => 1, 'due_date' => $cols['due_date'] ?? 3];
            } elseif (isset($cols['customer_name'], $cols['engineer']) && !isset($cols['status']) && !isset($cols['quote_number'])) {
                $cols['status'] = $cols['customer_name'] + 1;   // "On Progress": unnamed status column after Customer
            }
            continue;
        }
        if ($cols === null) {
            continue;
        }
        $customer = $txt($get($cells, 'customer_name'));
        $quote = $txt($get($cells, 'quote_number'));
        if (($customer === null || $customer === '') && ($quote === null || $quote === '')) {
            continue;
        }
        [$eid, $ename] = match_engineer((string)$txt($get($cells, 'engineer')));
        $status = (string)$txt($get($cells, 'status'));
        $row = array_filter([
            'sno' => is_numeric($get($cells, 'sno')) ? (int)$get($cells, 'sno') : null,
            'entry_date' => parse_any_date($get($cells, 'entry_date')),
            'received_date' => parse_any_date($get($cells, 'received_date')) ?? parse_any_date($get($cells, 'entry_date')),
            'quote_number' => $quote,
            'customer_name' => $customer,
            'company_name' => $txt($get($cells, 'company_name')),
            'region' => $txt($get($cells, 'region')),
            'requirement' => $txt($get($cells, 'requirement')),
            'quantity' => $txt($get($cells, 'quantity')),
            'quote_sent_date' => parse_any_date($get($cells, 'quote_sent_date')),
            'engineer_id' => $eid,
            'engineer_name' => $ename,
            'po_status' => $txt($get($cells, 'po_status')),
            'start_date' => parse_any_date($get($cells, 'start_date')),
            'due_date' => parse_any_date($get($cells, 'due_date')),
            'status' => $tender ? 'Tender' : $status,
            'remarks' => $txt($get($cells, 'remarks')),
            'followup' => $txt($get($cells, 'followup')),
            'contact' => $txt($get($cells, 'contact')),
        ], fn($v) => $v !== null && $v !== '');
        $row['stage'] = str_contains(strtolower($file), 'completed') ? stage_from_status($status ?: 'completed')
                      : (str_contains(strtolower($file), 'progress') ? 'OPEN' : stage_from_status($status));
        if ($row['stage'] === 'OPEN' && $status === '' && (str_contains(strtolower($file), 'completed') || isset($row['quote_sent_date']))) {
            $row['stage'] = 'DONE';
        }
        $row['source'] = 'IMPORT';
        $row['created_by'] = 'Import: ' . pathinfo($file, PATHINFO_FILENAME);
        // the COMPLETED sheet repeats quotations of the main tracker: complete those rows instead of duplicating
        $existing = ($quote !== null && $quote !== '') ? one('SELECT * FROM work_items WHERE quote_number=? ORDER BY id LIMIT 1', [$quote]) : null;
        if ($existing) {
            $upd = [];
            foreach ($row as $k => $v) {
                if (in_array($k, ['source', 'created_by', 'sno'], true)) {
                    continue;
                }
                if ($k === 'stage' || $k === 'status' || $existing[$k] === null || $existing[$k] === '') {
                    $upd[$k] = $v;
                }
            }
            work_update((int)$existing['id'], $upd);
        } else {
            work_insert($row);
            $n++;
        }
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Quotation numbers: one sequence for BOMs, quotations and tracker rows (2026 + 7 digits)
// ---------------------------------------------------------------------------

function max_quote_seq(string $year): int
{
    $best = 1539;   // the supplied format starts at 20260001539
    foreach (['SELECT quote_number FROM quotations WHERE quote_number LIKE ?',
              'SELECT quote_number FROM boms WHERE quote_number LIKE ?',
              'SELECT quote_number FROM work_items WHERE quote_number LIKE ?'] as $sql) {
        foreach (q($sql, ["$year%"])->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (preg_match('/^\d{11}$/', (string)$n)) {
                $best = max($best, (int)substr($n, 4));
            }
        }
    }
    return $best;
}

/** Next free quotation number. Call inside quote_number_lock() when the number is stored right after. */
function next_quote_number(): string
{
    $year = date('Y');
    return $year . sprintf('%07d', max_quote_seq($year) + 1);
}

function with_quote_lock(callable $fn)
{
    val("SELECT GET_LOCK('ahpc_quote_no', 20)");
    try {
        return $fn();
    } finally {
        val("SELECT RELEASE_LOCK('ahpc_quote_no')");
    }
}

// ---------------------------------------------------------------------------
// Automatic tracking of BOMs
// ---------------------------------------------------------------------------

/** [user id, name] of the engineer of a BOM: the assignee, else the person who created it. */
function bom_engineer(array $bom): array
{
    if ($bom['assigned_to'] && ($u = one('SELECT id, name FROM users WHERE id=?', [$bom['assigned_to']]))) {
        return [(int)$u['id'], $u['name']];
    }
    if ($bom['created_by'] && ($u = one('SELECT id, name FROM users WHERE name=? LIMIT 1', [$bom['created_by']]))) {
        return [(int)$u['id'], $u['name']];
    }
    return [null, (string)$bom['created_by']];
}

function customer_name($customer_id): string
{
    return $customer_id ? (string)val('SELECT company FROM customers WHERE id=?', [$customer_id]) : '';
}

/**
 * A new project BOM: give it the next quotation number and add (or link) its tracker row.
 * $from_bom = the BOM it is a revision of (keeps the same number and tracker row).
 */
function track_bom_created(int $bom_id, ?int $from_bom = null): void
{
    $bom = one('SELECT * FROM boms WHERE id=?', [$bom_id]);
    if (!$bom || ($bom['source'] ?? '') === 'UPLOAD') {
        return;
    }
    if ($from_bom && ($prev = one('SELECT quote_number FROM boms WHERE id=?', [$from_bom])) && $prev['quote_number']) {
        q('UPDATE boms SET quote_number=? WHERE id=?', [$prev['quote_number'], $bom_id]);
        q('UPDATE work_items SET bom_id=?, updated_at=? WHERE bom_id=?', [$bom_id, now_str(), $from_bom]);
        return;
    }
    $number = with_quote_lock(function () use ($bom_id) {
        $n = next_quote_number();
        q('UPDATE boms SET quote_number=? WHERE id=?', [$n, $bom_id]);
        return $n;
    });
    [$eid, $ename] = bom_engineer($bom);
    $row = ['quote_number' => $number, 'customer_name' => customer_name($bom['customer_id']), 'requirement' => $bom['title'],
            'engineer_id' => $eid, 'engineer_name' => $ename, 'bom_id' => $bom_id, 'status' => 'BOM created', 'stage' => 'OPEN',
            'start_date' => today_str(), 'due_date' => $bom['due_date']];
    // the requirement's row (no BOM yet) becomes this BOM's row
    $req_row = $bom['requirement_id']
        ? one('SELECT id FROM work_items WHERE requirement_id=? AND bom_id IS NULL ORDER BY id LIMIT 1', [$bom['requirement_id']]) : null;
    if ($req_row) {
        work_update((int)$req_row['id'], $row);
    } else {
        work_insert($row + ['sno' => next_sno(), 'entry_date' => today_str(), 'received_date' => today_str(),
                            'requirement_id' => $bom['requirement_id'], 'source' => 'AUTO', 'created_by' => $bom['created_by']]);
    }
}

function next_sno(): int
{
    return (int)val('SELECT COALESCE(MAX(sno), 0) + 1 FROM work_items');
}

function track_requirement_created(int $req_id): void
{
    $r = one('SELECT * FROM requirements WHERE id=?', [$req_id]);
    if (!$r) {
        return;
    }
    [$eid, $ename] = $r['assigned_to'] ? match_user_id((int)$r['assigned_to']) : [null, ''];
    work_insert(['sno' => next_sno(), 'entry_date' => today_str(), 'received_date' => $r['req_date'] ?: today_str(),
                 'customer_name' => customer_name($r['customer_id']), 'requirement' => trim($r['title'] . ($r['description'] ? ' - ' . $r['description'] : '')),
                 'engineer_id' => $eid, 'engineer_name' => $ename, 'due_date' => $r['due_date'], 'status' => 'Requirement received',
                 'stage' => 'OPEN', 'requirement_id' => $req_id, 'source' => 'AUTO', 'created_by' => current_user_name()]);
}

function match_user_id(int $uid): array
{
    $u = one('SELECT id, name FROM users WHERE id=?', [$uid]);
    return $u ? [(int)$u['id'], $u['name']] : [null, ''];
}

/** Update the tracker row of a BOM (any version of it). */
function track_bom(int $bom_id, array $fields): void
{
    $row = one('SELECT id FROM work_items WHERE bom_id=? ORDER BY id LIMIT 1', [$bom_id]);
    if ($row) {
        work_update((int)$row['id'], $fields);
    }
}

/** Keep engineer / customer / title / due date of a BOM's tracker row in step with the BOM. */
function track_bom_refresh(int $bom_id): void
{
    $bom = one('SELECT * FROM boms WHERE id=?', [$bom_id]);
    if (!$bom) {
        return;
    }
    [$eid, $ename] = bom_engineer($bom);
    track_bom($bom_id, ['engineer_id' => $eid, 'engineer_name' => $ename, 'customer_name' => customer_name($bom['customer_id']),
                        'due_date' => $bom['due_date']]);
}

/** After an assignment on the dashboard / thread. */
function track_assignment(string $kind, int $item_id): void
{
    if ($kind === 'bom') {
        track_bom_refresh($item_id);
    } elseif ($kind === 'req') {
        $r = one('SELECT assigned_to, due_date FROM requirements WHERE id=?', [$item_id]);
        [$eid, $ename] = $r && $r['assigned_to'] ? match_user_id((int)$r['assigned_to']) : [null, ''];
        q('UPDATE work_items SET engineer_id=?, engineer_name=?, due_date=?, updated_at=? WHERE requirement_id=? AND bom_id IS NULL',
          [$eid, $ename, $r['due_date'] ?? null, now_str(), $item_id]);
    } elseif ($kind === 'quote') {
        $qt = one('SELECT assigned_to, bom_id FROM quotations WHERE id=?', [$item_id]);
        if ($qt && $qt['assigned_to'] && $qt['bom_id']) {
            [$eid, $ename] = match_user_id((int)$qt['assigned_to']);
            track_bom((int)$qt['bom_id'], ['engineer_id' => $eid, 'engineer_name' => $ename]);
        }
    }
}

/** Give quotation numbers / tracker rows to project BOMs made before version 3. */
function backfill_bom_tracking(): void
{
    $boms = all("SELECT * FROM boms b WHERE COALESCE(b.source,'') <> 'UPLOAD' AND " . LATEST_BOM_SQL . ' ORDER BY b.id');
    foreach ($boms as $b) {
        $versions = q('SELECT id FROM boms WHERE bom_number=?', [$b['bom_number']])->fetchAll(PDO::FETCH_COLUMN);
        $in = implode(',', array_map('intval', $versions));
        $quote = one("SELECT id, quote_number FROM quotations WHERE bom_id IN ($in) ORDER BY id DESC LIMIT 1");
        $number = $quote && preg_match('/^\d{11}$/', (string)$quote['quote_number']) ? $quote['quote_number'] : null;
        if (!$b['quote_number']) {
            $number ??= with_quote_lock(fn() => next_quote_number());
            q("UPDATE boms SET quote_number=? WHERE id IN ($in)", [$number]);
        } else {
            $number = $b['quote_number'];
        }
        if (!val("SELECT 1 FROM work_items WHERE bom_id IN ($in)")) {
            [$eid, $ename] = bom_engineer($b);
            $status = match ($b['status']) {
                'QUOTED' => 'Quote generated', 'APPROVED' => 'BOM approved', 'UNDER_REVIEW' => 'BOM under review',
                'REVISION_REQUIRED' => 'Revision required', default => 'BOM created',
            };
            $sent = $quote ? one('SELECT status, quote_date FROM quotations WHERE id=?', [$quote['id']]) : null;
            if ($sent && $sent['status'] === 'SENT') {
                $status = 'Quote sent';
            }
            work_insert(['sno' => next_sno(), 'entry_date' => substr((string)$b['created_at'], 0, 10) ?: today_str(),
                         'received_date' => substr((string)$b['created_at'], 0, 10) ?: today_str(), 'quote_number' => $number,
                         'customer_name' => customer_name($b['customer_id']), 'requirement' => $b['title'], 'engineer_id' => $eid,
                         'engineer_name' => $ename, 'bom_id' => $b['id'], 'quote_id' => $quote['id'] ?? null,
                         'requirement_id' => $b['requirement_id'], 'status' => $status, 'stage' => stage_from_status($status),
                         'quote_sent_date' => $sent && $sent['status'] === 'SENT' ? $sent['quote_date'] : null,
                         'source' => 'AUTO', 'created_by' => $b['created_by']]);
        }
    }
}

const LATEST_BOM_SQL = 'NOT EXISTS (SELECT 1 FROM boms n WHERE n.bom_number=b.bom_number AND n.version>b.version)';
