<?php
/*
 * Daily overdue reminders: e-mails the assignee + admins about every pending item that is past
 * its due date and asks for the reason for delay (at most once per item per day).
 *
 * The app also runs this check by itself (once an hour, from 09:00) while people use it. For reliable
 * reminders add a cron job / Windows scheduled task, e.g. every hour from 9 to 18:
 *   0 9-18 * * *  php /var/www/html/ahpc/cron/reminders.php
 */
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../lib/workflow.php';

db();
q("REPLACE INTO app_meta (k, v) VALUES ('reminders_last_run', ?)", [(string)time()]);
$n = overdue_reminders();
echo date('Y-m-d H:i') . " - overdue reminders: $n item(s)\n";
