<?php
/*
 * AHPC BOM & Quotation Management System - settings.
 * Edit this file, save, and reload the page. Nothing else needs to change.
 */

// ---------------------------------------------------------------------------
//  MySQL / MariaDB
// ---------------------------------------------------------------------------
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'ahpc_bom';          // created automatically if the user is allowed to
const DB_USER = 'root';
const DB_PASS = '';

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

// The team. Admins can assign work to anyone (including themselves); engineers do the work.
// [name, e-mail (login), role, starting password]
const TEAM = [
    ['Gurubalaji', 'gurubalaji@allwayhpc.com', 'Engineer', 'Guru@2026'],
    ['Saravana Kumar', 'saravanakumar@allwayhpc.com', 'Admin', 'Saravana@2026'],
    ['Dinesh', 'dinesh@allwayhpc.com', 'Engineer', 'Dinesh@2026'],
    ['Theepthithan', 'theepthithan@allwayhpc.com', 'Engineer', 'Theep@2026'],
    ['Praveen', 'praveen@allwayhpc.com', 'Admin', 'Praveen@2026'],
];

// Path to LibreOffice for the "Download PDF" button (blank = look for soffice / libreoffice on the PATH).
const SOFFICE_PATH = '';
