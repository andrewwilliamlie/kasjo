<?php
// Kasjo support admin (tim Hausjo): find a toko, reset a forgotten PIN, disable a user or a toko.
declare(strict_types=1);
require __DIR__ . '/api/_lib.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");

if (!installed()) { http_response_code(503); exit('Belum dipasang. Buka install.php.'); }
session_name('kasjo_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$msg = ''; $err = ''; $temp = null;
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$csrfOk = $post && hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''));

if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }

if (empty($_SESSION['admin'])) {
    if ($post) {
        $k = 'admin:' . client_ip();
        if (too_many($k, 5, 900)) $err = 'Terlalu banyak percobaan. Tunggu 15 menit.';
        elseif (password_verify((string)($_POST['pass'] ?? ''), (string)(config()['admin_hash'] ?? ''))) {
            session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(16)); $_SESSION['at'] = time();
            clear_attempts($k); header('Location: admin.php'); exit;
        } else { note_attempt($k); $err = 'Kata sandi salah.'; }
    }
} else {
    if (time() - (int)($_SESSION['at'] ?? 0) > 3600) { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }
    $_SESSION['at'] = time();
    if ($post && !$csrfOk) $err = 'Sesi formulir kedaluwarsa. Muat ulang halaman.';
    elseif ($post) {
        $do = (string)($_POST['do'] ?? ''); $id = (int)($_POST['id'] ?? 0);
        if ($do === 'reset' || $do === 'user') {
            $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]); $u = $st->fetch();
            if (!$u) $err = 'Pengguna tidak ditemukan.';
            elseif ($do === 'reset') {
                $pin = random_pin();
                db()->prepare('UPDATE users SET pin_hash = ?, must_change = 1 WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
                db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
                clear_attempts('login:' . $u['phone']);
                audit((int)$u['toko_id'], null, 'pengguna', null, null, 'Support Kasjo mereset PIN ' . $u['name']);
                $temp = ['who' => $u['name'] . ' (' . $u['phone'] . ')', 'pin' => $pin];
            } else {
                $nd = (int)$u['disabled'] ? 0 : 1;
                db()->prepare('UPDATE users SET disabled = ? WHERE id = ?')->execute([$nd, $id]);
                if ($nd) db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
                $msg = $nd ? $u['name'] . ' dinonaktifkan dan dikeluarkan dari semua HP.' : $u['name'] . ' diaktifkan lagi.';
            }
        } elseif ($do === 'toko') {
            $st = db()->prepare('SELECT * FROM toko WHERE id = ?'); $st->execute([$id]); $t = $st->fetch();
            if (!$t) $err = 'Toko tidak ditemukan.';
            else {
                $nd = (int)$t['disabled'] ? 0 : 1;
                db()->prepare('UPDATE toko SET disabled = ? WHERE id = ?')->execute([$nd, $id]);
                if ($nd) db()->prepare('DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE toko_id = ?)')->execute([$id]);
                $msg = $nd ? 'Toko ' . $t['name'] . ' dinonaktifkan.' : 'Toko ' . $t['name'] . ' diaktifkan lagi.';
            }
        }
    }
}
$q = trim((string)($_GET['q'] ?? ''));
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Admin Kasjo</title>
<style>
:root{--bg:#f4f8f7;--card:#fff;--ink:#1d2a29;--muted:#6b7b79;--line:#e1e9e8;--teal:#0e7c73;--red:#e0483e;--ok:#0e9b8b;--warn:#c77a12}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Roboto,system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:980px;margin:0 auto;padding:24px 16px 60px}header{display:flex;justify-content:space-between;align-items:center;gap:12px}
h1{font-size:20px;margin:0}a{color:var(--teal)}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-top:14px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.stats div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}
.stats b{display:block;font-size:24px;font-variant-numeric:tabular-nums}.stats small{color:var(--muted)}
input{padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;width:100%}form.inline{display:inline}
button{background:var(--teal);color:#fff;border:0;border-radius:10px;padding:7px 12px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}button.sec{background:#eef3f2;color:var(--ink)}button.danger{background:var(--red)}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted);font-weight:600}
.scroll{overflow-x:auto}.err{background:#fdeceb;color:var(--red);padding:12px;border-radius:10px;margin-top:14px}.ok{background:#e0f4f1;color:var(--ok);padding:12px;border-radius:10px;margin-top:14px}
.temp{background:#fef3e1;color:var(--warn);padding:14px;border-radius:10px;margin-top:14px}.temp code{font-size:22px;font-weight:800;letter-spacing:.1em;color:var(--ink)}
.pill{font-size:12px;font-weight:700;padding:2px 8px;border-radius:99px;background:#e0f4f1;color:var(--ok)}.pill.off{background:#fdeceb;color:var(--red)}
.u{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:4px 0}.login{max-width:360px;margin:60px auto}
</style></head><body><main>
<?php if (empty($_SESSION['admin'])): ?>
  <div class="login card"><h1>Admin Kasjo</h1>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <form method="post"><p><label for="pass">Kata sandi admin</label><br><input id="pass" name="pass" type="password" required autofocus></p><button type="submit">Masuk</button></form></div>
<?php else:
    $c = function (string $sql, array $a = []): int { $s = db()->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); };
    $monthStart = (int)(strtotime(date('Y-m-01 00:00:00')) * 1000);
    $stats = [
        'Toko' => $c('SELECT COUNT(*) FROM toko'),
        'Pengguna' => $c('SELECT COUNT(*) FROM users'),
        'Aktif 30 hari' => $c('SELECT COUNT(DISTINCT toko_id) FROM users WHERE last_login > ?', [now_ms() - 30 * 86400000]),
        'Transaksi bulan ini' => $c("SELECT COUNT(*) FROM records WHERE kind = 'trx' AND deleted = 0 AND ts >= ?", [$monthStart]),
    ];
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)) . '%';
        $ph = '%' . (norm_phone($q) ?: '#') . '%';
        $st = db()->prepare('SELECT DISTINCT t.* FROM toko t LEFT JOIN users u ON u.toko_id = t.id WHERE LOWER(t.name) LIKE ? OR u.phone LIKE ? OR LOWER(u.name) LIKE ? ORDER BY t.id DESC LIMIT 50');
        $st->execute([$like, $ph, $like]);
    } else {
        $st = db()->query('SELECT * FROM toko ORDER BY id DESC LIMIT 20');
    }
    $tokos = $st->fetchAll();
    $usersOf = db()->prepare('SELECT * FROM users WHERE toko_id = ? ORDER BY CASE role WHEN \'owner\' THEN 0 WHEN \'manager\' THEN 1 ELSE 2 END, name');
    $trxOf = db()->prepare("SELECT COUNT(*) FROM records WHERE toko_id = ? AND kind = 'trx' AND deleted = 0");
    $fmt = fn($ms) => $ms ? date('d M Y H:i', (int)($ms / 1000)) : '-';
    $csrf = h($_SESSION['csrf']);
