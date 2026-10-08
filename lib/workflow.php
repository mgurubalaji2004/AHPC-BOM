<?php
/*
 * Business logic shared by the pages: assignment, remarks thread, e-mail events,
 * pending list, BOM totals, component rebuild and quotation helpers.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/quote_docx.php';
require_once __DIR__ . '/quote_pdf.php';

// ---------------------------------------------------------------------------
// Users / assignment / remarks / notifications
// ---------------------------------------------------------------------------

function team_users(bool $only_active = true): array
{
    return all($only_active ? 'SELECT * FROM users WHERE active=1 ORDER BY role DESC, name'
                            : 'SELECT * FROM users ORDER BY name');
}

function admin_users(): array
{
    return all("SELECT * FROM users WHERE role='Admin' AND active=1");
}

function user_by_id($uid): ?array
{
    return $uid ? one('SELECT * FROM users WHERE id=?', [$uid]) : null;
}

function check_kind(string $kind): string
{
    if (!isset(KIND_TABLE[$kind])) {
        abort(404);
    }
    return KIND_TABLE[$kind];
}

/** Everything a notification (or the thread panel) needs to know about a requirement / BOM / quotation. */
function item_info(string $kind, int $item_id): array
{
    $table = check_kind($kind);
    $r = one("SELECT * FROM $table WHERE id=?", [$item_id]);
    if ($r === null) {
        abort(404);
    }
    if ($kind === 'req') {
        [$ref, $title, $path] = [$r['req_number'], $r['title'], url("/requirements/$item_id/edit")];
    } elseif ($kind === 'bom') {
        [$ref, $title, $path] = [$r['bom_number'] . ' v' . $r['version'], $r['title'], url("/boms/$item_id")];
    } else {
        [$ref, $title, $path] = [$r['quote_number'], $r['subject'] ?: 'Quotation', url("/quotes/$item_id")];
    }
    $c = $r['customer_id'] ? one('SELECT company FROM customers WHERE id=?', [$r['customer_id']]) : null;
    return ['kind' => $kind, 'id' => $item_id, 'label' => KIND_LABEL[$kind], 'ref' => $ref, 'title' => $title ?? '',
            'path' => $path, 'url' => abs_url($path), 'company' => $c['company'] ?? '',
            'assigned_to' => $r['assigned_to'], 'due_date' => $r['due_date'], 'row' => $r];
}

/** (info, remarks, assignee ...) for the conversation panel. */
function thread_for(string $kind, int $item_id): array
{
    $info = item_info($kind, $item_id);
    return [
        'info' => $info,
        'remarks' => all('SELECT * FROM remarks WHERE kind=? AND item_id=? ORDER BY id DESC', [$kind, $item_id]),
        'assignee' => user_by_id($info['assigned_to']),
        'users' => team_users(),
        'overdue' => $info['due_date'] && $info['due_date'] < today_str(),
    ];
}

function add_remark(string $kind, int $item_id, ?array $user, string $rtype, string $body): void
{
    q('INSERT INTO remarks (kind, item_id, author_id, author_name, author_role, rtype, body, created_at)
       VALUES (?,?,?,?,?,?,?,?)',
      [$kind, $item_id, $user['id'] ?? null, $user['name'] ?? 'System', $user['role'] ?? '', $rtype, $body, now_str()]);
}

/**
 * E-mail everyone concerned with an item. Recipients = assignee + all admins (+ extras), without the person
 * who did the action (unless include_actor). Returns the addresses used.
 */
