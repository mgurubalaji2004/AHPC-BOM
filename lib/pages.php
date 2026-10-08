<?php
/*
 * Page handlers - one function per route (see the route table in index.php).
 * Workflow: Requirement -> Search Existing BOM/Components -> BOM Builder -> Pricing (Margin + GST)
 *           -> Internal Review -> Admin Approval -> Quotation Generation
 */

// ---------------------------------------------------------------------------
// Login / account / team
// ---------------------------------------------------------------------------

function page_login(): void
{
    if (is_post()) {
        $email = strtolower(trim((string)form('email', '')));
        $password = (string)form('password', '');
        $u = one('SELECT * FROM users WHERE LOWER(email)=? AND active=1', [$email]);
        if ($u === null || !$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
            flash('Wrong e-mail or password.', 'error');
            render('login', ['email' => $email], 'Login - AHPC BOM System', 401);
        }
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['user_name'] = $u['name'];
        $_SESSION['role'] = $u['role'];
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        flash("Welcome, {$u['name']} ({$u['role']})");
        redirect(safe_next(arg('next')) ?? url('/'));
    }
    render('login', ['email' => ''], 'Login - AHPC BOM System');
}

function page_logout(): void
{
    $_SESSION = [];
    redirect(url('/login'));
}

function page_change_password(): void
{
    login_required();
    if (is_post()) {
        $u = one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]);
        $new = (string)form('new_password', '');
        if (!password_verify((string)form('old_password', ''), (string)$u['password_hash'])) {
            flash('Current password is wrong.', 'error');
        } elseif (strlen($new) < 8) {
            flash('New password must be at least 8 characters.', 'error');
        } elseif ($new !== form('confirm_password')) {
            flash('New password and confirmation do not match.', 'error');
        } else {
            q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            flash('Password changed.');
            redirect(url('/'));
        }
    }
    render('change_password', [], 'Change password');
}

function page_team(): void
{
    roles_required('Admin');
    render('team', [
        'users' => all('SELECT * FROM users ORDER BY role, name'),
        'log' => all('SELECT * FROM email_log ORDER BY id DESC LIMIT 100'),
        'mail_ok' => mail_is_configured(),
        'mail' => mail_config(),
    ], 'Team & Emails');
}

function page_reset_password(int $user_id): void
{
    roles_required('Admin');
    $u = one('SELECT * FROM users WHERE id=?', [$user_id]) ?? abort(404);
    $pw = new_password();
    q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $user_id]);
    flash("New password for {$u['name']} ({$u['email']}): $pw  - share it with them now; it is not shown again.");
    redirect(url('/team'));
}

function page_team_test_mail(): void
{
    roles_required('Admin');
    $me = one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]);
    $subject = '[AHPC] Test e-mail from the BOM system';
    $body = 'If you can read this, Zoho Mail notifications are working.';
    $sent = send_mail([[$me['name'], $me['email']]], $subject, "<p>$body</p>", $body, null, 'TEST', '');
    flash($sent ? 'Test e-mail queued to ' . implode(', ', $sent) . ' - check the log below for the result.' : 'No recipient.');
    redirect(url('/team'));
}

// ---------------------------------------------------------------------------
// Assignment and remarks (conversation thread)
// ---------------------------------------------------------------------------

function page_assign(string $kind, int $item_id): void
{
    roles_required('Admin');
    check_kind($kind);
    $assignee = user_by_id(int_or_null(form('assignee_id')));
    if (!$assignee || !$assignee['active']) {
        flash('Choose a person to assign to.', 'error');
    } else {
        do_assign($kind, $item_id, (int)$assignee['id'], form('due_date'), trim((string)form('note', '')), me());
        flash("Assigned to {$assignee['name']}. They have been notified by e-mail.");
    }
    redirect_back();
}

/** Add a remark or a 'reason for delay' to a requirement / BOM / quotation and e-mail it. */
function page_save_remarks(string $kind, int $item_id): void
{
    login_required();
    $table = check_kind($kind);
    item_info($kind, $item_id);   // 404 when missing
    $body = trim((string)form('remarks', ''));
    if ($body === '') {
        flash('Please type a remark first.', 'error');
        redirect_back();
    }
    $me = me();
    $rtype = form('rtype') === 'DELAY' ? 'DELAY' : 'REMARK';
    $new_due = date_or_null(form('new_due'));
    $text = $body . ($rtype === 'DELAY' && $new_due ? " (revised due date: $new_due)" : '');
    add_remark($kind, $item_id, $me, $rtype, $text);
    q("UPDATE $table SET remarks=? WHERE id=?", [($rtype === 'DELAY' ? 'Delay: ' : '') . $body, $item_id]);
    if ($rtype === 'DELAY' && $new_due) {
        q("UPDATE $table SET due_date=? WHERE id=?", [$new_due, $item_id]);
    }
    if ($rtype === 'DELAY') {
        [$headline, $event, $msg] = ['Reason for delay', 'DELAY', "{$me['name']} has reported a delay:\n\n$text"];
    } elseif ($me['role'] === 'Admin') {
        [$headline, $event, $msg] = ['Remark from Admin', 'REMARK_ADMIN', "{$me['name']} (Admin) added a remark:\n\n$text"];
    } else {
        [$headline, $event, $msg] = ['Remark to Admin', 'REMARK_ENGINEER', "{$me['name']} (Engineer) added a remark:\n\n$text"];
    }
    notify_event($kind, $item_id, $event, $headline, $msg, $me);
    flash('Remark saved and e-mailed.');
    redirect_back();
}