?>
  <header><h1>Admin Kasjo</h1><a href="?logout=1">Keluar</a></header>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
  <?php if ($temp): ?><div class="temp">PIN sementara untuk <b><?= h($temp['who']) ?></b>:<br><code><?= h($temp['pin']) ?></code><br>
    Kirim lewat WhatsApp ke nomor yang terdaftar. Pengguna wajib menggantinya saat masuk. PIN ini hanya tampil sekali.</div><?php endif; ?>
  <section class="stats" style="margin-top:14px"><?php foreach ($stats as $k => $n): ?><div><b><?= number_format($n, 0, ',', '.') ?></b><small><?= h($k) ?></small></div><?php endforeach; ?></section>
  <section class="card"><form method="get"><label for="q">Cari toko (nama toko, nama orang, atau nomor WhatsApp)</label>
    <div style="display:flex;gap:8px;margin-top:6px"><input id="q" name="q" value="<?= h($q) ?>" placeholder="0812xxxx atau nama toko"><button type="submit">Cari</button></div></form>
  <div class="scroll" style="margin-top:12px"><table><thead><tr><th>Toko</th><th>Pengguna</th><th>Transaksi</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($tokos as $t): $usersOf->execute([$t['id']]); $us = $usersOf->fetchAll(); $trxOf->execute([$t['id']]); ?>
    <tr><td><b><?= h($t['name']) ?></b><br><small>Daftar <?= h($fmt($t['created_at'])) ?> · uji coba s/d <?= h(date('d M Y', (int)($t['trial_until'] / 1000))) ?></small></td>
      <td><?php foreach ($us as $u): ?><div class="u"><span><b><?= h($u['name']) ?></b> · <?= h($u['role']) ?><br><small><?= h($u['phone']) ?> · masuk <?= h($fmt($u['last_login'])) ?></small></span>
        <?php if ($u['disabled']): ?><span class="pill off">Nonaktif</span><?php endif; ?>
        <form class="inline" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="do" value="reset"><button type="submit">Reset PIN</button></form>
        <form class="inline" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="do" value="user"><button type="submit" class="sec"><?= $u['disabled'] ? 'Aktifkan' : 'Nonaktifkan' ?></button></form></div>
      <?php endforeach; ?></td>
      <td><?= number_format((int)$trxOf->fetchColumn(), 0, ',', '.') ?></td>
      <td><span class="pill <?= $t['disabled'] ? 'off' : '' ?>"><?= $t['disabled'] ? 'Nonaktif' : 'Aktif' ?></span><br>
        <form class="inline" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="do" value="toko"><button type="submit" class="<?= $t['disabled'] ? 'sec' : 'danger' ?>" style="margin-top:6px"><?= $t['disabled'] ? 'Aktifkan toko' : 'Nonaktifkan toko' ?></button></form></td></tr>
  <?php endforeach; if (!$tokos): ?><tr><td colspan="4">Tidak ada toko yang cocok.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p style="color:var(--muted);font-size:13px">Sebelum reset PIN, pastikan yang meminta benar orangnya, misalnya chat dari nomor WhatsApp yang terdaftar.</p></section>
<?php endif; ?>
</main></body></html>
