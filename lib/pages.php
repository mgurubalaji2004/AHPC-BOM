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
        $email = strtolower(trim((string)form('email', '')));   // user name or e-mail
        $password = (string)form('password', '');
        $u = one('SELECT * FROM users WHERE (LOWER(email)=? OR LOWER(username)=?) AND active=1', [$email, $email]);
        if ($u === null || !$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
            flash('Wrong user name / e-mail or password.', 'error');
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
        'users' => all("SELECT u.*, (SELECT COUNT(*) FROM requirements WHERE assigned_to=u.id)
                              + (SELECT COUNT(*) FROM boms WHERE assigned_to=u.id AND COALESCE(source,'')<>'UPLOAD')
                              + (SELECT COUNT(*) FROM quotations WHERE assigned_to=u.id) AS n_assigned
                        FROM users u ORDER BY u.role, u.name"),
        'log' => all('SELECT * FROM email_log ORDER BY id DESC LIMIT 100'),
        'mail_ok' => mail_is_configured(),
        'mail' => mail_config(),
    ], 'Users & Emails');
}

/** Check and normalise the user form. Returns [data, error]. */
function user_form(?int $id): array
{
    $d = [
        'name' => trim((string)form('name', '')),
        'username' => strtolower(trim((string)form('username', ''))),
        'email' => strtolower(trim((string)form('email', ''))),
        'role' => in_array(form('role'), ROLES, true) ? form('role') : 'Engineer',
        'active' => form('active', $id ? '0' : '1') === '1' ? 1 : 0,
    ];
    if ($d['name'] === '' || $d['username'] === '') {
        return [$d, 'Name and user name are required.'];
    }
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $d['username'])) {
        return [$d, 'User name: 3-60 letters, digits, dot, dash or underscore (no spaces).'];
    }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        return [$d, 'That e-mail address is not valid.'];
    }
    if (val('SELECT id FROM users WHERE LOWER(username)=? AND id<>?', [$d['username'], $id ?? 0])) {
        return [$d, "User name {$d['username']} is already taken."];
    }
    if ($d['email'] !== '' && val('SELECT id FROM users WHERE LOWER(email)=? AND id<>?', [$d['email'], $id ?? 0])) {
        return [$d, "E-mail {$d['email']} is already used by another user."];
    }
    return [$d, null];
}

function other_active_admins(int $id): int
{
    return (int)val("SELECT COUNT(*) FROM users WHERE role='Admin' AND active=1 AND id<>?", [$id]);
}

function page_user_create(): void
{
    roles_required('Admin');
    [$d, $err] = user_form(null);
    $pw = (string)form('password', '');
    if (!$err && $pw !== '' && strlen($pw) < 8) {
        $err = 'Password must be at least 8 characters (or leave it empty to generate one).';
    }
    if ($err) {
        flash($err, 'error');
        redirect(url('/team'));
    }
    $generated = $pw === '';
    $pw = $generated ? new_password() : $pw;
    insert('INSERT INTO users (name, username, email, role, password_hash, active) VALUES (?, ?, ?, ?, ?, 1)',
           [$d['name'], $d['username'], $d['email'] ?: null, $d['role'], password_hash($pw, PASSWORD_DEFAULT)]);
    flash("User {$d['name']} created ({$d['role']}). Login: {$d['username']}" . ($generated ? "  password: $pw  - share it now; it is not shown again." : '.'));
    redirect(url('/team'));
}

function page_user_edit(int $user_id): void
{
    roles_required('Admin');
    $u = one('SELECT * FROM users WHERE id=?', [$user_id]) ?? abort(404);
    [$d, $err] = user_form($user_id);
    if (!$err && $u['role'] === 'Admin' && ($d['role'] !== 'Admin' || !$d['active']) && other_active_admins($user_id) === 0) {
        $err = 'There must always be at least one active admin.';
    }
    if (!$err && $user_id === (int)$_SESSION['user_id'] && !$d['active']) {
        $err = 'You cannot deactivate your own login.';
    }
    $pw = (string)form('password', '');
    if (!$err && $pw !== '' && strlen($pw) < 8) {
        $err = 'Password must be at least 8 characters.';
    }
    if ($err) {
        flash($err, 'error');
        redirect(url('/team'));
    }
    q('UPDATE users SET name=?, username=?, email=?, role=?, active=? WHERE id=?',
      [$d['name'], $d['username'], $d['email'] ?: null, $d['role'], $d['active'], $user_id]);
    if ($pw !== '') {
        q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $user_id]);
    }
    flash("User {$d['name']} saved" . ($pw !== '' ? ' with the new password.' : '.'));
    redirect(url('/team'));
}