// ---------------------------------------------------------------------------
// Dashboard + analytics
// ---------------------------------------------------------------------------

function page_dashboard(): void
{
    login_required();
    $all_pending = pending_quotes();
    $mine = arg('mine') === '1';
    $uid = (int)$_SESSION['user_id'];
    $my_open = array_values(array_filter($all_pending, fn($p) => $p['assignee_id'] === $uid));
    $counts = [
        'requirements' => (int)val('SELECT COUNT(*) FROM requirements'),
        'boms' => (int)val('SELECT COUNT(DISTINCT bom_number) FROM boms'),
        'pending' => count($all_pending),
        'quotes' => (int)val('SELECT COUNT(*) FROM quotations'),
    ];
    render('dashboard', ['counts' => $counts, 'pending' => $mine ? $my_open : $all_pending, 'mine' => $mine,
                         'my_count' => count($my_open), 'users' => team_users()], 'Dashboard - AHPC BOM System');
}

function month_keys(int $n = 6): array
{
    $keys = [];
    $y = (int)date('Y');
    $m = (int)date('n');
    for ($i = 0; $i < $n; $i++) {
        $keys[] = sprintf('%04d-%02d', $y, $m);
        if (--$m === 0) {
            [$m, $y] = [12, $y - 1];
        }
    }
    return array_reverse($keys);
}