function notify_event(string $kind, int $item_id, string $event, string $headline, string $message, ?array $actor = null,
                      bool $to_admins = true, bool $to_assignee = true, array $extra_users = [],
                      bool $include_actor = false, array $details = []): array
{
    $info = item_info($kind, $item_id);
    $people = [];
    if ($to_assignee && $info['assigned_to']) {
        $u = user_by_id($info['assigned_to']);
        if ($u && $u['active']) {
            $people[$u['id']] = $u;
        }
    }
    if ($to_admins) {
        foreach (admin_users() as $u) {
            $people[$u['id']] = $u;
        }
    }
    foreach ($extra_users as $u) {
        if ($u && $u['active']) {
            $people[$u['id']] = $u;
        }
    }
    if ($actor !== null && !$include_actor) {
        unset($people[$actor['id']]);
    }
    if (!$people) {
        return [];
    }
    $assignee = user_by_id($info['assigned_to']);
    $rows = array_merge([
        ['Type', $info['label']], ['Reference', $info['ref']], ['Title', $info['title'] ?: '-'],
        ['Customer', $info['company'] ?: '-'],
        ['Assigned to', $assignee ? $assignee['name'] : 'Not assigned yet'],
        ['Due date', $info['due_date'] ?: '-'],
    ], $details);
    $by = $actor ? "{$actor['name']} ({$actor['role']})" : 'System';
    $subject = "[AHPC] $headline: {$info['ref']} - " . ($info['title'] ?: $info['label']);
    $html = render_partial('email_notify', ['headline' => $headline, 'message' => $message, 'rows' => $rows, 'by' => $by,
                                            'link' => $info['url'], 'when' => date('d M Y, h:i A')]);
    $text = "$headline\n\n$message\n\n" . implode("\n", array_map(fn($r) => "{$r[0]}: {$r[1]}", $rows))
          . "\n\nBy: $by\nOpen: {$info['url']}\n";
    $to = array_map(fn($u) => [$u['name'], $u['email']], array_values($people));
    return send_mail($to, $subject, $html, $text, $actor['email'] ?? null, $event, $info['ref']);
}

/** Assign (or re-assign) an item, record it in the thread and e-mail the people concerned. */
function do_assign(string $kind, int $item_id, int $assignee_id, ?string $due_date, string $note, array $actor): void
{
    $table = check_kind($kind);
    $due_date = date_or_null($due_date);
    $old_id = val("SELECT assigned_to FROM $table WHERE id=?", [$item_id]);
    q("UPDATE $table SET assigned_to=?, assigned_by=?, assigned_at=?, due_date=? WHERE id=?",
      [$assignee_id, $actor['id'], now_str(), $due_date, $item_id]);
    $assignee = user_by_id($assignee_id);
    $line = "Assigned to {$assignee['name']}" . ($due_date ? ", due $due_date" : '');
    if ($note !== '') {
        $line .= ". Instruction: $note";
    }
    add_remark($kind, $item_id, $actor, 'ASSIGNMENT', $line);
    $extra = [$assignee];
    $previous = ($old_id && (int)$old_id !== $assignee_id) ? user_by_id($old_id) : null;
    if ($previous) {
        $extra[] = $previous;
    }
    $msg = "{$actor['name']} has assigned this " . strtolower(KIND_LABEL[$kind]) . ' to '
         . ($assignee['id'] == $actor['id'] ? 'themselves' : $assignee['name']) . '.'
         . ($due_date ? " Due date: $due_date." : '')
         . ($note !== '' ? "\n\nInstruction: $note" : '')
         . ($previous ? "\n\n(Previously assigned to {$previous['name']}.)" : '');
    notify_event($kind, $item_id, 'ASSIGNED', $previous ? 'Re-assigned' : 'Assigned', $msg, $actor,
                 extra_users: $extra, include_actor: true);
}

/** Customer id from a form. Value '__new__' creates a customer from the inline fields. */
function resolve_customer(): ?int
{
    $cid = $_POST['customer_id'] ?? '';
    if ($cid === '__new__') {
        $company = trim((string)form('new_company', ''));
        if ($company === '') {
            return null;
        }
        return insert('INSERT INTO customers (company, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)',
                      [$company, form('new_contact_person'), form('new_email'), form('new_phone'), form('new_address')]);
    }
    return int_or_null($cid);
}

