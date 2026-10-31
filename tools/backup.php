<?php
// Kasjo nightly database backup. Run from cron only (blocked from the web by .htaccess and the CLI check below):
//   php /home/u283956056/domains/kasjo.site/public_html/tools/backup.php
// Writes a gzipped SQL file to domains/kasjo.site/backups/ (outside public_html) and keeps the last KEEP_DAYS days.
// Restore: phpMyAdmin → u283956056_kasjo → Import → choose the .sql.gz file (see README).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/api/_lib.php';

const KEEP_DAYS = 14;
const BATCH = 200;

if (!installed()) { fwrite(STDERR, "Kasjo is not installed\n"); exit(1); }
$dir = getenv('KASJO_BACKUP_DIR') ?: dirname(config_path()) . '/backups';
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { fwrite(STDERR, "Cannot create $dir\n"); exit(1); }

// With --if-due (used by the hourly cron, so the server's own timezone does not matter):
// back up in the 02:00 hour Jakarta time, or catch up when the newest backup is over 26 hours old.
if (in_array('--if-due', $argv ?? [], true)) {
    $latest = 0;
    foreach (glob($dir . '/kasjo-*.sql.gz') ?: [] as $f) $latest = max($latest, (int)filemtime($f));
    $age = time() - $latest;
    $due = ((int)date('G') === 2 && $age > 2 * 3600) || $age > 26 * 3600;
    if (!$due) exit(0);
}

$pdo = db();
$driver = config()['db']['driver'] ?? 'mysql';
$tables = ['toko', 'users', 'sessions', 'records', 'audit_log', 'attempts'];
$file = $dir . '/kasjo-' . date('Ymd-His') . '.sql.gz';
$tmp = $file . '.part';
$gz = gzopen($tmp, 'wb6');
if (!$gz) { fwrite(STDERR, "Cannot write $tmp\n"); exit(1); }
$q = fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v));

gzwrite($gz, "-- Kasjo database backup " . date('c') . " (app " . APP_VERSION . ")\n");
gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
$counts = [];
foreach ($tables as $t) {
    if ($driver === 'mysql') {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
    }
    $n = 0; $buf = [];
    $st = $pdo->query("SELECT * FROM $t");
    $st->setFetchMode(PDO::FETCH_ASSOC);
    $cols = null;
    foreach ($st as $row) {
        if ($cols === null) $cols = '(`' . implode('`,`', array_keys($row)) . '`)';
        $buf[] = '(' . implode(',', array_map($q, array_values($row))) . ')';
        $n++;
        if (count($buf) >= BATCH) { gzwrite($gz, "INSERT INTO `$t` $cols VALUES\n" . implode(",\n", $buf) . ";\n"); $buf = []; }
    }
    if ($buf) gzwrite($gz, "INSERT INTO `$t` $cols VALUES\n" . implode(",\n", $buf) . ";\n");
    gzwrite($gz, "\n");
    $counts[] = "$t=$n";
}
gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n");
gzclose($gz);
rename($tmp, $file);
@chmod($file, 0600);

// keep the last KEEP_DAYS days
$removed = 0;
foreach (glob($dir . '/kasjo-*.sql.gz') ?: [] as $old) {
    if (filemtime($old) < time() - KEEP_DAYS * 86400) { @unlink($old); $removed++; }
}
foreach (glob($dir . '/*.part') ?: [] as $p) if (filemtime($p) < time() - 86400) @unlink($p);

echo date('c') . ' backup ' . basename($file) . ' ' . round(filesize($file) / 1024) . " KB, " . implode(' ', $counts) . ", removed $removed old\n";