function page_user_delete(int $user_id): void
{
    roles_required('Admin');
    $u = one('SELECT * FROM users WHERE id=?', [$user_id]) ?? abort(404);
    if ($user_id === (int)$_SESSION['user_id']) {
        flash('You cannot delete your own login.', 'error');
        redirect(url('/team'));
    }
    if ($u['role'] === 'Admin' && $u['active'] && other_active_admins($user_id) === 0) {
        flash('There must always be at least one active admin.', 'error');
        redirect(url('/team'));
    }
    foreach (KIND_TABLE as $t) {   // their work goes back to "unassigned"; remarks keep the author's name
        q("UPDATE $t SET assigned_to=NULL WHERE assigned_to=?", [$user_id]);
        q("UPDATE $t SET assigned_by=NULL WHERE assigned_by=?", [$user_id]);
    }
    q('UPDATE remarks SET author_id=NULL WHERE author_id=?', [$user_id]);
    q('DELETE FROM users WHERE id=?', [$user_id]);
    flash("User {$u['name']} deleted. Their open work is now unassigned.");
    redirect(url('/team'));
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
    $uid = (int)$_SESSION['user_id'];
    $mine = arg('mine') === '1';
    $person = arg('person');          // user id, or 'none' for unassigned
    $search = trim((string)arg('q', ''));
    $my_open = array_values(array_filter($all_pending, fn($p) => $p['assignee_id'] === $uid));

    // pending work per person (every active user is listed, also with 0)
    $people = [];
    foreach (team_users() as $u) {
        $people[(int)$u['id']] = ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role'], 'items' => [], 'overdue' => 0];
    }
    $unassigned = ['id' => 'none', 'name' => 'Unassigned', 'role' => '', 'items' => [], 'overdue' => 0];
    $shown = $mine ? $my_open : $all_pending;
    if ($search !== '') {
        $needle = mb_strtolower($search);
        $shown = array_values(array_filter($shown, fn($p) => str_contains(mb_strtolower(
            $p['ref'] . ' ' . $p['title'] . ' ' . $p['company'] . ' ' . $p['assignee'] . ' ' . $p['stage']), $needle)));
    }
    foreach ($shown as $p) {
        $key = $p['assignee_id'];
        if ($key !== null && !isset($people[$key])) {   // assigned to a disabled user
            $people[$key] = ['id' => $key, 'name' => $p['assignee'] ?? 'Former user', 'role' => '', 'items' => [], 'overdue' => 0];
        }
        if ($key === null) {
            $unassigned['items'][] = $p;
            $unassigned['overdue'] += $p['overdue'] ? 1 : 0;
        } else {
            $people[$key]['items'][] = $p;
            $people[$key]['overdue'] += $p['overdue'] ? 1 : 0;
        }
    }
    $groups = array_values($people);
    usort($groups, fn($a, $b) => [$a['role'] !== 'Admin', $a['name']] <=> [$b['role'] !== 'Admin', $b['name']]);
    $groups[] = $unassigned;
    $chips = $groups;
    if ($person !== null && $person !== '') {
        $groups = array_values(array_filter($groups, fn($g) => (string)$g['id'] === (string)$person));
    }
    $groups = array_values(array_filter($groups, fn($g) => $g['items']));

    $counts = [
        'requirements' => (int)val('SELECT COUNT(*) FROM requirements'),
        'boms' => (int)val("SELECT COUNT(DISTINCT bom_number) FROM boms WHERE COALESCE(source,'') <> 'UPLOAD'"),
        'library' => (int)val("SELECT COUNT(*) FROM boms WHERE source = 'UPLOAD'"),
        'pending' => count($all_pending),
        'quotes' => (int)val('SELECT COUNT(*) FROM quotations'),
    ];
    render('dashboard', ['counts' => $counts, 'groups' => $groups, 'chips' => $chips, 'mine' => $mine, 'person' => $person,
                         'my_count' => count($my_open), 'users' => team_users(), 'q' => $search,
                         'results' => $search !== '' ? global_search($search) : null], 'Dashboard - AHPC BOM System');
}

