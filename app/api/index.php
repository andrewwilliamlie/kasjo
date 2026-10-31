<?php
// Kasjo API. Every call: /api/?a=<action>, JSON in, JSON out. Token in the X-Auth-Token header.
declare(strict_types=1);
require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $code, string $message, int $http = 400): never { out(['ok' => false, 'error' => $code, 'message' => $message], $http); }

set_exception_handler(function (Throwable $e) {
    error_log('Kasjo api: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok' => false, 'error' => 'server', 'message' => 'Terjadi kesalahan di server. Coba lagi.']);
});
ini_set('display_errors', '0');

if (!installed()) fail('not_installed', 'Server belum dipasang.', 503);

$action = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024 + 1);
    if ($raw === false || strlen($raw) > 4 * 1024 * 1024) fail('too_large', 'Data terlalu besar.', 413);
    $body = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) fail('bad_json', 'Format data tidak valid.');
}
$str = fn(string $k, int $max = 300): string => mb_substr(trim((string)($body[$k] ?? '')), 0, $max);
function need_post(string $m): void { if ($m !== 'POST') fail('method', 'POST only', 405); }

/* ---------- accounts and sessions ---------- */
function user_out(array $u): array {
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'phone' => $u['phone'], 'role' => $u['role'], 'mustChange' => (bool)$u['must_change']];
}
function toko_out(array $t): array {
    return ['id' => (int)$t['id'], 'name' => $t['name'], 'plan' => $t['plan'], 'trialUntil' => (int)$t['trial_until'], 'paidUntil' => (int)$t['paid_until']];
}
function load_toko(int $id): array { $st = db()->prepare('SELECT * FROM toko WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: []; }
function new_session(int $uid): string {
    $t = random_token(); $now = now_ms();
    db()->prepare('INSERT INTO sessions (token_hash, user_id, created_at, expires_at, last_seen, agent) VALUES (?,?,?,?,?,?)')
        ->execute([hash_token($t), $uid, $now, $now + SESSION_DAYS * 86400000, $now, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([$now, $uid]);
    return $t;
}
function auth(): array {
    $t = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) fail('auth', 'Silakan masuk lagi.', 401);
    $st = db()->prepare('SELECT s.expires_at, s.last_seen, u.*, t.disabled AS toko_disabled FROM sessions s JOIN users u ON u.id = s.user_id JOIN toko t ON t.id = u.toko_id WHERE s.token_hash = ?');
    $st->execute([hash_token($t)]);
    $row = $st->fetch();
    if (!$row || $row['expires_at'] < now_ms()) fail('auth', 'Sesi berakhir. Silakan masuk lagi.', 401);
    if ((int)$row['disabled'] === 1 || (int)$row['toko_disabled'] === 1) fail('disabled', 'Akun dinonaktifkan. Hubungi pemilik toko atau support Kasjo.', 403);
    if (now_ms() - (int)$row['last_seen'] > 86400000) {
        db()->prepare('UPDATE sessions SET last_seen = ?, expires_at = ? WHERE token_hash = ?')->execute([now_ms(), now_ms() + SESSION_DAYS * 86400000, hash_token($t)]);
    }
    $row['token_hash'] = hash_token($t);
    return $row;
}
function need_role(array $me, array $roles, string $msg = 'Hanya pemilik toko yang bisa melakukan ini.'): void {
    if (!in_array($me['role'], $roles, true)) fail('forbidden', $msg, 403);
}
function me_out(array $me): array { return ['user' => user_out($me), 'toko' => toko_out(load_toko((int)$me['toko_id']))]; }
function find_user_by_phone(string $phone): ?array { $st = db()->prepare('SELECT * FROM users WHERE phone = ?'); $st->execute([$phone]); return $st->fetch() ?: null; }
function read_phone(callable $str): string {
    $phone = norm_phone($str('phone', 30));
    if (!valid_phone($phone)) fail('phone', 'Nomor WhatsApp tidak valid. Contoh: 0812xxxxxxxx.');
    return $phone;
}

switch ($action) {

case 'config':
    out(['ok' => true, 'version' => APP_VERSION, 'supportWa' => config()['support_wa'] ?? '']);

case 'register': {
    need_post($method);
    $kIp = 'reg:' . client_ip();
    if (too_many($kIp, 10, 3600)) fail('rate', 'Terlalu banyak pendaftaran dari jaringan ini. Coba lagi nanti.', 429);
    $tokoName = $str('toko', 120); $name = $str('name', 80); $phone = read_phone($str); $pin = (string)($body['pin'] ?? '');
    if ($tokoName === '') fail('toko', 'Isi nama usaha.');
    if ($name === '') fail('name', 'Isi nama pemilik.');
    if (!valid_pin($pin)) fail('pin', 'PIN harus 6 angka dan tidak mudah ditebak (bukan 123456 atau 111111).');
    if (find_user_by_phone($phone)) fail('exists', 'Nomor ini sudah terdaftar. Silakan masuk.', 409);
    note_attempt($kIp);
    $now = now_ms(); $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO toko (name, created_at, trial_until) VALUES (?,?,?)')->execute([$tokoName, $now, $now + TRIAL_DAYS * 86400000]);
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (toko_id, phone, name, pin_hash, role, created_at) VALUES (?,?,?,?,?,?)')->execute([$tid, $phone, $name, password_hash($pin, PASSWORD_DEFAULT), 'owner', $now]);
    $uid = (int)$pdo->lastInsertId();
    audit($tid, $uid, 'daftar', null, null, 'Toko didaftarkan oleh ' . $name);
    $pdo->commit();
    $u = find_user_by_phone($phone);
    out(['ok' => true, 'token' => new_session($uid), 'me' => me_out($u)]);
}

case 'login': {
    need_post($method);
    $phone = read_phone($str); $pin = (string)($body['pin'] ?? '');
    $kId = 'login:' . $phone; $kIp = 'loginip:' . client_ip();
    if (too_many($kId, 5, 900) || too_many($kIp, 30, 900)) fail('rate', 'Terlalu banyak percobaan. Tunggu 15 menit lalu coba lagi.', 429);
    $u = find_user_by_phone($phone);
    if (!$u || !password_verify($pin, $u['pin_hash'])) { note_attempt($kId); note_attempt($kIp); fail('login', 'Nomor WhatsApp atau PIN salah.', 401); }
    $t = load_toko((int)$u['toko_id']);
    if ((int)$u['disabled'] === 1 || !$t || (int)$t['disabled'] === 1) fail('disabled', 'Akun dinonaktifkan. Hubungi pemilik toko atau support Kasjo.', 403);
    clear_attempts($kId);
    if (password_needs_rehash($u['pin_hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET pin_hash = ? WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $u['id']]);
    out(['ok' => true, 'token' => new_session((int)$u['id']), 'me' => me_out($u)]);
}

case 'me': { $me = auth(); out(['ok' => true, 'me' => me_out($me)]); }

case 'logout': {
    $me = auth();
    db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$me['token_hash']]);
    out(['ok' => true]);
}

case 'change_pin': {
    need_post($method);
    $me = auth();
    $cur = (string)($body['current'] ?? ''); $next = (string)($body['next'] ?? '');
    if (!$me['must_change'] && !password_verify($cur, $me['pin_hash'])) fail('current', 'PIN lama salah.', 401);
    if (!valid_pin($next)) fail('pin', 'PIN baru harus 6 angka dan tidak mudah ditebak.');
    if (password_verify($next, $me['pin_hash'])) fail('pin', 'PIN baru harus berbeda dari PIN lama.');
    db()->prepare('UPDATE users SET pin_hash = ?, must_change = 0 WHERE id = ?')->execute([password_hash($next, PASSWORD_DEFAULT), $me['id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?')->execute([$me['id'], $me['token_hash']]);
    audit((int)$me['toko_id'], (int)$me['id'], 'ganti_pin', null, null, $me['name'] . ' mengganti PIN');
    out(['ok' => true]);
}

case 'pull': {
    $me = auth(); $tid = (int)$me['toko_id'];
    $since = max(0, (int)($_GET['since'] ?? 0));
    $from = $since > 0 ? $since - 5000 : 0; // small overlap so nothing written mid-request is missed
    $sql = 'SELECT kind, id, data, updated_at, deleted FROM records WHERE toko_id = ? AND srv > ?' . ($from === 0 ? ' AND deleted = 0' : '') . ' ORDER BY srv';
    $st = db()->prepare($sql); $st->execute([$tid, $from]);
    $records = array_map(fn($r) => ['kind' => $r['kind'], 'id' => $r['id'], 'updatedAt' => (int)$r['updated_at'], 'deleted' => (bool)$r['deleted'], 'data' => $r['deleted'] ? null : json_decode($r['data'], true)], $st->fetchAll());
    $t = load_toko($tid);
    $res = ['ok' => true, 'now' => now_ms(), 'full' => $from === 0, 'records' => $records, 'me' => ['user' => user_out($me), 'toko' => toko_out($t)]];
    if ($from === 0 || (int)$t['settings_srv'] > $from) $res['settings'] = ['data' => $t['settings'] === null ? null : json_decode((string)$t['settings'], true), 'updatedAt' => (int)$t['settings_updated']];
    out($res);
}

case 'push': {
    need_post($method);
    $me = auth(); $tid = (int)$me['toko_id']; $uid = (int)$me['id']; $role = $me['role'];
    $srv = now_ms(); $accepted = []; $rejected = [];
    $recs = $body['records'] ?? [];
    if (!is_array($recs) || count($recs) > 500) fail('too_many', 'Terlalu banyak data sekaligus.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare('SELECT updated_at, deleted FROM records WHERE toko_id = ? AND kind = ? AND id = ?');
        $ins = $pdo->prepare('INSERT INTO records (toko_id, kind, id, data, updated_at, deleted, srv, ts, k, amount, profit, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $upd = $pdo->prepare('UPDATE records SET data = ?, updated_at = ?, deleted = ?, srv = ?, ts = ?, k = ?, amount = ?, profit = ?, updated_by = ? WHERE toko_id = ? AND kind = ? AND id = ?');
        foreach ($recs as $r) {
            if (!is_array($r)) continue;
            $kind = (string)($r['kind'] ?? ''); $id = (string)($r['id'] ?? ''); $ua = (int)($r['updatedAt'] ?? 0); $del = !empty($r['deleted']);
            if (!in_array($kind, KINDS, true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || $ua <= 0) continue;
            $d = $del ? [] : ($r['data'] ?? null);
            if (!$del && !is_array($d)) continue;
            $data = $del ? '{}' : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($data === false || strlen($data) > 65536) continue;
            $sel->execute([$tid, $kind, $id]); $cur = $sel->fetch();
            $exists = $cur !== false && !(int)$cur['deleted'];
            // the product list (prices, costs) is managed by owner and manager only
            if ($role === 'kasir' && $kind === 'product') { $rejected[] = ['kind' => $kind, 'id' => $id, 'reason' => 'role']; continue; }
            // kasir may only add new entries; changing or deleting needs owner or manager
            if ($role === 'kasir' && ($exists || $del)) { $rejected[] = ['kind' => $kind, 'id' => $id, 'reason' => 'role']; continue; }
            if ($cur !== false && $ua < (int)$cur['updated_at']) continue; // an older edit than the one stored
            $ts = $del ? null : (int)($d['ts'] ?? 0);
            $k = $kind === 'trx' && !$del ? mb_substr((string)($d['kind'] ?? ''), 0, 20) : null;
            $amount = $del ? null : (int)($d['amount'] ?? 0);
            $profit = $kind === 'trx' && !$del ? (int)($d['profit'] ?? 0) : null;
            if ($cur === false) $ins->execute([$tid, $kind, $id, $data, $ua, $del ? 1 : 0, $srv, $ts, $k, $amount, $profit, $uid, $uid]);
            else $upd->execute([$data, $ua, $del ? 1 : 0, $srv, $ts, $k, $amount, $profit, $uid, $tid, $kind, $id]);
            $accepted[] = ['kind' => $kind, 'id' => $id];
            if ($del) audit($tid, $uid, 'hapus', $kind, $id, 'Menghapus data ' . $kind);
            else audit($tid, $uid, $cur === false || (int)$cur['deleted'] ? 'catat' : 'ubah', $kind, $id, record_summary($kind, $d));
        }
        $settingsOk = null;
        if (isset($body['settings']) && is_array($body['settings'])) {
            if ($role === 'kasir') $settingsOk = false;
            else {
                $sd = $body['settings']['data'] ?? null; $ua = (int)($body['settings']['updatedAt'] ?? 0);
                $js = json_encode($sd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $t = load_toko($tid);
                if (is_array($sd) && $js !== false && strlen($js) <= 65536 && $ua >= (int)$t['settings_updated']) {
                    $name = mb_substr(trim((string)($sd['name'] ?? '')), 0, 120) ?: $t['name'];
                    $pdo->prepare('UPDATE toko SET settings = ?, settings_updated = ?, settings_srv = ?, name = ? WHERE id = ?')->execute([$js, $ua, $srv, $name, $tid]);
                    audit($tid, $uid, 'pengaturan', null, null, 'Mengubah pengaturan toko');
                }
                $settingsOk = true;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log('Kasjo push: ' . $e->getMessage()); fail('server', 'Gagal menyimpan ke server. Coba lagi.', 500); }
    out(['ok' => true, 'now' => now_ms(), 'accepted' => $accepted, 'rejected' => $rejected, 'settings' => $settingsOk]);
}

case 'users': {
    $me = auth(); need_role($me, ['owner']);
    $st = db()->prepare('SELECT id, name, phone, role, must_change, disabled, last_login, created_at FROM users WHERE toko_id = ? ORDER BY CASE role WHEN \'owner\' THEN 0 WHEN \'manager\' THEN 1 ELSE 2 END, name');
    $st->execute([$me['toko_id']]);
    out(['ok' => true, 'users' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['name'], 'phone' => $u['phone'], 'role' => $u['role'], 'mustChange' => (bool)$u['must_change'], 'disabled' => (bool)$u['disabled'], 'lastLogin' => $u['last_login'] ? (int)$u['last_login'] : null, 'me' => (int)$u['id'] === (int)$me['id']], $st->fetchAll())]);
}

case 'user_add': {
    need_post($method);
    $me = auth(); need_role($me, ['owner']);
    $name = $str('name', 80); $phone = read_phone($str); $role = (string)($body['role'] ?? 'kasir'); $pin = (string)($body['pin'] ?? '');
    if ($name === '') fail('name', 'Isi nama pengguna.');
    if (!in_array($role, ['manager', 'kasir'], true)) fail('role', 'Peran tidak valid.');
    if (!valid_pin($pin)) fail('pin', 'PIN sementara harus 6 angka dan tidak mudah ditebak.');
    $c = db()->prepare('SELECT COUNT(*) FROM users WHERE toko_id = ?'); $c->execute([$me['toko_id']]);
    if ((int)$c->fetchColumn() >= 20) fail('limit', 'Maksimal 20 pengguna per toko.');
    if (find_user_by_phone($phone)) fail('exists', 'Nomor ini sudah dipakai akun Kasjo lain.', 409);
    db()->prepare('INSERT INTO users (toko_id, phone, name, pin_hash, role, must_change, created_at) VALUES (?,?,?,?,?,1,?)')
        ->execute([$me['toko_id'], $phone, $name, password_hash($pin, PASSWORD_DEFAULT), $role, now_ms()]);
    audit((int)$me['toko_id'], (int)$me['id'], 'pengguna', null, null, 'Menambah ' . $role . ' ' . $name);
    out(['ok' => true]);
}

case 'user_update': {
    need_post($method);
    $me = auth(); need_role($me, ['owner']);
    $id = (int)($body['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND toko_id = ?'); $st->execute([$id, $me['toko_id']]); $u = $st->fetch();
    if (!$u) fail('not_found', 'Pengguna tidak ditemukan.', 404);
    if ((int)$u['id'] === (int)$me['id'] || $u['role'] === 'owner') fail('self', 'Akun pemilik tidak bisa diubah dari sini.');
    $tid = (int)$me['toko_id']; $mid = (int)$me['id'];
    if (isset($body['role'])) {
        $role = (string)$body['role'];
        if (!in_array($role, ['manager', 'kasir'], true)) fail('role', 'Peran tidak valid.');
        db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
        audit($tid, $mid, 'pengguna', null, null, 'Mengubah peran ' . $u['name'] . ' menjadi ' . $role);
    }
    if (isset($body['disabled'])) {
        $dis = $body['disabled'] ? 1 : 0;
        db()->prepare('UPDATE users SET disabled = ? WHERE id = ?')->execute([$dis, $id]);
        if ($dis) db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        audit($tid, $mid, 'pengguna', null, null, ($dis ? 'Menonaktifkan ' : 'Mengaktifkan ') . $u['name']);
    }
    if (isset($body['pin'])) {
        $pin = (string)$body['pin'];
        if (!valid_pin($pin)) fail('pin', 'PIN sementara harus 6 angka dan tidak mudah ditebak.');
        db()->prepare('UPDATE users SET pin_hash = ?, must_change = 1 WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
        db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        clear_attempts('login:' . $u['phone']);
        audit($tid, $mid, 'pengguna', null, null, 'Mereset PIN ' . $u['name']);
    }
    out(['ok' => true]);
}

case 'audit': {
    $me = auth(); need_role($me, ['owner', 'manager'], 'Hanya pemilik atau manajer yang bisa melihat riwayat aktivitas.');
    $st = db()->prepare('SELECT a.action, a.kind, a.summary, a.at, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.toko_id = ? ORDER BY a.at DESC, a.id DESC LIMIT 300');
    $st->execute([$me['toko_id']]);
    out(['ok' => true, 'items' => array_map(fn($r) => ['action' => $r['action'], 'summary' => $r['summary'], 'at' => (int)$r['at'], 'user' => $r['name'] ?? '-'], $st->fetchAll())]);
}

case 'wipe': {
    need_post($method);
    $me = auth(); need_role($me, ['owner']);
    if (!password_verify((string)($body['pin'] ?? ''), $me['pin_hash'])) fail('current', 'PIN salah.', 401);
    $tid = (int)$me['toko_id']; $pdo = db(); $pdo->beginTransaction();
    // mark as deleted (not removed) so the toko's other phones also clear their copy on next sync
    $now = now_ms();
    $pdo->prepare("UPDATE records SET deleted = 1, data = '{}', updated_at = ?, srv = ?, updated_by = ? WHERE toko_id = ? AND deleted = 0")->execute([$now, $now, (int)$me['id'], $tid]);
    $pdo->prepare('UPDATE toko SET settings = NULL, settings_updated = ?, settings_srv = ? WHERE id = ?')->execute([$now, $now, $tid]);
    audit($tid, (int)$me['id'], 'hapus_semua', null, null, 'Menghapus semua data toko');
    $pdo->commit();
    out(['ok' => true, 'now' => now_ms()]);
}

default:
    fail('unknown', 'Unknown action', 404);
}
