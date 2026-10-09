<?php
/*
 * AHPC BOM & Quotation Management System
 * ----------------------------------------
 * Internal web application implementing the AHPC BOM Software flowchart:
 *
 *   Requirement -> Search Existing BOM/Components -> BOM Builder
 *   -> Pricing (Margin + GST) -> Internal Review -> Admin Approval
 *   -> Quotation Generation -> Order Status (PO / Cancelled)
 *   -> Procurement / Stock Check -> Work Order -> Packing/Delivery
 *
 * Tech stack: PHP 8.1+ and MySQL / MariaDB. No Composer packages needed.
 * Setup: edit config.php, copy this folder into the web server (e.g. XAMPP htdocs/ahpc)
 * and open http://localhost/ahpc/ - the tables are created automatically.
 */

require_once __DIR__ . '/lib/workflow.php';
require_once __DIR__ . '/lib/pages.php';

// [method, path pattern, handler]. {id} = number, {kind} = req|bom|quote
const ROUTES = [
    ['GET|POST', '/login', 'page_login'],
    ['GET', '/logout', 'page_logout'],
    ['GET|POST', '/account/password', 'page_change_password'],
    ['POST', '/account/theme', 'page_theme'],
    ['GET', '/team', 'page_team'],
    ['POST', '/team/{id}/reset_password', 'page_reset_password'],
    ['POST', '/team/test_mail', 'page_team_test_mail'],
    ['POST', '/team/create', 'page_user_create'],
    ['POST', '/team/{id}/edit', 'page_user_edit'],
    ['POST', '/team/{id}/delete', 'page_user_delete'],
    ['POST', '/assign/{kind}/{id}', 'page_assign'],
    ['POST', '/remarks/{kind}/{id}', 'page_save_remarks'],
    ['GET', '/', 'page_dashboard'],
    ['GET', '/tracker', 'page_tracker'],
    ['GET', '/tracker/export', 'page_tracker_export'],
    ['POST', '/tracker/new', 'page_tracker_new'],
    ['GET|POST', '/tracker/{id}/edit', 'page_tracker_edit'],
    ['POST', '/tracker/{id}/delete', 'page_tracker_delete'],
    ['POST', '/tracker/{id}/stage', 'page_tracker_stage'],
    ['GET', '/analytics', 'page_analytics'],
    ['GET|POST', '/customers', 'page_customers'],
    ['GET', '/requirements', 'page_requirements_list'],
    ['GET|POST', '/requirements/new', 'page_requirement_new'],
    ['GET|POST', '/requirements/{id}/edit', 'page_requirement_edit'],
    ['GET|POST', '/components', 'page_components'],
    ['POST', '/components/rebuild', 'page_components_rebuild'],
    ['GET', '/boms', 'page_boms_list'],
    ['GET', '/boms/search', 'page_bom_search'],
    ['POST', '/boms/upload', 'page_bom_upload'],
    ['GET|POST', '/boms/{id}/customize', 'page_bom_customize'],
    ['GET|POST', '/boms/new', 'page_bom_new'],
    ['POST', '/boms/{id}/clone', 'page_bom_clone'],
    ['GET', '/boms/{id}', 'page_bom_detail'],
    ['POST', '/boms/{id}/meta', 'page_bom_meta'],
    ['POST', '/boms/{id}/add_item', 'page_bom_add_item'],
    ['POST', '/boms/{id}/save_items', 'page_bom_save_items'],
    ['POST', '/boms/{id}/remove_item/{id}', 'page_bom_remove_item'],
    ['POST', '/boms/{id}/pricing', 'page_bom_pricing'],
    ['POST', '/boms/{id}/submit_review', 'page_bom_submit_review'],
    ['POST', '/boms/{id}/reopen', 'page_bom_reopen'],
    ['POST', '/boms/{id}/review', 'page_bom_review_decision'],
    ['POST', '/boms/{id}/generate_quote', 'page_generate_quote'],
    ['GET', '/quotes', 'page_quotes_list'],
    ['GET', '/quotes/{id}', 'page_quote_view'],
    ['GET|POST', '/quotes/{id}/edit', 'page_quote_edit'],
    ['POST', '/quotes/{id}/refresh', 'page_quote_refresh'],
    ['POST', '/quotes/{id}/status', 'page_quote_status'],
    ['GET', '/quotes/{id}/docx', 'page_quote_docx'],
    ['GET', '/quotes/{id}/pdf', 'page_quote_pdf'],
    ['POST', '/quotes/{id}/delete', 'page_quote_delete'],
    ['POST', '/boms/{id}/delete', 'page_bom_delete'],
    ['POST', '/components/{id}/delete', 'page_component_delete'],
    ['POST', '/components/delete_selected', 'page_components_delete_selected'],
    ['POST', '/customers/{id}/delete', 'page_customer_delete'],
    ['POST', '/customers/{id}/edit', 'page_customer_edit'],
    ['POST', '/requirements/{id}/delete', 'page_requirement_delete'],
];