function page_analytics(): void
{
    login_required();
    $latest = LATEST_BOM;
    $months = month_keys(6);
    $monthly = function (string $table) use ($months): array {
        $data = array_fill_keys($months, 0);
        foreach (all("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) c FROM $table GROUP BY m") as $r) {
            if (isset($data[$r['m']])) {
                $data[$r['m']] = (int)$r['c'];
            }
        }
        return array_values($data);
    };

    $qval = array_fill_keys($months, 0);
    foreach (all("SELECT DATE_FORMAT(created_at, '%Y-%m') m, SUM(grand_total) s FROM quotations GROUP BY m") as $r) {
        if (isset($qval[$r['m']])) {
            $qval[$r['m']] = (int)pyround($r['s'] ?? 0);
        }
    }
    $bom_status = array_map(fn($r) => [ucwords(strtolower(str_replace('_', ' ', $r['status']))), (int)$r['c']],
                            all("SELECT status, COUNT(*) c FROM boms b WHERE $latest GROUP BY status"));
    $by_customer = array_map(fn($r) => [$r['company'] ?? '-', (int)pyround($r['s'] ?? 0)],
                             all('SELECT c.company, SUM(qt.grand_total) s, COUNT(*) n FROM quotations qt
                                  LEFT JOIN customers c ON c.id=qt.customer_id GROUP BY c.company ORDER BY s DESC LIMIT 8'));
    $by_category = array_map(fn($r) => [($r['cat'] ?? '') !== '' ? $r['cat'] : 'Other', (int)pyround($r['s'] ?? 0)],
                             all("SELECT it.category cat, SUM(it.quantity*it.unit_price) s FROM bom_items it
                                  JOIN boms b ON b.id=it.bom_id WHERE $latest GROUP BY it.category ORDER BY s DESC LIMIT 10"));
    $quote_rows = array_reverse(all('SELECT quote_number, subtotal, margin_amount, gst_amount FROM quotations ORDER BY id DESC LIMIT 8'));
    $quote_split = array_map(fn($r) => ['#' . substr(preg_replace('/\D/', '', (string)$r['quote_number']), -5),
                                        (int)pyround($r['subtotal'] ?? 0), (int)pyround($r['margin_amount'] ?? 0),
                                        (int)pyround($r['gst_amount'] ?? 0)], $quote_rows);

    $pending = pending_quotes();
    $by_owner = [];
    foreach ($pending as $p) {
        $by_owner[$p['pending_by']] = ($by_owner[$p['pending_by']] ?? 0) + 1;
    }

    $n_req = (int)val('SELECT COUNT(*) FROM requirements');
    $n_bom = (int)val('SELECT COUNT(DISTINCT bom_number) FROM boms');
    $n_appr = (int)val("SELECT COUNT(*) FROM boms b WHERE $latest AND status IN ('APPROVED','QUOTED')");
    $n_quote = (int)val('SELECT COUNT(*) FROM quotations');
    $n_sent = (int)val("SELECT COUNT(*) FROM quotations WHERE status='SENT'");
    $total_val = (float)val('SELECT COALESCE(SUM(grand_total),0) FROM quotations');
    $sent_val = (float)val("SELECT COALESCE(SUM(grand_total),0) FROM quotations WHERE status='SENT'");
    $sales_rows = all("SELECT COALESCE(sales_person,'-') sp, COUNT(*) c FROM requirements GROUP BY sp ORDER BY c DESC LIMIT 8");

    $data = [
        'months' => $months,
        'req_m' => $monthly('requirements'), 'bom_m' => $monthly('boms'), 'quote_m' => $monthly('quotations'),
        'quote_value_m' => array_values($qval),
        'funnel' => [['Requirements', $n_req], ['BOMs', $n_bom], ['Approved', $n_appr], ['Quotes', $n_quote], ['Sent', $n_sent]],
        'bom_status' => $bom_status, 'by_customer' => $by_customer, 'by_category' => $by_category,
        'quote_split' => $quote_split,
        'pending_owner' => array_map(fn($k, $v) => [$k, $v], array_keys($by_owner), array_values($by_owner)),
        'by_sales' => array_map(fn($r) => [$r['sp'], (int)$r['c']], $sales_rows),
    ];
    $kpis = [
        'requirements' => $n_req, 'boms' => $n_bom, 'quotes' => $n_quote, 'pending' => count($pending),
        'quote_value' => $total_val, 'sent_value' => $sent_val,
        'avg_quote' => $n_quote ? $total_val / $n_quote : 0,
        'customers' => (int)val('SELECT COUNT(*) FROM customers'),
        'conversion' => $n_req ? (int)pyround(100.0 * $n_sent / $n_req) : 0,
    ];
    render('analytics', ['data' => $data, 'kpis' => $kpis], 'Analytics - AHPC BOM System');
}

// ---------------------------------------------------------------------------
// Step 1: Customers
// ---------------------------------------------------------------------------

function page_customers(): void
{
    login_required();
    if (is_post()) {
        q('INSERT INTO customers (company, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)',
          [trim((string)form('company', '')), form('contact_person'), form('email'), form('phone'), form('address')]);
        flash('Customer added.');
        redirect(url('/customers'));
    }
    render('customers', ['customers' => all('SELECT * FROM customers ORDER BY company')], 'Customers');
}

// ---------------------------------------------------------------------------
// Step 2: Requirement entry
// ---------------------------------------------------------------------------

function page_requirements_list(): void
{
    login_required();
    $rows = all('SELECT r.*, c.company FROM requirements r LEFT JOIN customers c ON c.id = r.customer_id ORDER BY r.id DESC');
    render('requirements_list', ['requirements' => $rows], 'Requirements');
}

function page_requirement_new(): void
{
    login_required();
    if (is_post()) {
        $customer_id = resolve_customer();
        if (!$customer_id) {
            flash('Please select a customer, or enter the company name of the new customer.', 'error');
            redirect(url('/requirements/new'));
        }
        $req_number = next_number('requirements', 'req_number', 'REQ');
        $new_id = insert(
            "INSERT INTO requirements (req_number, customer_id, sales_person, req_date, expected_delivery, title, description, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'OPEN', ?)",
            [$req_number, $customer_id, form('sales_person') ?? current_user_name(), date_or_null(form('req_date')) ?? today_str(),
             date_or_null(form('expected_delivery')), form('title'), form('description'), now_str()]);
        $me = me();
        $assignee_id = int_or_null(form('assignee_id'));
        if ($me['role'] === 'Admin' && $assignee_id && user_by_id($assignee_id)) {
            do_assign('req', $new_id, $assignee_id, form('due_date'), trim((string)form('note', '')), $me);
        } else {
            notify_event('req', $new_id, 'REQ_NEW', 'New requirement received (not assigned yet)',
                         "{$me['name']} entered a new customer requirement. An admin needs to assign it to an engineer.\n\n"
                         . form('description', ''), $me, to_assignee: false);
        }
        flash("Requirement $req_number created.");
        redirect(url('/boms/search', ['requirement_id' => $new_id]));
    }
    render('requirement_new', ['customers' => all('SELECT * FROM customers ORDER BY company'), 'req' => null,
                               'users' => team_users()], 'New Requirement');
}

function page_requirement_edit(int $req_id): void
{
    login_required();
    $r = one('SELECT * FROM requirements WHERE id=?', [$req_id]) ?? abort(404);
    if (is_post()) {
        $customer_id = resolve_customer() ?? $r['customer_id'];
        q('UPDATE requirements SET customer_id=?, sales_person=?, req_date=?, expected_delivery=?, title=?, description=? WHERE id=?',
          [$customer_id, form('sales_person'), date_or_null(form('req_date')), date_or_null(form('expected_delivery')),
           form('title'), form('description'), $req_id]);
        notify_event('req', $req_id, 'REQ_EDIT', 'Requirement updated',
                     current_user_name() . ' edited the requirement details.', me());
        flash('Requirement updated.');
        redirect(url('/requirements'));
    }
    render('requirement_new', ['customers' => all('SELECT * FROM customers ORDER BY company'), 'req' => $r,
                               'users' => team_users()], 'Edit Requirement');
}

// ---------------------------------------------------------------------------
// Step 3: Component database / search
// ---------------------------------------------------------------------------

function page_components(): void
{
    login_required();
    if (is_post()) {
        q('INSERT INTO components (category, manufacturer, part_number, description, supplier, cost, selling_price, stock)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [trim((string)form('category', '')), form('manufacturer'), form('part_number'), form('description'), form('supplier'),
           to_int(form('cost')), to_int(form('selling_price')), to_int(form('stock'))]);
        flash('Component added.');
        redirect(url('/components'));
    }
    $search = trim((string)arg('q', ''));
    $category = trim((string)arg('category', ''));
    $sql = 'SELECT * FROM components WHERE 1=1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (manufacturer LIKE ? OR part_number LIKE ? OR description LIKE ? OR category LIKE ?)';
        $params = array_fill(0, 4, "%$search%");
    }
    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY category, manufacturer';
    render('components', [
        'components' => all($sql, $params),
        'categories' => q('SELECT DISTINCT category FROM components ORDER BY category')->fetchAll(PDO::FETCH_COLUMN),
        'q' => $search, 'category' => $category,
    ], 'Components');
}

function page_components_rebuild(): void
{
    roles_required('Admin');
    $n = rebuild_components();
    flash("Component database replaced: $n components built from all BOMs.");
    redirect(url('/components'));
}

// ---------------------------------------------------------------------------
// Step 4: BOMs (search -> create -> edit at ANY time -> clone to make another BOM)
// ---------------------------------------------------------------------------

/** All existing BOMs with every component, qty and price visible; each can be edited or cloned. */
function page_boms_list(): void
{
    login_required();
    $search = trim((string)arg('q', ''));
    $sql = 'SELECT b.*, c.company FROM boms b LEFT JOIN customers c ON c.id = b.customer_id WHERE 1=1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (b.title LIKE ? OR b.bom_number LIKE ? OR c.company LIKE ? OR EXISTS
                  (SELECT 1 FROM bom_items i WHERE i.bom_id=b.id AND (i.part_number LIKE ? OR i.description LIKE ? OR i.category LIKE ?)))';
        $params = array_fill(0, 6, "%$search%");
    }
    $rows = all($sql . ' ORDER BY b.id DESC', $params);
    render('boms_list', ['boms' => $rows, 'detail' => bom_totals_for($rows), 'q' => $search], 'BOMs');
}

