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
    ['GET', '/team', 'page_team'],
    ['POST', '/team/{id}/reset_password', 'page_reset_password'],
    ['POST', '/team/test_mail', 'page_team_test_mail'],
    ['POST', '/assign/{kind}/{id}', 'page_assign'],
    ['POST', '/remarks/{kind}/{id}', 'page_save_remarks'],
    ['GET', '/', 'page_dashboard'],
    ['GET', '/analytics', 'page_analytics'],
    ['GET|POST', '/customers', 'page_customers'],
    ['GET', '/requirements', 'page_requirements_list'],
    ['GET|POST', '/requirements/new', 'page_requirement_new'],
    ['GET|POST', '/requirements/{id}/edit', 'page_requirement_edit'],
    ['GET|POST', '/components', 'page_components'],
    ['POST', '/components/rebuild', 'page_components_rebuild'],
    ['GET', '/boms', 'page_boms_list'],
    ['GET', '/boms/search', 'page_bom_search'],
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
    echo '<!DOCTYPE html><title>Database error</title><h1>Database error</h1>'
       . '<p>Could not talk to MySQL. Check the database settings in <code>config.php</code> and that MySQL is running.</p>'
       . '<pre style="white-space:pre-wrap;color:#a00;">' . e($e->getMessage()) . '</pre>';
}
