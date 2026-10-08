# AHPC BOM & Quotation Management System (PHP + MySQL)

Internal web application for Allway HPC, following the BOM software flowchart:

```
Requirement -> Search existing BOM / components -> BOM builder -> Pricing (margin + GST)
-> Internal review -> Admin approval (or revision = new version) -> Quotation (Word / PDF)
```

The workflow, pages, rules, numbering, calculations and Word quotation format are the same as in
the earlier Python/Flask + SQLite version. It now runs on **PHP 8.1+ and MySQL 5.7+ / MariaDB 10.3+**
and needs no Composer packages.

## Install

1. Copy this folder into the web server, e.g. `C:\xampp\htdocs\ahpc` (XAMPP) or `/var/www/html/ahpc`.
2. Open `config.php` and set:
   * the MySQL login (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`). The database and its tables are
     created on first start. If the MySQL user may not create databases, create `ahpc_bom` first
     (utf8mb4) or import `sql/schema.sql` in phpMyAdmin.
   * the Zoho Mail settings (`MAIL_USER`, `MAIL_PASSWORD`) and `APP_BASE_URL`
     (the address the team uses, e.g. `http://192.168.1.10/ahpc`). Links in e-mails use it.
3. Open `http://localhost/ahpc/` and sign in with a user name (or e-mail) from `TEAM` in `config.php`.
   Everyone can change their password under **Password**.

On the first start (or when upgrading an older database) the app loads the **BOM library** from
`data/bom_library/` (the uploaded Excel / Zoho sheets): every BOM table in those files becomes an
"Uploaded BOM", the customers are taken from the files, and the component database is rebuilt from all
BOM lines. The earlier demo customers / components and the old Excel-imported BOMs are removed
(project BOMs, requirements and quotations are kept).

### Logins

| Name | User name | Role | Starting password |
|---|---|---|---|
| Praveen | praveen | Admin | Praveen@2026 |
| Saravana Kumar | saravanakumar | Admin | Saravana@2026 |
| Ravichandran | ravichandran | Admin | Ravi@2026 |
| Gurubalaji | gurubalaji | Engineer | Guru@2026 |
| Theepthithan | theepthithan | Engineer | Theep@2026 |
| Dinesh | dinesh | Engineer | Dinesh@2026 |
| Muthumadhan | muthumadhan | Engineer | Muthu@2026 |

Admins manage users on **Users & Emails** (add, edit, change role, disable, reset password, delete), assign
requirements / BOMs / quotations, add remarks and can delete BOMs, quotations, components, customers and
requirements. Engineers do the BOM and quotation work and add remarks / reasons for delay.

### What is where

* **Dashboard** - search box for customers, requirements, BOMs and quotations; pending work grouped by
  person (click a name to see only their work).
* **BOMs** - *Uploaded BOMs* (the library) and *Project BOMs*. Each BOM has **Use this BOM** (copy all lines
  into a new BOM for a customer / requirement), **Customize** (choose lines, change models / quantities /
  prices, add lines, then save as a new BOM) and **Open as new BOM** (instant editable copy). More sheets can
  be added with **Upload BOM sheets** (.xlsx).
* **Quotations** - same format as `templates_docx/quotation_template.docx`, filled from the BOM, editable,
  downloadable as **Word** and **PDF** (PDF uses LibreOffice when installed, otherwise the built-in PDF writer).
* **Analytics** - charts (Chart.js is included in `static/`, no internet needed).

PHP extensions used: `pdo_mysql`, `zip`, `dom`, `openssl`, `mbstring` (all enabled in XAMPP by default).

### Moving the data from the old Python version

```
php tools/import_sqlite.php C:\path\to\old\ahpc_bom\ahpc.db
```

This copies all customers, requirements, components, BOMs (all versions), BOM lines, quotations,
orders, remarks and the e-mail log with the same ids. The old version stored passwords in a format
PHP cannot read, so after the import every team member signs in with the starting password from
`TEAM` (printed by the script) and can change it.
Add `--force` to import over a database that already has BOMs/quotations (all tables are wiped first).

### Overdue reminders

While people use the app it checks once an hour (from 09:00) for overdue work and e-mails the
assignee + admins asking for the reason for delay (once per item per day). For reminders that do not
depend on someone opening the app, schedule `cron/reminders.php`:

* Linux: `0 9-18 * * *  php /var/www/html/ahpc/cron/reminders.php`
* Windows: Task Scheduler -> `C:\xampp\php\php.exe C:\xampp\htdocs\ahpc\cron\reminders.php`, hourly.

### PDF download

**Download PDF** always works. If LibreOffice is installed on the server, the Word quotation is converted
1:1 (set `SOFFICE_PATH` in `config.php` if `soffice` is not on the PATH, e.g.
`C:\Program Files\LibreOffice\program\soffice.exe`); otherwise the app draws the same 4-page layout itself.

### Other tasks

* Reset the `TEAM` logins to their starting passwords: `php tools/reset_passwords.php`
* An admin can also reset one person's password on **Users & Emails**, which also shows the e-mail log
  and a "send me a test e-mail" button.

## Files

| Path | What it is |
|---|---|
| `index.php` | Entry point and route table (`index.php?r=/boms/5` ...) |
| `config.php` | All settings: MySQL, Zoho Mail, team logins |
| `lib/core.php` | Database, install/seed, sessions, login, CSRF, formatting (Indian number format) |
| `lib/workflow.php` | Assignment, remarks thread, e-mail events, pending list, BOM totals, quotation helpers, reminders |
| `lib/pages.php` | One function per page / action |
| `lib/notify.php` | Zoho SMTP mailer (sent after the page is delivered) + e-mail log |
| `lib/quote_docx.php` | Fills `templates_docx/quotation_template.docx` (same output as before) |
| `lib/quote_pdf.php`, `lib/pdf.php` | Built-in PDF of the quotation (used when LibreOffice is missing) |
| `lib/bom_import.php` | Reads BOM sheets (.xlsx / Zoho HTML) into BOMs |
| `data/bom_library/` | The uploaded BOM sheets loaded on first start |
| `templates/` | HTML pages |
| `static/` | CSS and logo |
| `sql/schema.sql` | MySQL tables |
| `tools/`, `cron/` | Command-line scripts above |

The `.htaccess` files block web access to `config.php`, `lib/`, `templates/`, `sql/`, `tools/`, `data/`
and `cron/` on Apache. For uploads of large sheets, `upload_max_filesize` / `post_max_size` in php.ini
must allow them (XAMPP default 40M is fine). On nginx/IIS, deny those paths in the server configuration.