/**
 * Keyword search over BOM title, customer, number AND every component line (CPU, motherboard, heatsink...).
 * Each hit is shown with its COMPLETE bill of materials.
 */
function page_bom_search(): void
{
    login_required();
    $requirement_id = int_or_null(arg('requirement_id'));
    $requirement = null;
    $search = trim((string)($_GET['q'] ?? ''));
    $searched = array_key_exists('q', $_GET);
    if ($requirement_id) {
        $requirement = one('SELECT * FROM requirements WHERE id=?', [$requirement_id]);
        if ($search === '' && $requirement && !$searched) {
            $search = (string)$requirement['title'];
        }
    }
    $similar = [];
    $words = array_values(array_filter(preg_split('/[\s,;]+/u', $search), fn($w) => mb_strlen($w) > 1));
    if ($words) {
        $parts = [];
        $params = [];
        foreach ($words as $w) {
            $parts[] = '(CASE WHEN b.title LIKE ? OR b.bom_number LIKE ? OR c.company LIKE ? OR EXISTS
                         (SELECT 1 FROM bom_items i WHERE i.bom_id=b.id AND (i.category LIKE ? OR i.manufacturer LIKE ?
                           OR i.part_number LIKE ? OR i.description LIKE ?)) THEN 1 ELSE 0 END)';
            array_push($params, ...array_fill(0, 7, "%$w%"));
        }
        // when several words are typed, show BOMs matching the most words first (but any single match qualifies)
        $similar = all('SELECT * FROM (SELECT b.*, c.company, (' . implode(' + ', $parts) . ') AS score FROM boms b
                          LEFT JOIN customers c ON c.id = b.customer_id WHERE ' . LATEST_BOM . ') x
                        WHERE score > 0 ORDER BY score DESC, id DESC', $params);
    }
    render('bom_search', ['requirement' => $requirement, 'similar' => $similar, 'q' => $search, 'words' => $words,
                          'detail' => bom_totals_for($similar)], 'Search Existing BOM');
}

