<?php
// Kasjo: shared backend code. Not reachable from the web (blocked in api/.htaccess).
declare(strict_types=1);

const APP_VERSION = '1.6.0';
const SCHEMA_VERSION = '2'; // bump when tables are added; migrate() creates them on the next request
const SESSION_DAYS = 90;
const TRIAL_DAYS = 30;
const KINDS = ['trx', 'kasbon', 'closing', 'product', 'qris'];
const ROLES = ['owner', 'manager', 'kasir'];
const MAX_USERS = 3;
const DEFAULT_PAY_WA = '6285596301230'; // admin WhatsApp for payment confirmations; changeable in admin.php // active users per toko, owner included
// subscription plans: price in rupiah, days added
const PLANS = [
    '1m'  => ['label' => '1 bulan', 'days' => 30,  'price' => 29000],
    '3m'  => ['label' => '3 bulan', 'days' => 90,  'price' => 79000],
    '12m' => ['label' => '1 tahun', 'days' => 365, 'price' => 249000],
];
date_default_timezone_set('Asia/Jakarta');

/* ---------- config ---------- */
// The config file sits next to public_html (never inside it), e.g.
// /home/u283956056/domains/kasjo.site/kasjo-config.php
function config_path(): string {
    $env = getenv('KASJO_CONFIG');
    if ($env) return $env;
    $d = __DIR__;
    while ($d !== '/' && $d !== '' && basename($d) !== 'public_html') $d = dirname($d);
    if (basename($d) !== 'public_html') $d = dirname(__DIR__, 2); // repo root when run outside Hostinger
    return dirname($d) . '/kasjo-config.php';
}
function config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $p = config_path();
        $cfg = is_file($p) ? (require $p) : [];
    }
    return $cfg;
}
function installed(): bool { return !empty(config()['db']); }