function dispatch(string $method, string $path): void
{
    $path = '/' . trim($path, '/');
    foreach (ROUTES as [$methods, $pattern, $handler]) {
        $re = '#^' . str_replace(['{id}', '{kind}'], ['(\d+)', '(req|bom|quote)'], $pattern) . '$#';
        if (!preg_match($re, $path, $m)) {
            continue;
        }
        if (!in_array($method, explode('|', $methods), true)) {
            abort(405);
        }
        $args = array_map(fn($v) => ctype_digit($v) ? (int)$v : $v, array_slice($m, 1));
        $handler(...$args);
        return;
    }
    abort(404);
}

start_session();
try {
    db();
    load_current_user();
    check_csrf();
    if (me()) {
        register_shutdown_function('maybe_run_reminders');
    }
    dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', (string)($_GET['r'] ?? '/'));
} catch (HttpError $e) {
    $code = $e->getCode() ?: 404;
    http_response_code($code);
    $msg = [404 => 'Not Found', 405 => 'Method Not Allowed'][$code] ?? 'Error';
    echo "<!DOCTYPE html><title>$code $msg</title><h1>$code $msg</h1><p><a href=\"" . e(url('/')) . '">Back to the dashboard</a></p>';
} catch (PDOException $e) {
    error_log('[db] ' . $e->getMessage());
    http_response_code(500);
    $code = (int)($e->errorInfo[1] ?? $e->getCode());
    $help = match ($code) {
        1045 => '<p><b>MySQL refused the login</b> <code>' . e(DB_USER) . '</code>' . (DB_PASS === '' ? ' with an <b>empty password</b>' : '')
              . '. Put the right MySQL user name and password in <code>config.local.php</code> (copy <code>config.local.example.php</code>)'
              . ' or in <code>config.php</code> (DB_USER / DB_PASS), save, and reload this page.</p>'
              . '<p>No user for the app yet? In phpMyAdmin &rarr; SQL (or the mysql command line, logged in as root) run:</p>'
              . '<pre>CREATE DATABASE ahpc_bom CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' . "\n"
              . "CREATE USER 'ahpc'@'localhost' IDENTIFIED BY 'choose-a-password';\n"
              . "CREATE USER 'ahpc'@'127.0.0.1' IDENTIFIED BY 'choose-a-password';\n"
              . "GRANT ALL PRIVILEGES ON ahpc_bom.* TO 'ahpc'@'localhost';\n"
              . "GRANT ALL PRIVILEGES ON ahpc_bom.* TO 'ahpc'@'127.0.0.1';\nFLUSH PRIVILEGES;</pre>"
              . '<p>and then use <code>ahpc</code> / <code>choose-a-password</code> as DB_USER / DB_PASS.</p>',
        2002, 2003, 2006 => '<p><b>MySQL is not reachable</b> at <code>' . e(DB_HOST) . ':' . e(DB_PORT) . '</code>. Start MySQL'
              . ' (XAMPP Control Panel &rarr; MySQL &rarr; Start) or correct DB_HOST / DB_PORT.</p>',
        1044, 1142 => '<p><b>The MySQL user may not use the database</b> <code>' . e(DB_NAME) . '</code>. Create the database and'
              . ' GRANT ALL PRIVILEGES ON ' . e(DB_NAME) . '.* to the user (see README), then reload.</p>',
        default => '<p>Could not talk to MySQL. Check the database settings (DB_HOST, DB_USER, DB_PASS, DB_NAME) and that MySQL is running.</p>',
    };
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Database error</title>'
       . '<body style="font-family:Segoe UI,Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 16px;color:#1c2433;line-height:1.5;">'
       . '<h1>Database error</h1>' . $help
       . '<pre style="white-space:pre-wrap;background:#fde8e8;color:#a00;padding:10px;border-radius:8px;">' . e($e->getMessage()) . '</pre></body>';
}