/** CREATE NEW BOM (empty structure) or copy/customize an existing one. */
function page_bom_new(): void
{
    login_required();
    $requirement_id = int_or_null(arg('requirement_id'));
    $copy_from = int_or_null(arg('copy_from'));

    if (is_post()) {
        $customer_id = resolve_customer();
        if (!$customer_id) {
            flash('Please select a customer, or enter the company name of the new customer.', 'error');
            redirect(current_uri());
        }
        $requirement_id = int_or_null(form('requirement_id'));
        $bom_number = next_number('boms', 'bom_number', 'BOM');
        $me = me();
        [$owner, $due] = inherited_owner($requirement_id, $me);
        $new_bom_id = insert(
            "INSERT INTO boms (bom_number, version, requirement_id, customer_id, title, status,
                               pricing_mode, margin_percent, gst_percent, created_by, created_at, assigned_to, due_date)
             VALUES (?, 1, ?, ?, ?, 'DRAFT', 'AUTOMATIC', 15, 18, ?, ?, ?, ?)",
            [$bom_number, $requirement_id, $customer_id, form('title'), current_user_name(), now_str(), $owner, $due]);
        $src = int_or_null(form('copy_from'));
        if ($src) {
            copy_items($src, $new_bom_id);
        }
        notify_event('bom', $new_bom_id, 'BOM_NEW', 'BOM created', "{$me['name']} started a new BOM ($bom_number).", $me);
        flash("BOM $bom_number created. You can add and edit components below.");
        redirect(url("/boms/$new_bom_id"));
    }

    $requirement = $requirement_id ? one('SELECT * FROM requirements WHERE id=?', [$requirement_id]) : null;
    $copy_bom = $copy_from ? one('SELECT * FROM boms WHERE id=?', [$copy_from]) : null;
    $items = $copy_bom ? all('SELECT * FROM bom_items WHERE bom_id=?', [$copy_from]) : [];
    render('bom_new', ['requirement' => $requirement, 'customers' => all('SELECT * FROM customers ORDER BY company'),
                       'copy_bom' => $copy_bom, 'copy_items' => $items], 'Create BOM');
}

/** Create ANOTHER BOM from an existing one (new BOM number, all components copied, draft). */
function page_bom_clone(int $bom_id): void
{
    login_required();
    $bom = one('SELECT * FROM boms WHERE id=?', [$bom_id]) ?? abort(404);
    $bom_number = next_number('boms', 'bom_number', 'BOM');
    $me = me();
    [$owner, $due] = inherited_owner($bom['requirement_id'], $me);
    $new_id = insert(
        "INSERT INTO boms (bom_number, version, requirement_id, customer_id, title, status,
                           pricing_mode, margin_percent, gst_percent, created_by, created_at, assigned_to, due_date)
         VALUES (?, 1, ?, ?, ?, 'DRAFT', ?, ?, ?, ?, ?, ?, ?)",
        [$bom_number, $bom['requirement_id'], $bom['customer_id'], ($bom['title'] ?? '') . ' (copy)', $bom['pricing_mode'],
         $bom['margin_percent'], $bom['gst_percent'], current_user_name(), now_str(), $owner, $due]);
    copy_items($bom_id, $new_id);
    notify_event('bom', $new_id, 'BOM_NEW', 'BOM created (copy)',
                 "{$me['name']} created $bom_number as a copy of {$bom['bom_number']}.", $me);
    flash("New BOM $bom_number created from {$bom['bom_number']}. Modify components as needed.");
    redirect(url("/boms/$new_id"));
}

function page_bom_detail(int $bom_id): void
{
    login_required();
    $bom = one('SELECT * FROM boms WHERE id=?', [$bom_id]) ?? abort(404);
    $items = bom_items($bom_id);
    render('bom_detail', [
        'bom' => $bom, 'items' => $items, 'totals' => compute_bom_totals($bom, $items),
        'customers' => all('SELECT * FROM customers ORDER BY company'),
        'versions' => all('SELECT * FROM boms WHERE bom_number = ? ORDER BY version', [$bom['bom_number']]),
        'quote' => one('SELECT * FROM quotations WHERE bom_id=? ORDER BY id DESC LIMIT 1', [$bom_id]),
        'all_components' => all('SELECT * FROM components ORDER BY category, manufacturer'),
    ], $bom['bom_number']);
}