// ---------------------------------------------------------------------------
// Pending list (dashboard)
// ---------------------------------------------------------------------------

const PENDING_STAGE = [
    'DRAFT' => ['BOM being prepared', 'Engineer'],
    'UNDER_REVIEW' => ['BOM under internal review', 'Admin'],
    'APPROVED' => ['BOM approved - quotation not generated', 'Sales'],
    'REVISION_REQUIRED' => ['Revision required', 'Engineer'],
];

const LATEST_BOM = 'NOT EXISTS (SELECT 1 FROM boms n WHERE n.bom_number=b.bom_number AND n.version>b.version)';

/** Everything that has not yet reached a SENT quotation, with who it is pending with. */
function pending_quotes(): array
{
    $rows = [];
    // 1. requirements without any BOM
    foreach (all('SELECT r.*, c.company FROM requirements r LEFT JOIN customers c ON c.id=r.customer_id
                  WHERE NOT EXISTS (SELECT 1 FROM boms b WHERE b.requirement_id=r.id) ORDER BY r.id DESC') as $r) {
        $rows[] = ['kind' => 'req', 'id' => (int)$r['id'], 'ref' => $r['req_number'], 'title' => $r['title'],
                   'company' => $r['company'], 'stage' => 'Requirement received - BOM not started', 'pending_by' => 'Engineer',
                   'remarks' => $r['remarks'], 'since' => substr((string)$r['created_at'], 0, 10),
                   'link' => url('/boms/search', ['requirement_id' => $r['id']])];
    }
    // 2. latest version of every BOM that has not been quoted yet
    foreach (all("SELECT b.*, c.company FROM boms b LEFT JOIN customers c ON c.id=b.customer_id
                  WHERE b.status != 'QUOTED' AND b.status != 'REVISION_REQUIRED' AND COALESCE(b.created_by,'') != 'Excel import'
                    AND COALESCE(b.source,'') != 'UPLOAD'
                    AND " . LATEST_BOM . ' ORDER BY b.id DESC') as $b) {
        [$stage, $who] = PENDING_STAGE[$b['status']] ?? [$b['status'], 'Engineer'];
        $rows[] = ['kind' => 'bom', 'id' => (int)$b['id'], 'ref' => "{$b['bom_number']} v{$b['version']}", 'title' => $b['title'],
                   'company' => $b['company'], 'stage' => $stage, 'pending_by' => $who, 'remarks' => $b['remarks'],
                   'since' => substr((string)$b['created_at'], 0, 10), 'link' => url("/boms/{$b['id']}")];
    }
    // 3. quotations generated but not yet sent
    foreach (all("SELECT q.*, c.company FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id
                  WHERE q.status='DRAFT' ORDER BY q.id DESC") as $qt) {
        $rows[] = ['kind' => 'quote', 'id' => (int)$qt['id'], 'ref' => $qt['quote_number'], 'title' => $qt['subject'] ?: 'Quotation',
                   'company' => $qt['company'], 'stage' => 'Quotation draft - not yet sent to customer',
                   'pending_by' => 'Sales', 'remarks' => $qt['remarks'], 'since' => substr((string)$qt['created_at'], 0, 10),
                   'link' => url("/quotes/{$qt['id']}")];
    }
    // who is it assigned to, when is it due, what was the last remark
    $today = today_str();
    foreach ($rows as &$r) {
        $t = KIND_TABLE[$r['kind']];
        $a = one("SELECT x.assigned_to, x.due_date, u.name AS aname FROM $t x LEFT JOIN users u ON u.id=x.assigned_to WHERE x.id=?",
                 [$r['id']]);
        $r['assignee_id'] = $a['assigned_to'] !== null ? (int)$a['assigned_to'] : null;
        $r['assignee'] = $a['aname'];
        $r['due'] = $a['due_date'];
        $r['overdue'] = $a['due_date'] && $a['due_date'] < $today;
        $r['last_remark'] = one("SELECT * FROM remarks WHERE kind=? AND item_id=? AND rtype IN ('REMARK','DELAY') ORDER BY id DESC LIMIT 1",
                                [$r['kind'], $r['id']]);
    }
    unset($r);
    return $rows;
}

// ---------------------------------------------------------------------------
// BOMs
// ---------------------------------------------------------------------------

/** All amounts are whole rupees. */
function compute_bom_totals(?array $bom, array $items): array
{
    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal += (float)($it['quantity'] ?? 0) * (float)($it['unit_price'] ?? 0);
    }
    $margin_amount = pyround($subtotal * (float)($bom['margin_percent'] ?? 0) / 100.0);
    $selling = $subtotal + $margin_amount;
    $gst_amount = pyround($selling * (float)($bom['gst_percent'] ?? 0) / 100.0);
    $grand_total = $selling + $gst_amount;
    return ['subtotal' => (int)$subtotal, 'margin_amount' => (int)$margin_amount, 'selling' => (int)$selling,
            'gst_amount' => (int)$gst_amount, 'grand_total' => (int)$grand_total];
}

function bom_items(int $bom_id): array
{
    return all('SELECT * FROM bom_items WHERE bom_id=? ORDER BY id', [$bom_id]);
}

/** [bom_id => [items, totals]] for a list of BOMs. */
function bom_totals_for(array $boms): array
{
    $out = [];
    foreach ($boms as $b) {
        $items = bom_items((int)$b['id']);
        $out[$b['id']] = [$items, compute_bom_totals($b, $items)];
    }
    return $out;
}

/** (assigned_to, due_date) for a new BOM: the requirement's engineer, else the creator if an engineer. */
function inherited_owner($requirement_id, ?array $me): array
{
    if ($requirement_id) {
        $r = one('SELECT assigned_to, due_date FROM requirements WHERE id=?', [$requirement_id]);
        if ($r && $r['assigned_to']) {
            return [$r['assigned_to'], $r['due_date']];
        }
    }
    return [($me && $me['role'] === 'Engineer') ? $me['id'] : null, null];
}

function copy_items(int $from_bom, int $to_bom): void
{
    foreach (bom_items($from_bom) as $it) {
        q('INSERT INTO bom_items (bom_id, component_id, category, manufacturer, part_number, description, quantity, unit_price)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$to_bom, $it['component_id'], $it['category'], $it['manufacturer'], $it['part_number'],
           $it['description'], $it['quantity'], $it['unit_price']]);
    }
}

/**
 * Replace the whole Component database with the distinct components used in all BOMs
 * (category + model). Price = the most frequent non-zero unit price used for it.
 */
function rebuild_components(): int
{
    $groups = [];
    foreach (all('SELECT * FROM bom_items ORDER BY id') as $it) {
        $model = trim(preg_replace('/\s+/u', ' ', (string)$it['part_number']));
        $cat = trim((string)$it['category']);
        if ($model === '' || $cat === '') {
            continue;
        }
        $key = mb_strtolower($cat) . "\0" . preg_replace('/[^a-z0-9]/', '', mb_strtolower($model));
        $g = $groups[$key] ?? ['cat' => '', 'model' => '', 'mfr' => '', 'desc' => '', 'prices' => [], 'n' => 0];
        $g['cat'] = $g['cat'] ?: $cat;
        $g['model'] = $g['model'] ?: $model;
        $g['mfr'] = $g['mfr'] ?: (string)$it['manufacturer'];
        $g['desc'] = $g['desc'] ?: (string)$it['description'];
        $g['n']++;
        if ((float)$it['unit_price'] > 0) {
            $p = (int)pyround($it['unit_price']);
            $g['prices'][$p] = ($g['prices'][$p] ?? 0) + 1;
        }
        $groups[$key] = $g;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE bom_items SET component_id=NULL');
        q('UPDATE procurement SET component_id=NULL');
        q('DELETE FROM components');
        $list = array_values($groups);
        usort($list, fn($a, $b) => [mb_strtolower($a['cat']), mb_strtolower($a['model'])] <=> [mb_strtolower($b['cat']), mb_strtolower($b['model'])]);
        foreach ($list as $g) {
            $price = 0;
            $best = 0;
            foreach ($g['prices'] as $p => $count) {   // first-seen price wins a tie
                if ($count > $best) {
                    [$price, $best] = [$p, $count];
                }
            }
            q("INSERT INTO components (category, manufacturer, part_number, description, supplier, cost, selling_price, stock)
               VALUES (?, ?, ?, ?, 'From BOM history', ?, ?, 0)",
              [$g['cat'], $g['mfr'], $g['model'], $g['desc'], $price, $price]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return count($groups);
}

/** Add a BOM line to the component database when that category + model is not there yet. */
function add_component_from_line(array $it): void
{
    $model = trim(preg_replace('/\s+/u', ' ', (string)$it['part_number']));
    $cat = trim((string)$it['category']);
    if ($model === '' || $cat === '') {
        return;
    }
    if (!val('SELECT 1 FROM components WHERE LOWER(category)=LOWER(?) AND LOWER(part_number)=LOWER(?) LIMIT 1', [$cat, $model])) {
        $price = to_int($it['unit_price']);
        q("INSERT INTO components (category, manufacturer, part_number, description, supplier, cost, selling_price, stock)
           VALUES (?, ?, ?, ?, 'From BOM history', ?, ?, 0)", [$cat, (string)$it['manufacturer'], $model, (string)$it['description'], $price, $price]);
    }
}

// ---------------------------------------------------------------------------
// Quotation (format = Allway HPC Word quotation; editable any time)
// ---------------------------------------------------------------------------

const DEFAULT_SUBJECT = 'Quotation for High Performance Server / Workstation';
const DEFAULT_TERMS = [
    'taxes' => 'GST @ 18% extra as mentioned in the above table',
    'warranty' => '3 Years Standard warranty (only on hardware parts)',
    'payment' => '100% Advance along with the PO',
    'delivery' => '6-8 weeks from the date of receipt of PO; subject to stock availability at the time of Processing the PO.',
    'validity' => 'The Price Valid for 5 days',
    'order_to' => 'Must be placed on ALLWAY HPC PVT LTD',
];
const TERM_LABELS = [['taxes', 'Taxes'], ['warranty', 'Warranty'], ['payment', 'Payment'],
                     ['delivery', 'Delivery'], ['validity', 'Validity'], ['order_to', 'Order to be released on']];
const SPEC_GROUPS = [['Overview', ['Make', 'Model']], ['Build', ['Chassis', 'Chipset', 'CPU', 'Memory']],
                     ['Storage', ['SSD', 'HDD', 'Drive Bays']],
                     ['Expansion', ['Expansion Slots', 'GPU', 'Network', 'Ports', 'Add-on-Card']],
                     ['Power & OS', ['Power Supply', 'Operating System']]];
const SPEC_LABELS = DOCX_SPEC_ROWS;
const DEFAULT_SPECS = ['Make' => 'AHPC', 'Model' => 'AHPC-', 'Ports' => '2 x USB 3.2 Gen2, 1 x MLAN, 1 x VGA',
                       'Operating System' => 'Linux Operating System (Ubuntu / Rocky)'];

/** Which row of the proposal table a BOM line belongs to. */
function spec_row_for(array $item): ?string
{
    $c = strtolower((string)$item['category']);
    $d = strtolower(($item['description'] ?? '') . ' ' . ($item['part_number'] ?? ''));
    $has = fn(string $s) => str_contains($c, $s);
    if ($has('chassis')) return 'Chassis';
    if ($has('mother') || $has('mainboard')) return 'Chipset';
    if ($has('heat') || $has('cooler')) return 'CPU';
    if ($has('cpu') || $has('processor')) return 'CPU';
    if ($has('memory') || in_array($c, ['ram', 'dimm'], true)) return 'Memory';
    if ($has('gpu') || $has('graphic')) return 'GPU';
    if ($has('hdd') || $has('hard') || in_array('hdd', preg_split('/\s+/', trim($d)), true)) return 'HDD';
    if ($has('ssd') || $has('nvme') || $has('storage') || $has('disk')) return 'SSD';
    if ($has('nic') || $has('network') || $has('lan') || $has('infiniband') || $has('hca') || $has('ethernet')) return 'Network';
    if ($has('hba') || $has('raid') || $has('controller') || $has('add')) return 'Add-on-Card';
    if ($has('psu') || $has('power') || $has('smps')) return 'Power Supply';
    if ($c === 'os' || $has('operating')) return 'Operating System';
    // anything else on the BOM (cables, monitor, keyboard & mouse, accessories...) is still listed on the quotation
    return trim($d) !== '' ? 'Add-on-Card' : null;
}

function build_specs(array $items, ?array $existing = null): array
{
    $specs = DEFAULT_SPECS;
    if ($existing) {
        foreach (['Make', 'Model', 'Drive Bays', 'Expansion Slots', 'Ports', 'Operating System'] as $k) {
            if (!empty($existing[$k])) {
                $specs[$k] = $existing[$k];
            }
        }
    }
    $buckets = [];
    foreach ($items as $it) {
        $row = spec_row_for($it);
        if (!$row) {
            continue;
        }
        $name = implode(' ', array_filter([trim((string)$it['manufacturer']), trim((string)$it['part_number'])], fn($x) => $x !== ''));
        $desc = trim((string)$it['description']);
        if ($desc !== '' && !str_contains(mb_strtolower($name), mb_strtolower($desc))) {
            $name = $name !== '' ? "$name - $desc" : $desc;
        }
        $text = trim(((int)($it['quantity'] ?: 1)) . " x $name");
        $cat = strtolower((string)$it['category']);
        if (str_starts_with($cat, 'heat') || str_starts_with($cat, 'cooler')) {
            $text = 'Heatsink: ' . $text;
        }
        $buckets[$row][] = $text;
    }
    foreach ($buckets as $row => $texts) {
        $specs[$row] = implode('; ', $texts);
    }
    return $specs;
}

function new_quote_number(): string
{
    $year = date('Y');
    $best = 1539;   // the supplied format starts at 20260001539
    foreach (q('SELECT quote_number FROM quotations WHERE quote_number LIKE ?', ["$year%"])->fetchAll(PDO::FETCH_COLUMN) as $n) {
        if (preg_match('/^\d{11}$/', (string)$n)) {
            $best = max($best, (int)substr($n, 4));
        }
    }
    return $year . sprintf('%07d', $best + 1);
}

function money_parts($total_price, $gst_percent): array
{
    $total = to_int($total_price);
    $gst = (int)pyround($total * (float)($gst_percent ?? 0) / 100.0);
    return [$total, $gst, $total + $gst];
}

function json_out($v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES);
}

/** Make sure a quotation row has every field of the Word format (also upgrades quotes made by the old version). */
function hydrate_quote(int $quote_id): ?array
{
    $qt = one('SELECT * FROM quotations WHERE id=?', [$quote_id]);
    if ($qt === null) {
        return null;
    }
    if ($qt['specs'] === null) {
        $bom = one('SELECT * FROM boms WHERE id=?', [$qt['bom_id']]);
        $items = $bom ? bom_items((int)$qt['bom_id']) : [];
        $cust = one('SELECT * FROM customers WHERE id=?', [$qt['customer_id']]);
        $gst_pct = $bom ? (float)$bom['gst_percent'] : 18;
        $total = $bom ? compute_bom_totals($bom, $items)['selling'] : (float)$qt['subtotal'] + (float)$qt['margin_amount'];
        [$total, $gst, $grand] = money_parts($total, $gst_pct);
        $terms = DEFAULT_TERMS;
        $terms['taxes'] = 'GST @ ' . fmt_g($gst_pct) . '% extra as mentioned in the above table';
        q('UPDATE quotations SET to_company=?, to_address=?, subject=?, specs=?, total_price=?, gst_percent=?,
           gst_amount=?, grand_total=?, quote_date=?, terms_json=?, updated_at=? WHERE id=?',
          [$cust['company'] ?? '', $cust['address'] ?? '', DEFAULT_SUBJECT, json_out(build_specs($items)), $total, $gst_pct,
           $gst, $grand, substr($qt['created_at'] ?: now_str(), 0, 10), json_out($terms), now_str(), $quote_id]);
        $qt = one('SELECT * FROM quotations WHERE id=?', [$quote_id]);
    }
    return $qt;
}

/** Plain array used by the HTML page and the .docx filler. */
function quote_context(array $qt): array
{
    $specs = json_decode($qt['specs'] ?: '{}', true) ?: [];
    $terms = array_merge(DEFAULT_TERMS, json_decode($qt['terms_json'] ?: '{}', true) ?: []);
    $d = $qt['quote_date'] ?: substr((string)$qt['created_at'], 0, 10);
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    $shown = $dt ? $dt->format('d/m/Y') : $d;
    $spec_rows = [];
    foreach (SPEC_LABELS as $l) {
        $spec_rows[$l] = (string)($specs[$l] ?? '');
    }
    return [
        'id' => (int)$qt['id'], 'quote_number' => $qt['quote_number'], 'date' => $shown, 'date_iso' => $d,
        'to_company' => $qt['to_company'] ?? '', 'to_address' => $qt['to_address'] ?? '',
        'subject' => $qt['subject'] ?: DEFAULT_SUBJECT, 'specs' => $spec_rows, 'terms' => $terms,
        'total_price' => (float)($qt['total_price'] ?? 0),
        'gst_percent' => $qt['gst_percent'] !== null ? (float)$qt['gst_percent'] : 18.0,
        'gst_amount' => (float)($qt['gst_amount'] ?? 0), 'grand_total' => (float)($qt['grand_total'] ?? 0),
        'status' => $qt['status'], 'remarks' => $qt['remarks'] ?? '', 'bom_id' => $qt['bom_id'],
    ];
}

// ---------------------------------------------------------------------------
// Overdue reminders (run by cron/reminders.php, or automatically once an hour after 09:00)
// ---------------------------------------------------------------------------

/** E-mail the assignee + admins about every pending item past its due date and ask for the reason for delay. */
function overdue_reminders(): int
{
    $today = today_str();
    $sent = 0;
    foreach (pending_quotes() as $p) {
        if (!($p['overdue'] && $p['assignee_id'])) {
            continue;
        }
        $info = item_info($p['kind'], $p['id']);
        if (val("SELECT 1 FROM email_log WHERE event='OVERDUE' AND ref=? AND DATE(created_at)=?", [$info['ref'], $today])) {
            continue;
        }
        notify_event($p['kind'], $p['id'], 'OVERDUE', 'OVERDUE - please give reason for delay',
                     "This " . strtolower($info['label']) . " was due on {$p['due']} and is still pending ({$p['stage']}). "
                     . "Please open it and add a 'Reason for delay' remark with a revised due date.");
        $sent++;
    }
    return $sent;
}

/** Called after normal page requests: run the reminder check at most once an hour, from 09:00. */
function maybe_run_reminders(): void
{
    if ((int)date('G') < 9) {
        return;
    }
    $last = (int)val("SELECT v FROM app_meta WHERE k='reminders_last_run'");
    if (time() - $last < 3600) {
        return;
    }
    q("REPLACE INTO app_meta (k, v) VALUES ('reminders_last_run', ?)", [(string)time()]);
    try {
        overdue_reminders();
    } catch (Throwable $e) {
        error_log('[reminder] error: ' . $e->getMessage());
    }
}