/* ---------- database ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = db_connect(config()['db']);
    return $pdo;
}
function db_connect(array $c): PDO {
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path'], null, null, $opt);
        $pdo->exec('PRAGMA journal_mode=WAL');
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? '127.0.0.1', (int)($c['port'] ?? 3306), $c['name']);
    return new PDO($dsn, $c['user'], $c['pass'], $opt);
}

function schema(string $driver): array {
    if ($driver === 'sqlite') { $ai = 'INTEGER PRIMARY KEY AUTOINCREMENT'; $tail = ''; $txt = 'TEXT'; $u = ''; }
    else { $ai = 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'; $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'; $txt = 'MEDIUMTEXT'; $u = ' UNSIGNED'; }
    return [
        // one row per agent business
        "CREATE TABLE IF NOT EXISTS toko (
            id $ai,
            name VARCHAR(120) NOT NULL,
            settings $txt NULL,
            settings_updated BIGINT NOT NULL DEFAULT 0,
            settings_srv BIGINT NOT NULL DEFAULT 0,
            plan VARCHAR(20) NOT NULL DEFAULT 'trial',
            trial_until BIGINT NOT NULL DEFAULT 0,
            paid_until BIGINT NOT NULL DEFAULT 0,
            disabled TINYINT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL
        )$tail",
        // people who log in: owner, manager, kasir
        "CREATE TABLE IF NOT EXISTS users (
            id $ai,
            toko_id INT$u NOT NULL,
            phone VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            pin_hash VARCHAR(255) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'kasir',
            must_change TINYINT NOT NULL DEFAULT 0,
            disabled TINYINT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            last_login BIGINT NULL
        )$tail",
        "CREATE INDEX idx_users_toko ON users (toko_id)",
        "CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT$u NOT NULL,
            created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, last_seen BIGINT NOT NULL,
            agent VARCHAR(200) NULL
        )$tail",
        "CREATE INDEX idx_sessions_user ON sessions (user_id)",
        // transactions, kasbon and tutup kasir. The app's own record is kept in `data`;
        // the columns next to it are copies used for reports and the admin page.
        "CREATE TABLE IF NOT EXISTS records (
            toko_id INT$u NOT NULL,
            kind VARCHAR(10) NOT NULL,
            id VARCHAR(64) NOT NULL,
            data $txt NOT NULL,
            updated_at BIGINT NOT NULL,
            deleted TINYINT NOT NULL DEFAULT 0,
            srv BIGINT NOT NULL,
            ts BIGINT NULL,
            k VARCHAR(20) NULL,
            amount BIGINT NULL,
            profit BIGINT NULL,
            created_by INT$u NULL,
            updated_by INT$u NULL,
            PRIMARY KEY (toko_id, kind, id)
        )$tail",
        "CREATE INDEX idx_records_sync ON records (toko_id, srv)",
        "CREATE INDEX idx_records_ts ON records (toko_id, kind, ts)",
        // who did what, for owners and disputes
        "CREATE TABLE IF NOT EXISTS audit_log (
            id $ai,
            toko_id INT$u NOT NULL,
            user_id INT$u NULL,
            action VARCHAR(20) NOT NULL,
            kind VARCHAR(10) NULL,
            ref_id VARCHAR(64) NULL,
            summary VARCHAR(300) NOT NULL,
            at BIGINT NOT NULL
        )$tail",
        "CREATE INDEX idx_audit_toko ON audit_log (toko_id, at)",
        // subscription payments: the agent pays to Hausjo's QRIS, uploads proof, support approves
        "CREATE TABLE IF NOT EXISTS payments (
            id $ai,
            toko_id INT$u NOT NULL,
            user_id INT$u NULL,
            plan VARCHAR(5) NOT NULL,
            days INT NOT NULL,
            amount INT NOT NULL,
            code INT NOT NULL DEFAULT 0,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            proof $txt NULL,
            note VARCHAR(300) NULL,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            decided_at BIGINT NULL
        )$tail",
        "CREATE INDEX idx_payments_status ON payments (status, created_at)",
        "CREATE INDEX idx_payments_toko ON payments (toko_id, created_at)",
        "CREATE TABLE IF NOT EXISTS app_settings (
            k VARCHAR(40) NOT NULL PRIMARY KEY,
            v $txt NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS attempts (
            id $ai,
            k VARCHAR(190) NOT NULL,
            at BIGINT NOT NULL
        )$tail",
        "CREATE INDEX idx_attempts ON attempts (k, at)",
    ];
}
function create_schema(PDO $pdo, string $driver): void {
    foreach (schema($driver) as $sql) {
        try { $pdo->exec($sql); }
        catch (PDOException $e) {
            if (stripos($sql, 'CREATE INDEX') === 0) continue; // already there on a re-run
            throw $e;
        }
    }
}

// Runs the (idempotent) schema once per SCHEMA_VERSION, so new tables appear after a deploy without reinstalling.
function migrate(): void {
    $marker = dirname(config_path()) . '/.kasjo-schema';
    if (@file_get_contents($marker) === SCHEMA_VERSION) return;
    create_schema(db(), config()['db']['driver'] ?? 'mysql');
    @file_put_contents($marker, SCHEMA_VERSION, LOCK_EX);
}
function app_setting(string $k): ?string { $st = db()->prepare('SELECT v FROM app_settings WHERE k = ?'); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? null : $v; }
function set_app_setting(string $k, ?string $v): void {
    db()->prepare('DELETE FROM app_settings WHERE k = ?')->execute([$k]);
    if ($v !== null) db()->prepare('INSERT INTO app_settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

/* ---------- subscription ---------- */
// Active until the later of the trial end and the paid end. 'paid' once a payment has been approved and not yet run out.
function toko_status(array $t): array {
    $now = now_ms(); $trial = (int)$t['trial_until']; $paid = (int)$t['paid_until']; $until = max($trial, $paid);
    $status = $until <= $now ? 'expired' : ($paid > $now ? 'paid' : 'trial');
    return ['active' => $until > $now, 'until' => $until, 'status' => $status, 'daysLeft' => $until > $now ? (int)ceil(($until - $now) / 86400000) : 0];
}
// Extends from the end of the current period (or today if already expired), so paying early loses nothing.
function approve_payment(int $id, string $note = ''): ?array {
    $pdo = db(); $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?'); $st->execute([$id]); $p = $st->fetch();
    if (!$p || !in_array($p['status'], ['pending', 'review'], true)) { $pdo->rollBack(); return null; }
    $t = load_toko_row((int)$p['toko_id']);
    $base = max(now_ms(), (int)$t['trial_until'], (int)$t['paid_until']);
    $newUntil = $base + (int)$p['days'] * 86400000;
    $pdo->prepare('UPDATE toko SET paid_until = ?, plan = ? WHERE id = ?')->execute([$newUntil, $p['plan'], $p['toko_id']]);
    $pdo->prepare("UPDATE payments SET status = 'approved', note = ?, decided_at = ?, updated_at = ? WHERE id = ?")->execute([mb_substr($note, 0, 300) ?: null, now_ms(), now_ms(), $id]);
    $pdo->commit();
    audit((int)$p['toko_id'], null, 'langganan', null, null, 'Pembayaran ' . (PLANS[$p['plan']]['label'] ?? $p['plan']) . ' ' . rupiah($p['amount']) . ' dikonfirmasi, aktif s/d ' . date('d M Y', intdiv($newUntil, 1000)));
    return ['until' => $newUntil];
}
// Account deletion (Google Play policy): removes a toko and everything in it. Subscription payments stay for
// Hausjo's bookkeeping and tax, without the proof picture or any link to a person. Call inside a transaction.
function delete_toko_data(PDO $pdo, int $tid): void {
    foreach (['DELETE FROM records WHERE toko_id = ?', 'DELETE FROM audit_log WHERE toko_id = ?',
              'DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE toko_id = ?)', 'DELETE FROM users WHERE toko_id = ?'] as $q) $pdo->prepare($q)->execute([$tid]);
    $pdo->prepare('UPDATE payments SET proof = NULL, user_id = NULL WHERE toko_id = ?')->execute([$tid]);
    $pdo->prepare("UPDATE toko SET name = '(akun dihapus)', settings = NULL, settings_updated = 0, disabled = 1 WHERE id = ?")->execute([$tid]);
}
function load_toko_row(int $id): array { $st = db()->prepare('SELECT * FROM toko WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: []; }

/* ---------- helpers ---------- */
function now_ms(): int { return (int)floor(microtime(true) * 1000); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 64); }
function norm_phone(string $p): string {
    $d = preg_replace('/\D/', '', $p) ?? '';
    if (str_starts_with($d, '0')) $d = '62' . substr($d, 1);
    elseif (str_starts_with($d, '8')) $d = '62' . $d;
    return $d;
}
function valid_phone(string $d): bool { return (bool)preg_match('/^628\d{7,12}$/', $d); }
function valid_pin(string $p): bool {
    if (!preg_match('/^\d{6}$/', $p)) return false;
    $weak = ['000000','111111','222222','333333','444444','555555','666666','777777','888888','999999','123456','654321','123123','112233','121212'];
    return !in_array($p, $weak, true);
}
function random_pin(): string { do { $p = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); } while (!valid_pin($p)); return $p; }
function random_token(): string { return bin2hex(random_bytes(32)); }
function hash_token(string $t): string { return hash('sha256', $t); }
function rupiah($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }

/* ---------- rate limiting (failed attempts only) ---------- */
function too_many(string $k, int $limit, int $windowSec): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM attempts WHERE k = ? AND at > ?');
    $st->execute([$k, now_ms() - $windowSec * 1000]);
    return (int)$st->fetchColumn() >= $limit;
}
function note_attempt(string $k): void {
    db()->prepare('INSERT INTO attempts (k, at) VALUES (?, ?)')->execute([$k, now_ms()]);
    if (random_int(1, 50) === 1) db()->prepare('DELETE FROM attempts WHERE at < ?')->execute([now_ms() - 86400000]);
}
function clear_attempts(string $k): void { db()->prepare('DELETE FROM attempts WHERE k = ?')->execute([$k]); }

/* ---------- activity log ---------- */
const KIND_LABEL = ['tarik' => 'Tarik Tunai', 'transfer' => 'Transfer', 'setor' => 'Setor Tunai', 'topup' => 'Top Up E-Wallet', 'tagihan' => 'Bayar Tagihan',
    'jual' => 'Penjualan Toko', 'stok' => 'Barang Masuk', 'stokadj' => 'Penyesuaian Stok', 'modal' => 'Modal Masuk', 'ambil' => 'Ambil Uang', 'pindah' => 'Pindah Saldo', 'biaya' => 'Biaya Operasional', 'kasbon' => 'Kasbon', 'bayarkasbon' => 'Bayar Kasbon', 'selisih' => 'Selisih Kas'];
function record_summary(string $kind, array $d): string {
    if ($kind === 'trx') {
        $s = (KIND_LABEL[$d['kind'] ?? ''] ?? 'Transaksi') . ' ' . rupiah($d['amount'] ?? 0);
        if (!empty($d['items']) && is_array($d['items'])) {
            $names = array_map(fn($i) => (int)($i['qty'] ?? 0) . 'x ' . mb_substr((string)($i['name'] ?? ''), 0, 40), array_slice($d['items'], 0, 3));
            $s .= ' · ' . implode(', ', $names) . (count($d['items']) > 3 ? ' …' : '');
        }
        if (!empty($d['cust'])) $s .= ' · ' . mb_substr((string)$d['cust'], 0, 60);
        return $s;
    }
    if ($kind === 'qris') return 'Gambar QRIS toko diperbarui';
    if ($kind === 'product') return 'Produk ' . mb_substr((string)($d['name'] ?? ''), 0, 60) . ' · harga ' . rupiah($d['price'] ?? 0);
    if ($kind === 'kasbon') return 'Pelanggan kasbon ' . mb_substr((string)($d['name'] ?? ''), 0, 60);
    if ($kind === 'closing') return 'Tutup kasir, selisih ' . rupiah($d['diff'] ?? 0);
    return $kind;
}
function audit(int $tokoId, ?int $userId, string $action, ?string $kind, ?string $ref, string $summary): void {
    db()->prepare('INSERT INTO audit_log (toko_id, user_id, action, kind, ref_id, summary, at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$tokoId, $userId, $action, $kind, $ref, mb_substr($summary, 0, 300), now_ms()]);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