/** After any edit: tell the user if a quotation already exists for this BOM. */
function bom_changed(int $bom_id): never
{
    $qn = val('SELECT quote_number FROM quotations WHERE bom_id=? ORDER BY id DESC LIMIT 1', [$bom_id]);
    if ($qn) {
        flash("Saved. Quotation $qn already exists for this BOM - open it and use "
              . "'Refresh from BOM' to carry these changes into the quotation.");
    } else {
        flash('BOM saved.');
    }
    redirect(url("/boms/$bom_id"));
}

function load_bom(int $bom_id): array
{
    return one('SELECT * FROM boms WHERE id=?', [$bom_id]) ?? abort(404);
}

function page_bom_meta(int $bom_id): void
{
    login_required();
    load_bom($bom_id);
    $customer_id = resolve_customer();
    q('UPDATE boms SET title=?, customer_id=COALESCE(?, customer_id) WHERE id=?', [form('title'), $customer_id, $bom_id]);
    bom_changed($bom_id);
}

function page_bom_add_item(int $bom_id): void
{
    login_required();
    load_bom($bom_id);
    $component_id = int_or_null(form('component_id'));
    $quantity = to_int(form('quantity'), 1, 1);
    $comp = $component_id ? one('SELECT * FROM components WHERE id=?', [$component_id]) : null;
    if ($comp) {
        q('INSERT INTO bom_items (bom_id, component_id, category, manufacturer, part_number, description, quantity, unit_price)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$bom_id, $comp['id'], $comp['category'], $comp['manufacturer'], $comp['part_number'], $comp['description'],
           $quantity, to_int($comp['selling_price'])]);
    } else {
        q('INSERT INTO bom_items (bom_id, category, manufacturer, part_number, description, quantity, unit_price)
           VALUES (?, ?, ?, ?, ?, ?, ?)',
          [$bom_id, form('category'), form('manufacturer'), form('part_number'), form('description'), $quantity,
           to_int(form('unit_price'))]);
    }
    bom_changed($bom_id);
}

/** Save every edited line of the BOM table in one go. */
function page_bom_save_items(int $bom_id): void
{
    login_required();
    load_bom($bom_id);
    foreach ((array)($_POST['item_id'] ?? []) as $item_id) {
        $item_id = (int)$item_id;
        $f = fn(string $name) => (string)($_POST["{$name}_$item_id"] ?? '');
        q('UPDATE bom_items SET category=?, manufacturer=?, part_number=?, description=?, quantity=?, unit_price=?
           WHERE id=? AND bom_id=?',
          [$f('category'), $f('manufacturer'), $f('part_number'), $f('description'),
           to_int($f('quantity'), 1, 1), to_int($f('unit_price')), $item_id, $bom_id]);
    }
    bom_changed($bom_id);
}

function page_bom_remove_item(int $bom_id, int $item_id): void
{
    login_required();
    q('DELETE FROM bom_items WHERE id=? AND bom_id=?', [$item_id, $bom_id]);
    bom_changed($bom_id);
}

function page_bom_pricing(int $bom_id): void
{
    login_required();
    load_bom($bom_id);
    $mode = form('pricing_mode') === 'CUSTOMIZED' ? 'CUSTOMIZED' : 'AUTOMATIC';
    q('UPDATE boms SET pricing_mode=?, margin_percent=?, gst_percent=? WHERE id=?',
      [$mode, (float)form('margin_percent', 0), (float)form('gst_percent', 0), $bom_id]);
    bom_changed($bom_id);
}

function page_bom_submit_review(int $bom_id): void
{
    login_required();
    load_bom($bom_id);
    q("UPDATE boms SET status='UNDER_REVIEW' WHERE id=?", [$bom_id]);
    $me = me();
    add_remark('bom', $bom_id, $me, 'SYSTEM', 'Submitted for internal review');
    notify_event('bom', $bom_id, 'BOM_SUBMIT', 'BOM submitted for review',
                 "{$me['name']} submitted this BOM for admin review. Please approve it or request a revision.", $me);
    flash('BOM submitted for internal review - admins have been e-mailed.');
    redirect(url("/boms/$bom_id"));
}

/** Move a BOM back to Draft so it goes through review again after changes. */
function page_bom_reopen(int $bom_id): void
{
    login_required();
    load_bom($bom_id);
    q("UPDATE boms SET status='DRAFT' WHERE id=?", [$bom_id]);
    $me = me();
    add_remark('bom', $bom_id, $me, 'SYSTEM', 'Moved back to Draft');
    notify_event('bom', $bom_id, 'BOM_REOPEN', 'BOM moved back to Draft',
                 "{$me['name']} moved this BOM back to Draft for changes.", $me);
    flash('BOM moved back to Draft.');
    redirect(url("/boms/$bom_id"));
}

