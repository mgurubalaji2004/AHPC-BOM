<?php
/* Set every team member back to the starting password listed in TEAM (config.php).
 *   php tools/reset_passwords.php
 */
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../lib/core.php';

reset_team_passwords(db());
foreach (TEAM as [$n, $u, $e, $r, $p]) {
    printf("   %-15s %-14s %-9s %-30s %s\n", $n, $u, $r, $e, $p);
}
echo " All " . count(TEAM) . " passwords have been reset to the values above.\n";
