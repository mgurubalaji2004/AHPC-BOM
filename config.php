<?php
/*
 * AHPC BOM & Quotation Management System - settings.
 * Edit this file, save, and reload the page. Nothing else needs to change.
 */

// ---------------------------------------------------------------------------
//  MySQL / MariaDB
//  Tip: put your real login in config.local.php (copy config.local.example.php) - that file is
//  never overwritten when you install a new version. Values there win over the ones below.
// ---------------------------------------------------------------------------
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', 'ahpc_bom');   // created automatically if the user is allowed to
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

// ---------------------------------------------------------------------------
//  ZOHO MAIL SETTINGS  -  EDIT THESE 5 LINES
// ---------------------------------------------------------------------------
const SMTP_HOST = 'smtp.zoho.in';             // India account: smtp.zoho.in | Global: smtp.zoho.com | EU: smtp.zoho.eu
const SMTP_PORT = 465;                        // 465 = SSL (recommended), 587 = STARTTLS
const MAIL_USER = 'noreply@allwayhpc.com';    // the Zoho mailbox that SENDS all notifications
const MAIL_PASSWORD = 'PUT-PASSWORD-HERE';    // its password (or Zoho "app password" if 2-factor is on)
const APP_BASE_URL = '';                      // address your team opens, e.g. http://192.168.1.10/ahpc  (blank = auto)
const MAIL_FROM_NAME = 'AHPC BOM System';

// ---------------------------------------------------------------------------
//  App
// ---------------------------------------------------------------------------
const APP_TIMEZONE = 'Asia/Kolkata';
const SESSION_NAME = 'ahpc_bom';

// Starting logins, created once on first start (after that, admins add / edit / delete users on the
// "Users" page). Everyone signs in with their user name or e-mail and changes the password under "Password".
// [name, user name, e-mail, role, starting password]
const TEAM = [
    ['Praveen', 'praveen', 'praveen@allwayhpc.com', 'Admin', 'Praveen@2026'],
    ['Saravana Kumar', 'saravanakumar', 'saravanakumar@allwayhpc.com', 'Admin', 'Saravana@2026'],
    ['Ravichandran', 'ravichandran', 'ravichandran@allwayhpc.com', 'Admin', 'Ravi@2026'],
    ['Gurubalaji', 'gurubalaji', 'gurubalaji@allwayhpc.com', 'Engineer', 'Guru@2026'],
    ['Theepthithan', 'theepthithan', 'theepthithan@allwayhpc.com', 'Engineer', 'Theep@2026'],
    ['Dinesh', 'dinesh', 'dinesh@allwayhpc.com', 'Engineer', 'Dinesh@2026'],
    ['Muthumadhan', 'muthumadhan', 'muthumadhan@allwayhpc.com', 'Engineer', 'Muthu@2026'],
];

// Path to LibreOffice for the "Download PDF" button (blank = look for soffice / libreoffice on the PATH).
const SOFFICE_PATH = '';