function page_bom_review_decision(int $bom_id): void
{
    roles_required('Admin');
    $bom = load_bom($bom_id);
    $comment = (string)form('comment', '');
    $me = me();
    if (form('decision') === 'approve') {
        q("UPDATE boms SET status='APPROVED', review_comment=? WHERE id=?", [$comment, $bom_id]);
        add_remark('bom', $bom_id, $me, 'REMARK', 'APPROVED' . ($comment !== '' ? ": $comment" : ''));
        notify_event('bom', $bom_id, 'BOM_APPROVED', 'BOM approved',
                     "{$me['name']} approved this BOM. A quotation can now be generated."
                     . ($comment !== '' ? "\n\nAdmin remark: $comment" : ''), $me);
        flash('BOM approved - the engineer has been e-mailed.');
        redirect(url("/boms/$bom_id"));
    }
    q("UPDATE boms SET status='REVISION_REQUIRED', review_comment=? WHERE id=?", [$comment, $bom_id]);
    $new_id = insert(
        "INSERT INTO boms (bom_number, version, parent_bom_id, requirement_id, customer_id, title,
                           status, pricing_mode, margin_percent, gst_percent, created_by, created_at, remarks,
                           assigned_to, due_date, assigned_by, assigned_at)
         VALUES (?, ?, ?, ?, ?, ?, 'DRAFT', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$bom['bom_number'], $bom['version'] + 1, $bom_id, $bom['requirement_id'], $bom['customer_id'], $bom['title'],
         $bom['pricing_mode'], $bom['margin_percent'], $bom['gst_percent'], current_user_name(), now_str(), $comment,
         $bom['assigned_to'], $bom['due_date'], $bom['assigned_by'], $bom['assigned_at']]);
    copy_items($bom_id, $new_id);
    add_remark('bom', $bom_id, $me, 'REMARK', 'REVISION REQUIRED' . ($comment !== '' ? ": $comment" : ''));
    add_remark('bom', $new_id, $me, 'REMARK', 'Revision requested' . ($comment !== '' ? ": $comment" : ''));
    notify_event('bom', $new_id, 'BOM_REVISION', 'Revision required',
                 "{$me['name']} reviewed {$bom['bom_number']} v{$bom['version']} and asked for changes. "
                 . 'A new draft (v' . ($bom['version'] + 1) . ') has been created for editing.'
                 . ($comment !== '' ? "\n\nAdmin remark: $comment" : ''), $me);
    flash('Revision requested. A new draft version has been created for editing.', 'error');
    redirect(url("/boms/$new_id"));
}

// ---------------------------------------------------------------------------
// Step 5: Quotation
// ---------------------------------------------------------------------------

function page_generate_quote(int $bom_id): void
{
    login_required();
    $bom = load_bom($bom_id);
    if (!in_array($bom['status'], ['APPROVED', 'QUOTED'], true)) {
        flash('BOM must be Admin Approved before generating a quotation.', 'error');
        redirect(url("/boms/$bom_id"));
    }
    $totals = compute_bom_totals($bom, bom_items($bom_id));
    $quote_number = new_quote_number();
    $qid = insert(
        "INSERT INTO quotations (quote_number, bom_id, customer_id, subtotal, margin_amount, gst_amount,
                                 grand_total, terms, status, created_at, assigned_to, due_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, '', 'DRAFT', ?, ?, ?)",
        [$quote_number, $bom_id, $bom['customer_id'], $totals['subtotal'], $totals['margin_amount'],
         $totals['gst_amount'], $totals['grand_total'], now_str(), $bom['assigned_to'], $bom['due_date']]);
    q("UPDATE boms SET status='QUOTED' WHERE id=?", [$bom_id]);
    hydrate_quote($qid);
    $me = me();
    notify_event('quote', $qid, 'QUOTE_NEW', 'Quotation generated',
                 "{$me['name']} generated quotation $quote_number from {$bom['bom_number']} v{$bom['version']}.", $me);
    flash("Quotation $quote_number generated. You can still edit it.");
    redirect(url("/quotes/$qid"));
}