/** Keyword search over customers, requirements, BOMs and quotations (every word must match). */
function global_search(string $q): array
{
    $words = array_values(array_filter(preg_split('/\s+/u', $q)));
    $cond = function (array $cols) use ($words): array {
        $parts = [];
        $params = [];
        foreach ($words as $w) {
            $parts[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $cols)) . ')';
            array_push($params, ...array_fill(0, count($cols), "%$w%"));
        }
        return [implode(' AND ', $parts), $params];
    };
    [$w, $p] = $cond(['c.company', 'c.contact_person', 'c.email', 'c.phone', 'c.address']);
    $customers = all("SELECT c.*, (SELECT COUNT(*) FROM boms b WHERE b.customer_id=c.id) n_boms,
                             (SELECT COUNT(*) FROM requirements r WHERE r.customer_id=c.id) n_reqs
                      FROM customers c WHERE $w ORDER BY c.company LIMIT 25", $p);
    [$w, $p] = $cond(['r.req_number', 'r.title', 'r.description', 'r.sales_person', 'r.remarks', 'c.company']);
    $requirements = all("SELECT r.*, c.company, u.name AS assignee FROM requirements r LEFT JOIN customers c ON c.id=r.customer_id
                         LEFT JOIN users u ON u.id=r.assigned_to WHERE $w ORDER BY r.id DESC LIMIT 25", $p);
    [$w, $p] = $cond(['b.title', 'b.bom_number', 'b.source_file', 'c.company',
                      "(SELECT GROUP_CONCAT(CONCAT_WS(' ', i.category, i.manufacturer, i.part_number, i.description) SEPARATOR ' ') FROM bom_items i WHERE i.bom_id=b.id)"]);
    $boms = all("SELECT b.*, c.company FROM boms b LEFT JOIN customers c ON c.id=b.customer_id WHERE $w ORDER BY b.id DESC LIMIT 25", $p);
    [$w, $p] = $cond(['q.quote_number', 'q.to_company', 'q.subject', 'q.specs', 'c.company']);
    $quotes = all("SELECT q.*, c.company FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id WHERE $w ORDER BY q.id DESC LIMIT 25", $p);
    return compact('customers', 'requirements', 'boms', 'quotes');
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
    $work = "COALESCE(b.source,'') <> 'UPLOAD'";
    $latest = LATEST_BOM . " AND $work";
    $months = month_keys(12);
    $monthly = function (string $table, string $extra = '') use ($months): array {
        $data = array_fill_keys($months, 0);
        foreach (all("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) c FROM $table b WHERE 1=1 $extra GROUP BY m") as $r) {
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
    $pairs = fn(array $rows, string $k, string $v) => array_map(fn($r) => [(string)($r[$k] ?? '-'), (int)pyround($r[$v] ?? 0)], $rows);

    $bom_status = array_map(fn($r) => [ucwords(strtolower(str_replace('_', ' ', $r['status']))), (int)$r['c']],
                            all("SELECT status, COUNT(*) c FROM boms b WHERE $latest GROUP BY status ORDER BY c DESC"));
    $by_customer = $pairs(all("SELECT COALESCE(c.company, qt.to_company, '-') company, SUM(qt.grand_total) s FROM quotations qt
                               LEFT JOIN customers c ON c.id=qt.customer_id GROUP BY company ORDER BY s DESC LIMIT 10"), 'company', 's');
    $by_category = $pairs(all("SELECT CASE WHEN it.category IS NULL OR it.category='' THEN 'Other' ELSE it.category END cat,
                                      SUM(it.quantity*it.unit_price) s FROM bom_items it JOIN boms b ON b.id=it.bom_id
                               WHERE " . LATEST_BOM . " GROUP BY cat ORDER BY s DESC LIMIT 12"), 'cat', 's');
    $library_customers = $pairs(all("SELECT COALESCE(c.company,'(no customer)') company, COUNT(*) n FROM boms b
                                     LEFT JOIN customers c ON c.id=b.customer_id WHERE b.source='UPLOAD' GROUP BY company ORDER BY n DESC LIMIT 12"), 'company', 'n');
    $quote_rows = array_reverse(all('SELECT quote_number, subtotal, margin_amount, gst_amount FROM quotations ORDER BY id DESC LIMIT 10'));
    $quote_split = array_map(fn($r) => [(string)$r['quote_number'], (int)pyround($r['subtotal'] ?? 0), (int)pyround($r['margin_amount'] ?? 0),
                                        (int)pyround($r['gst_amount'] ?? 0)], $quote_rows);

    $pending = pending_quotes();
    $by_person = [];
    foreach (team_users() as $u) {
        $by_person[$u['name']] = [0, 0];
    }
    foreach ($pending as $p) {
        $k = $p['assignee'] ?? 'Unassigned';
        $by_person[$k] ??= [0, 0];
        $by_person[$k][$p['overdue'] ? 1 : 0]++;
    }
    $by_owner = [];
    foreach ($pending as $p) {
        $by_owner[$p['pending_by']] = ($by_owner[$p['pending_by']] ?? 0) + 1;
    }
    $done_by = $pairs(all("SELECT COALESCE(u.name, 'Unassigned') n, COUNT(*) c FROM quotations q LEFT JOIN users u ON u.id=q.assigned_to
                           GROUP BY n ORDER BY c DESC"), 'n', 'c');

    $n_req = (int)val('SELECT COUNT(*) FROM requirements');
    $n_bom = (int)val("SELECT COUNT(DISTINCT bom_number) FROM boms b WHERE $work");
    $n_appr = (int)val("SELECT COUNT(*) FROM boms b WHERE $latest AND status IN ('APPROVED','QUOTED')");
    $n_quote = (int)val('SELECT COUNT(*) FROM quotations');
    $n_sent = (int)val("SELECT COUNT(*) FROM quotations WHERE status='SENT'");
    $total_val = (float)val('SELECT COALESCE(SUM(grand_total),0) FROM quotations');
    $sent_val = (float)val("SELECT COALESCE(SUM(grand_total),0) FROM quotations WHERE status='SENT'");
    $sales_rows = all("SELECT COALESCE(NULLIF(sales_person,''),'-') sp, COUNT(*) c FROM requirements GROUP BY sp ORDER BY c DESC LIMIT 10");

    $data = [
        'months' => array_map(fn($m) => date('M y', strtotime("$m-01")), $months),
        'req_m' => $monthly('requirements'), 'bom_m' => $monthly('boms', "AND $work"), 'quote_m' => $monthly('quotations'),
        'quote_value_m' => array_values($qval),
        'funnel' => [['Requirements', $n_req], ['BOMs', $n_bom], ['Approved', $n_appr], ['Quotations', $n_quote], ['Sent', $n_sent]],
        'bom_status' => $bom_status, 'by_customer' => $by_customer, 'by_category' => $by_category,
        'quote_split' => $quote_split, 'pending_owner' => array_map(fn($k, $v) => [$k, $v], array_keys($by_owner), array_values($by_owner)),
        'by_person' => array_map(fn($k, $v) => [$k, $v[0], $v[1]], array_keys($by_person), array_values($by_person)),
        'done_by' => $done_by, 'library_customers' => $library_customers,
        'by_sales' => array_map(fn($r) => [$r['sp'], (int)$r['c']], $sales_rows),
    ];
    $kpis = [
        'requirements' => $n_req, 'boms' => $n_bom, 'quotes' => $n_quote, 'pending' => count($pending),
        'quote_value' => $total_val, 'sent_value' => $sent_val,
        'avg_quote' => $n_quote ? $total_val / $n_quote : 0,
        'customers' => (int)val('SELECT COUNT(*) FROM customers'),
        'library' => (int)val("SELECT COUNT(*) FROM boms WHERE source='UPLOAD'"),
        'overdue' => count(array_filter($pending, fn($p) => $p['overdue'])),
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
    $search = trim((string)arg('q', ''));
    $rows = all("SELECT c.*, (SELECT COUNT(*) FROM boms b WHERE b.customer_id = c.id) AS n_boms FROM customers c"
                . ($search !== '' ? ' WHERE c.company LIKE ? OR c.contact_person LIKE ? OR c.email LIKE ?' : '') . ' ORDER BY c.company',
                $search !== '' ? array_fill(0, 3, "%$search%") : []);
    render('customers', ['customers' => $rows, 'q' => $search], 'Customers');
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
    $total = (int)val(str_replace('SELECT *', 'SELECT COUNT(*)', $sql), $params);
    $page = max(1, (int)arg('page', 1));
    $per_page = 100;
    $sql .= ' ORDER BY category, manufacturer, part_number LIMIT ' . $per_page . ' OFFSET ' . (($page - 1) * $per_page);
    render('components', [
        'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per_page)),
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

/**
 * BOMs page. Tab "library" (default) = the BOMs uploaded from Excel / Zoho sheets, tab "working" = BOMs made in
 * the app. Every BOM shows all its components and can be used, customized or opened as a new BOM.
 */
function page_boms_list(): void
{
    login_required();
    $search = trim((string)arg('q', ''));
    $tab = arg('tab') === 'working' ? 'working' : 'library';
    $page = max(1, (int)arg('page', 1));
    $per_page = 40;
    $where = $tab === 'library' ? "b.source = 'UPLOAD'" : "COALESCE(b.source, '') <> 'UPLOAD'";
    $params = [];
    if ($search !== '') {
        foreach (preg_split('/\s+/u', $search) as $w) {
            $where .= ' AND (b.title LIKE ? OR b.bom_number LIKE ? OR c.company LIKE ? OR b.source_file LIKE ? OR EXISTS
                       (SELECT 1 FROM bom_items i WHERE i.bom_id=b.id AND (i.part_number LIKE ? OR i.description LIKE ?
                        OR i.category LIKE ? OR i.manufacturer LIKE ?)))';
            array_push($params, ...array_fill(0, 8, "%$w%"));
        }
    }
    $from = "FROM boms b LEFT JOIN customers c ON c.id = b.customer_id WHERE $where";
    $total = (int)val("SELECT COUNT(*) $from", $params);
    $rows = all("SELECT b.*, c.company $from ORDER BY " . ($tab === 'library' ? 'c.company IS NULL, c.company, b.title, b.id' : 'b.id DESC')
                . ' LIMIT ' . $per_page . ' OFFSET ' . (($page - 1) * $per_page), $params);
    $counts = [
        'library' => (int)val("SELECT COUNT(*) FROM boms WHERE source = 'UPLOAD'"),
        'working' => (int)val("SELECT COUNT(*) FROM boms WHERE COALESCE(source, '') <> 'UPLOAD'"),
    ];
    render('boms_list', ['boms' => $rows, 'detail' => bom_totals_for($rows), 'q' => $search, 'tab' => $tab, 'page' => $page,
                         'pages' => max(1, (int)ceil($total / $per_page)), 'total' => $total, 'counts' => $counts], 'BOMs');
}

/** Upload one or more BOM sheets (.xlsx / Zoho .html) into the BOM library. */
function page_bom_upload(): void
{
    login_required();
    require_once __DIR__ . '/bom_import.php';
    $files = $_FILES['files'] ?? null;
    $made = [];
    $problems = [];
    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $problems[] = "$name: upload failed (error {$files['error'][$i]})";
                continue;
            }
            try {
                $recs = parse_bom_file($files['tmp_name'][$i], $name);
                if (!$recs) {
                    $problems[] = "$name: no BOM table found (needs a 'Components / Model / Quantity / Unit Price' table)";
                    continue;
                }
                $ids = save_uploaded_boms($recs, current_user_name() ?? 'Upload');
                $made = array_merge($made, $ids);
                if (count($ids) < count($recs)) {
                    $problems[] = "$name: " . (count($recs) - count($ids)) . ' BOM(s) were already in the library and were skipped';
                }
            } catch (Throwable $e) {
                $problems[] = "$name: " . $e->getMessage();
            }
        }
    }
    if ($made) {
        foreach (all('SELECT * FROM bom_items WHERE bom_id IN (' . implode(',', array_map('intval', $made)) . ')') as $it) {
            add_component_from_line($it);
        }
        flash(count($made) . ' BOM(s) added to the library.');
    }
    foreach ($problems as $p) {
        flash($p, 'error');
    }
    if (!$made && !$problems) {
        flash('Choose one or more .xlsx files first.', 'error');
    }
    redirect(url('/boms', ['tab' => 'library']));
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
        $src = int_or_null(form('copy_from'));
        $src_bom = $src ? one('SELECT * FROM boms WHERE id=?', [$src]) : null;
        $new_bom_id = insert(
            "INSERT INTO boms (bom_number, version, requirement_id, customer_id, title, status,
                               pricing_mode, margin_percent, gst_percent, created_by, created_at, assigned_to, due_date, parent_bom_id)
             VALUES (?, 1, ?, ?, ?, 'DRAFT', 'AUTOMATIC', ?, ?, ?, ?, ?, ?, NULL)",
            [$bom_number, $requirement_id, $customer_id, form('title'), $src_bom['margin_percent'] ?? 15, $src_bom['gst_percent'] ?? 18,
             current_user_name(), now_str(), $owner, $due]);
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
                       'copy_bom' => $copy_bom, 'copy_items' => $items, 'requirements' => open_requirements()], 'Create BOM');
}

function open_requirements(): array
{
    return all('SELECT r.id, r.req_number, r.title, r.customer_id, c.company FROM requirements r
                LEFT JOIN customers c ON c.id = r.customer_id ORDER BY r.id DESC LIMIT 300');
}

/**
 * Customize: pick which lines of an existing (e.g. uploaded) BOM to keep, change models / quantities / prices,
 * add lines, then save it as a NEW draft BOM for a customer / requirement. The original is not changed.
 */
function page_bom_customize(int $bom_id): void
{
    login_required();
    $bom = load_bom($bom_id);
    $items = bom_items($bom_id);
    if (is_post()) {
        $customer_id = resolve_customer();
        if (!$customer_id) {
            flash('Please select a customer, or enter the company name of the new customer.', 'error');
            redirect(url("/boms/$bom_id/customize"));
        }
        $lines = [];
        foreach ((array)($_POST['line'] ?? []) as $l) {
            if (empty($l['keep'])) {
                continue;
            }
            $cat = trim((string)($l['category'] ?? ''));
            $model = trim((string)($l['part_number'] ?? ''));
            if ($cat === '' && $model === '') {
                continue;
            }
            $lines[] = [$cat !== '' ? $cat : 'Other', trim((string)($l['manufacturer'] ?? '')), $model,
                        trim((string)($l['description'] ?? '')), to_int($l['quantity'] ?? 1, 1, 1), to_int($l['unit_price'] ?? 0, 0, 0)];
        }
        if (!$lines) {
            flash('Keep at least one line.', 'error');
            redirect(url("/boms/$bom_id/customize"));
        }
        $requirement_id = int_or_null(form('requirement_id'));
        $me = me();
        [$owner, $due] = inherited_owner($requirement_id, $me);
        $bom_number = next_number('boms', 'bom_number', 'BOM');
        $new_id = insert(
            "INSERT INTO boms (bom_number, version, requirement_id, customer_id, title, status, pricing_mode, margin_percent,
                               gst_percent, created_by, created_at, assigned_to, due_date)
             VALUES (?, 1, ?, ?, ?, 'DRAFT', 'CUSTOMIZED', ?, ?, ?, ?, ?, ?)",
            [$bom_number, $requirement_id, $customer_id, (string)form('title', $bom['title']), (float)form('margin_percent', 15),
             (float)form('gst_percent', 18), current_user_name(), now_str(), $owner, $due]);
        foreach ($lines as $l) {
            q('INSERT INTO bom_items (bom_id, category, manufacturer, part_number, description, quantity, unit_price)
               VALUES (?, ?, ?, ?, ?, ?, ?)', array_merge([$new_id], $l));
        }
        add_remark('bom', $new_id, $me, 'SYSTEM', "Customized from {$bom['bom_number']} ({$bom['title']})");
        notify_event('bom', $new_id, 'BOM_NEW', 'BOM created (customized)',
                     "{$me['name']} created $bom_number by customizing {$bom['bom_number']}.", $me);
        flash("BOM $bom_number created from {$bom['bom_number']}.");
        redirect(url("/boms/$new_id"));
    }
    $requirement_id = int_or_null(arg('requirement_id'));
    render('bom_customize', ['bom' => $bom, 'items' => $items, 'customers' => all('SELECT * FROM customers ORDER BY company'),
                             'requirements' => open_requirements(), 'requirement_id' => $requirement_id,
                             'totals' => compute_bom_totals($bom, $items)], 'Customize ' . $bom['bom_number']);
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
    // LibreOffice (if installed) converts the Word file 1:1; otherwise the built-in PDF writer draws the same layout
    $pdf = docx_to_pdf(build_docx($ctx)) ?? build_quote_pdf($ctx);
    send_download($pdf, "Quotation_{$ctx['quote_number']}.pdf", 'application/pdf');
}

// ---------------------------------------------------------------------------
// Deleting (admins only)
// ---------------------------------------------------------------------------

function delete_quote_rows(array $quote_ids): void
{
    foreach ($quote_ids as $qid) {
        $qid = (int)$qid;
        foreach (q('SELECT id FROM orders WHERE quote_id=?', [$qid])->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            q('DELETE FROM procurement WHERE order_id=?', [$oid]);
        }
        q('DELETE FROM orders WHERE quote_id=?', [$qid]);
        q("DELETE FROM remarks WHERE kind='quote' AND item_id=?", [$qid]);
        q('DELETE FROM quotations WHERE id=?', [$qid]);
    }
}

function page_bom_delete(int $bom_id): void
{
    roles_required('Admin');
    $bom = load_bom($bom_id);
    $pdo = db();
    $pdo->beginTransaction();
    delete_quote_rows(q('SELECT id FROM quotations WHERE bom_id=?', [$bom_id])->fetchAll(PDO::FETCH_COLUMN));
    q('DELETE FROM bom_items WHERE bom_id=?', [$bom_id]);
    q("DELETE FROM remarks WHERE kind='bom' AND item_id=?", [$bom_id]);
    q('UPDATE boms SET parent_bom_id=NULL WHERE parent_bom_id=?', [$bom_id]);
    q('DELETE FROM boms WHERE id=?', [$bom_id]);
    $pdo->commit();
    flash("BOM {$bom['bom_number']} ({$bom['title']}) deleted.");
    $back = referer_path();
    redirect($back && !str_contains($back, "/boms/$bom_id") ? $back : url('/boms', ['tab' => $bom['source'] === 'UPLOAD' ? 'library' : 'working']));
}

function page_quote_delete(int $quote_id): void
{
    roles_required('Admin');
    $qt = one('SELECT * FROM quotations WHERE id=?', [$quote_id]) ?? abort(404);
    delete_quote_rows([$quote_id]);
    // the BOM can be quoted again
    if ($qt['bom_id'] && !val('SELECT 1 FROM quotations WHERE bom_id=?', [$qt['bom_id']])) {
        q("UPDATE boms SET status='APPROVED' WHERE id=? AND status='QUOTED'", [$qt['bom_id']]);
    }
    flash("Quotation {$qt['quote_number']} deleted.");
    redirect(url('/quotes'));
}

function page_component_delete(int $id): void
{
    roles_required('Admin');
    $c = one('SELECT * FROM components WHERE id=?', [$id]) ?? abort(404);
    q('DELETE FROM components WHERE id=?', [$id]);   // BOM lines keep their text; only the link is cleared
    flash("Component {$c['category']} - {$c['part_number']} deleted.");
    redirect(referer_path() ?? url('/components'));
}

function page_components_delete_selected(): void
{
    roles_required('Admin');
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if ($ids) {
        q('DELETE FROM components WHERE id IN (' . implode(',', $ids) . ')');
    }
    flash(count($ids) . ' component(s) deleted.');
    redirect(referer_path() ?? url('/components'));
}

function page_customer_delete(int $id): void
{
    roles_required('Admin');
    $c = one('SELECT * FROM customers WHERE id=?', [$id]) ?? abort(404);
    foreach (['requirements', 'boms', 'quotations'] as $t) {
        q("UPDATE $t SET customer_id=NULL WHERE customer_id=?", [$id]);
    }
    q('DELETE FROM customers WHERE id=?', [$id]);
    flash("Customer {$c['company']} deleted (their BOMs and quotations are kept).");
    redirect(url('/customers'));
}

function page_customer_edit(int $id): void
{
    roles_required('Admin');
    one('SELECT id FROM customers WHERE id=?', [$id]) ?? abort(404);
    $company = trim((string)form('company', ''));
    if ($company === '') {
        flash('Company name is required.', 'error');
    } else {
        q('UPDATE customers SET company=?, contact_person=?, email=?, phone=?, address=? WHERE id=?',
          [$company, form('contact_person'), form('email'), form('phone'), form('address'), $id]);
        flash("Customer $company saved.");
    }
    redirect(url('/customers'));
}

function page_requirement_delete(int $id): void
{
    roles_required('Admin');
    $r = one('SELECT * FROM requirements WHERE id=?', [$id]) ?? abort(404);
    q('UPDATE boms SET requirement_id=NULL WHERE requirement_id=?', [$id]);
    q("DELETE FROM remarks WHERE kind='req' AND item_id=?", [$id]);
    q('DELETE FROM requirements WHERE id=?', [$id]);
    flash("Requirement {$r['req_number']} deleted (its BOMs are kept).");
    redirect(url('/requirements'));
}