function page_quotes_list(): void
{
    login_required();
    foreach (q('SELECT id FROM quotations WHERE specs IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        hydrate_quote((int)$id);
    }
    $rows = all('SELECT q.*, c.company FROM quotations q LEFT JOIN customers c ON c.id = q.customer_id ORDER BY q.id DESC');
    render('quotes_list', ['quotes' => $rows], 'Quotations');
}

function load_quote(int $quote_id): array
{
    return hydrate_quote($quote_id) ?? abort(404);
}

function page_quote_view(int $quote_id): void
{
    login_required();
    $qt = quote_context(load_quote($quote_id));
    render('quote_view', ['q' => $qt], "Quotation {$qt['quote_number']}");
}

function page_quote_edit(int $quote_id): void
{
    login_required();
    $qt = load_quote($quote_id);
    if (is_post()) {
        $spec_in = (array)($_POST['spec'] ?? []);
        $term_in = (array)($_POST['term'] ?? []);
        $specs = [];
        foreach (SPEC_LABELS as $l) {
            $specs[$l] = trim((string)($spec_in[$l] ?? ''));
        }
        $terms = [];
        foreach (TERM_LABELS as [$k, $_]) {
            $terms[$k] = trim((string)($term_in[$k] ?? ''));
        }
        $gst_pct = (float)form('gst_percent', 0);
        [$total, $gst, $grand] = money_parts(form('total_price'), $gst_pct);
        $status = in_array(form('status'), ['DRAFT', 'SENT'], true) ? form('status') : $qt['status'];
        try {
            q('UPDATE quotations SET quote_number=?, quote_date=?, to_company=?, to_address=?, subject=?, specs=?,
               total_price=?, gst_percent=?, gst_amount=?, grand_total=?, terms_json=?, status=?, updated_at=? WHERE id=?',
              [form('quote_number') ?? $qt['quote_number'], date_or_null(form('quote_date')), (string)form('to_company', ''),
               (string)form('to_address', ''), (string)form('subject', ''), json_out($specs), $total, $gst_pct, $gst, $grand,
               json_out($terms), $status, now_str(), $quote_id]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {   // 1062 = duplicate key
                throw $e;
            }
            flash('Quotation number ' . form('quote_number') . ' is already used by another quotation.', 'error');
            redirect(url("/quotes/$quote_id/edit"));
        }
        notify_event('quote', $quote_id, 'QUOTE_EDIT', 'Quotation edited', current_user_name() . ' edited the quotation.', me());
        flash('Quotation updated.');
        redirect(url("/quotes/$quote_id"));
    }
    $ctx = quote_context($qt);
    render('quote_edit', ['q' => $ctx], "Edit Quotation {$ctx['quote_number']}");
}

/** Re-read the (edited) BOM into the quotation: configuration rows + price. Make/Model/Ports/OS are kept. */
function page_quote_refresh(int $quote_id): void
{
    login_required();
    $qt = load_quote($quote_id);
    $bom = one('SELECT * FROM boms WHERE id=?', [$qt['bom_id']]);
    if (!$bom) {
        flash('The BOM of this quotation no longer exists.', 'error');
        redirect(url("/quotes/$quote_id"));
    }
    $items = bom_items((int)$qt['bom_id']);
    $specs = build_specs($items, json_decode($qt['specs'] ?: '{}', true) ?: []);
    $totals = compute_bom_totals($bom, $items);
    [$total, $gst, $grand] = money_parts($totals['selling'], $bom['gst_percent']);
    $terms = json_decode($qt['terms_json'] ?: '{}', true) ?: [];
    $terms['taxes'] = 'GST @ ' . fmt_g($bom['gst_percent']) . '% extra as mentioned in the above table';
    q('UPDATE quotations SET specs=?, total_price=?, gst_percent=?, gst_amount=?, grand_total=?, subtotal=?,
       margin_amount=?, terms_json=?, updated_at=? WHERE id=?',
      [json_out($specs), $total, $bom['gst_percent'], $gst, $grand, $totals['subtotal'], $totals['margin_amount'],
       json_out($terms), now_str(), $quote_id]);
    flash('Quotation refreshed from the latest BOM.');
    redirect(url("/quotes/$quote_id"));
}

function page_quote_status(int $quote_id): void
{
    login_required();
    load_quote($quote_id);
    $sent = form('status') === 'SENT';
    q('UPDATE quotations SET status=? WHERE id=?', [$sent ? 'SENT' : 'DRAFT', $quote_id]);
    $me = me();
    add_remark('quote', $quote_id, $me, 'SYSTEM', $sent ? 'Marked as sent to customer' : 'Moved back to draft');
    notify_event('quote', $quote_id, 'QUOTE_STATUS', $sent ? 'Quotation sent to customer' : 'Quotation moved back to draft',
                 "{$me['name']} marked this quotation as " . ($sent ? 'SENT to the customer' : 'DRAFT') . '.', $me);
    flash($sent ? 'Quotation marked as sent to customer.' : 'Quotation moved back to draft.');
    redirect(url("/quotes/$quote_id"));
}

function send_download(string $data, string $filename, string $mime): never
{
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

function page_quote_docx(int $quote_id): void
{
    login_required();
    $ctx = quote_context(load_quote($quote_id));
    send_download(build_docx($ctx), "Quotation_{$ctx['quote_number']}.docx",
                  'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}

function page_quote_pdf(int $quote_id): void
{
    login_required();
    $ctx = quote_context(load_quote($quote_id));
    $pdf = docx_to_pdf(build_docx($ctx));
    if ($pdf === null) {
        flash('PDF export needs LibreOffice on the server. Download the Word file and use Save as PDF instead.', 'error');
        redirect(url("/quotes/$quote_id"));
    }
    send_download($pdf, "Quotation_{$ctx['quote_number']}.pdf", 'application/pdf');
}
