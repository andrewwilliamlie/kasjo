<?php
// Kasjo support admin (tim Hausjo): confirm subscription payments, find a toko, reset a PIN, disable a user or toko,
// set the QRIS that agents pay their subscription to.
declare(strict_types=1);
require __DIR__ . '/api/_lib.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'");

if (!installed()) { http_response_code(503); exit('Belum dipasang. Buka install.php.'); }
migrate();
session_name('kasjo_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$msg = ''; $err = ''; $temp = null;
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$csrfOk = $post && hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''));
$tab = in_array($_GET['tab'] ?? '', ['bayar', 'toko', 'atur'], true) ? $_GET['tab'] : 'bayar';

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
        $do = (string)($_POST['do'] ?? ''); $id = (int)($_POST['id'] ?? 0); $note = trim((string)($_POST['note'] ?? ''));
        if ($do === 'approve') {
            $r = approve_payment($id, $note);
            $msg = $r ? 'Pembayaran dikonfirmasi. Toko aktif s/d ' . date('d M Y', intdiv($r['until'], 1000)) . '.' : '';
            if (!$r) $err = 'Pembayaran tidak ditemukan atau sudah diproses.';
        } elseif ($do === 'reject') {
            $st = db()->prepare("UPDATE payments SET status = 'rejected', note = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','review')");
            $st->execute([mb_substr($note ?: 'Pembayaran tidak ditemukan', 0, 300), now_ms(), now_ms(), $id]);
            $msg = $st->rowCount() ? 'Pembayaran ditolak. Pemilik toko melihat alasannya di aplikasi.' : '';
            if (!$st->rowCount()) $err = 'Pembayaran tidak ditemukan atau sudah diproses.';
        } elseif ($do === 'extend') {
            $days = max(1, min(400, (int)($_POST['days'] ?? 30)));
            $t = load_toko_row($id);
            if (!$t) $err = 'Toko tidak ditemukan.';
            else {
                $now = now_ms();
                db()->prepare("INSERT INTO payments (toko_id, user_id, plan, days, amount, code, status, note, created_at, updated_at, decided_at) VALUES (?,NULL,'manual',?,0,0,'pending',?,?,?,NULL)")
                    ->execute([$id, $days, $note ?: 'Perpanjangan manual oleh support', $now, $now]);
                $r = approve_payment((int)db()->lastInsertId(), $note ?: 'Perpanjangan manual oleh support');
                $msg = 'Toko ' . $t['name'] . ' diperpanjang ' . $days . ' hari, aktif s/d ' . date('d M Y', intdiv($r['until'], 1000)) . '.';
            }
        } elseif ($do === 'reset' || $do === 'user') {
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
            $t = load_toko_row($id);
            if (!$t) $err = 'Toko tidak ditemukan.';
            else {
                $nd = (int)$t['disabled'] ? 0 : 1;
                db()->prepare('UPDATE toko SET disabled = ? WHERE id = ?')->execute([$nd, $id]);
                if ($nd) db()->prepare('DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE toko_id = ?)')->execute([$id]);
                $msg = $nd ? 'Toko ' . $t['name'] . ' dinonaktifkan.' : 'Toko ' . $t['name'] . ' diaktifkan lagi.';
            }
        } elseif ($do === 'settings') {
            set_app_setting('pay_bank', mb_substr(trim((string)($_POST['pay_bank'] ?? '')), 0, 500) ?: null);
            $wa = norm_phone((string)($_POST['pay_wa'] ?? ''));
            if ($wa !== '' && !valid_phone($wa)) $err = 'Nomor WhatsApp konfirmasi tidak valid.';
            else set_app_setting('pay_wa', $wa ?: null);
            $f = $_FILES['pay_qris'] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $info = @getimagesize($f['tmp_name']);
                if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) $err = 'File QRIS harus gambar JPG, PNG atau WEBP.';
                elseif ($f['size'] > 1500000) $err = 'Gambar QRIS maksimal 1,5 MB. Kecilkan dulu atau pakai tangkapan layar.';
                else set_app_setting('pay_qris', 'data:' . $info['mime'] . ';base64,' . base64_encode((string)file_get_contents($f['tmp_name'])));
            }
            if (!empty($_POST['del_qris'])) set_app_setting('pay_qris', null);
            if (!$err) $msg = 'Pengaturan pembayaran disimpan.';
            $tab = 'atur';
        }
    }
}
$q = trim((string)($_GET['q'] ?? ''));
$fmt = fn($ms) => $ms ? date('d M Y H:i', (int)($ms / 1000)) : '-';
$fmtD = fn($ms) => $ms ? date('d M Y', (int)($ms / 1000)) : '-';
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Admin Kasjo</title>
<style>
:root{--bg:#f4f8f7;--card:#fff;--ink:#1d2a29;--muted:#6b7b79;--line:#e1e9e8;--teal:#0e7c73;--red:#e0483e;--ok:#0e9b8b;--warn:#c77a12}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Roboto,system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:1040px;margin:0 auto;padding:24px 16px 60px}header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
h1{font-size:20px;margin:0}h2{font-size:17px;margin:22px 0 4px}a{color:var(--teal)}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-top:14px}
nav.tabs{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap}nav.tabs a{padding:8px 14px;border-radius:10px;text-decoration:none;color:var(--ink);background:#e8efee;font-weight:600}nav.tabs a.on{background:var(--teal);color:#fff}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:14px}.stats div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}
.stats b{display:block;font-size:22px;font-variant-numeric:tabular-nums}.stats small{color:var(--muted)}
input,textarea{padding:9px 11px;border:1px solid var(--line);border-radius:10px;font:inherit;width:100%}form.inline{display:inline}
button{background:var(--teal);color:#fff;border:0;border-radius:10px;padding:8px 13px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}button.sec{background:#eef3f2;color:var(--ink)}button.danger{background:var(--red)}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted);font-weight:600}
.scroll{overflow-x:auto}.err{background:#fdeceb;color:var(--red);padding:12px;border-radius:10px;margin-top:14px}.ok{background:#e0f4f1;color:var(--ok);padding:12px;border-radius:10px;margin-top:14px}
.temp{background:#fef3e1;color:var(--warn);padding:14px;border-radius:10px;margin-top:14px}.temp code{font-size:22px;font-weight:800;letter-spacing:.1em;color:var(--ink)}
.pill{font-size:12px;font-weight:700;padding:2px 8px;border-radius:99px;background:#e0f4f1;color:var(--ok);white-space:nowrap}.pill.off{background:#fdeceb;color:var(--red)}.pill.warn{background:#fef3e1;color:var(--warn)}.pill.neu{background:#eef3f2;color:var(--muted)}
.u{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:4px 0}.login{max-width:360px;margin:60px auto}
.pay{display:grid;grid-template-columns:150px 1fr;gap:14px;border-top:1px solid var(--line);padding:14px 0}.pay:first-of-type{border-top:0}
.pay img{width:150px;max-height:220px;object-fit:contain;border:1px solid var(--line);border-radius:10px;background:#fff}
.pay .amt{font-size:22px;font-weight:800;font-variant-numeric:tabular-nums}.pay .amt small{font-size:13px;color:var(--warn);font-weight:700}
.noproof{width:150px;height:110px;border:1px dashed var(--line);border-radius:10px;display:grid;place-items:center;color:var(--muted);font-size:13px;text-align:center;padding:8px}
details summary{cursor:pointer;color:var(--teal);font-weight:600;font-size:13px}details img.full{width:100%;max-width:520px;max-height:none;margin-top:8px}
.acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center}.acts input{width:220px}
@media (max-width:560px){.pay{grid-template-columns:1fr}.pay img,.noproof{width:100%}}
</style></head><body><main>
<?php if (empty($_SESSION['admin'])): ?>
  <div class="login card"><h1>Admin Kasjo</h1>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <form method="post"><p><label for="pass">Kata sandi admin</label><br><input id="pass" name="pass" type="password" required autofocus></p><button type="submit">Masuk</button></form></div>
<?php else:
    $c = function (string $sql, array $a = []): int { $s = db()->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); };
    $now = now_ms(); $monthStart = (int)(strtotime(date('Y-m-01 00:00:00')) * 1000);
    $waiting = $c("SELECT COUNT(*) FROM payments WHERE status = 'review'");
    $stats = [
        'Toko' => number_format($c('SELECT COUNT(*) FROM toko'), 0, ',', '.'),
        'Berlangganan aktif' => number_format($c('SELECT COUNT(*) FROM toko WHERE paid_until > ?', [$now]), 0, ',', '.'),
        'Masa uji coba' => number_format($c('SELECT COUNT(*) FROM toko WHERE paid_until <= ? AND trial_until > ?', [$now, $now]), 0, ',', '.'),
        'Menunggu konfirmasi' => number_format($waiting, 0, ',', '.'),
        'Pendapatan bulan ini' => rupiah($c("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'approved' AND decided_at >= ?", [$monthStart])),
    ];
    $csrf = h($_SESSION['csrf']);
    $hidden = fn($id, $do) => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="id" value="' . (int)$id . '"><input type="hidden" name="do" value="' . h($do) . '">';
    $statusPill = function (array $t) use ($fmtD): string {
        $s = toko_status($t);
        if ($s['status'] === 'paid') return '<span class="pill">Langganan s/d ' . h($fmtD($s['until'])) . '</span>';
        if ($s['status'] === 'trial') return '<span class="pill neu">Uji coba s/d ' . h($fmtD($s['until'])) . '</span>';
        return '<span class="pill off">Habis ' . h($fmtD($s['until'])) . '</span>';
    };
?>
  <header><h1>Admin Kasjo</h1><a href="?logout=1">Keluar</a></header>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
  <?php if ($temp): ?><div class="temp">PIN sementara untuk <b><?= h($temp['who']) ?></b>:<br><code><?= h($temp['pin']) ?></code><br>
    Kirim lewat WhatsApp ke nomor yang terdaftar. Pengguna wajib menggantinya saat masuk. PIN ini hanya tampil sekali.</div><?php endif; ?>
  <section class="stats"><?php foreach ($stats as $k => $n): ?><div><b><?= h((string)$n) ?></b><small><?= h($k) ?></small></div><?php endforeach; ?></section>
  <nav class="tabs"><a href="?tab=bayar" class="<?= $tab === 'bayar' ? 'on' : '' ?>">Pembayaran<?= $waiting ? ' (' . $waiting . ')' : '' ?></a><a href="?tab=toko" class="<?= $tab === 'toko' ? 'on' : '' ?>">Toko &amp; pengguna</a><a href="?tab=atur" class="<?= $tab === 'atur' ? 'on' : '' ?>">QRIS pembayaran</a></nav>

<?php if ($tab === 'bayar'):
    $st = db()->query("SELECT p.*, t.name AS toko_name, t.trial_until, t.paid_until, (SELECT phone FROM users u WHERE u.toko_id = p.toko_id AND u.role = 'owner' LIMIT 1) AS owner_phone FROM payments p JOIN toko t ON t.id = p.toko_id WHERE p.status IN ('review','pending') ORDER BY CASE p.status WHEN 'review' THEN 0 ELSE 1 END, p.created_at");
    $open = $st->fetchAll();
    $hist = db()->query("SELECT p.*, t.name AS toko_name FROM payments p JOIN toko t ON t.id = p.toko_id WHERE p.status IN ('approved','rejected') ORDER BY p.decided_at DESC LIMIT 30")->fetchAll();
    if (!app_setting('pay_qris')): ?><p class="err">Belum ada QRIS pembayaran. Agen tidak bisa membayar sampai QRIS diunggah di tab <a href="?tab=atur">QRIS pembayaran</a>.</p><?php endif; ?>
  <section class="card"><h2 style="margin-top:0">Perlu dicek</h2>
    <p style="color:var(--muted);font-size:13px;margin:4px 0 0">Cocokkan nominal (sudah termasuk kode unik) dengan mutasi rekening / riwayat QRIS sebelum konfirmasi.</p>
    <?php foreach ($open as $p): ?>
      <div class="pay">
        <div><?php if ($p['proof']): ?><details><summary><img src="<?= h($p['proof']) ?>" alt="Bukti bayar"></summary><img class="full" src="<?= h($p['proof']) ?>" alt="Bukti bayar ukuran penuh"></details><?php else: ?><div class="noproof">Belum ada bukti bayar</div><?php endif; ?></div>
        <div>
          <div><b><?= h($p['toko_name']) ?></b> · <?= h($p['owner_phone'] ?? '-') ?> · <?= $p['status'] === 'review' ? '<span class="pill warn">Bukti diunggah</span>' : '<span class="pill neu">Menunggu bayar</span>' ?></div>
          <div class="amt"><?= h(rupiah($p['amount'])) ?> <small>kode unik <?= (int)$p['code'] ?></small></div>
          <div style="color:var(--muted);font-size:13px">Paket <?= h(PLANS[$p['plan']]['label'] ?? $p['plan']) ?> · dibuat <?= h($fmt($p['created_at'])) ?> · status toko <?= $statusPill($p) ?></div>
          <div class="acts">
            <form class="inline" method="post"><?= $hidden($p['id'], 'approve') ?><button type="submit">Konfirmasi lunas</button></form>
            <form class="inline" method="post" style="display:flex;gap:8px"><?= $hidden($p['id'], 'reject') ?><input name="note" placeholder="Alasan ditolak (tampil ke agen)"><button type="submit" class="danger">Tolak</button></form>
          </div>
        </div>
      </div>
    <?php endforeach; if (!$open): ?><p style="color:var(--muted)">Tidak ada pembayaran yang menunggu.</p><?php endif; ?>
  </section>
  <section class="card"><h2 style="margin-top:0">Riwayat</h2><div class="scroll"><table><thead><tr><th>Waktu</th><th>Toko</th><th>Paket</th><th>Nominal</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($hist as $p): ?><tr><td><?= h($fmt($p['decided_at'])) ?></td><td><?= h($p['toko_name']) ?></td><td><?= h(PLANS[$p['plan']]['label'] ?? ($p['plan'] === 'manual' ? 'Manual ' . (int)$p['days'] . ' hari' : $p['plan'])) ?></td><td><?= h(rupiah($p['amount'])) ?></td>
      <td><?= $p['status'] === 'approved' ? '<span class="pill">Lunas</span>' : '<span class="pill off">Ditolak</span>' ?><?= $p['note'] ? '<br><small>' . h($p['note']) . '</small>' : '' ?></td></tr><?php endforeach; if (!$hist): ?><tr><td colspan="5">Belum ada.</td></tr><?php endif; ?>
  </tbody></table></div></section>

<?php elseif ($tab === 'atur'): $qr = app_setting('pay_qris'); ?>
  <section class="card"><h2 style="margin-top:0">QRIS pembayaran langganan</h2>
    <p style="color:var(--muted);font-size:14px">Gambar ini tampil di aplikasi saat pemilik toko memilih paket. Pakai QRIS milik Hausjo / AIPOS.</p>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="settings">
      <?php if ($qr): ?><img src="<?= h($qr) ?>" alt="QRIS pembayaran" style="width:220px;border:1px solid var(--line);border-radius:10px;background:#fff"><br><label><input type="checkbox" name="del_qris" value="1" style="width:auto"> Hapus gambar ini</label><?php endif; ?>
      <p><label for="pay_qris">Unggah gambar QRIS <?= $qr ? 'baru' : '' ?> (JPG/PNG, maks 1,5 MB)</label><br><input id="pay_qris" name="pay_qris" type="file" accept="image/jpeg,image/png,image/webp"></p>
      <p><label for="pay_bank">Info transfer bank (opsional, tampil di bawah QRIS)</label><br><textarea id="pay_bank" name="pay_bank" rows="3" placeholder="BCA 1234567890 a.n. PT Hausjo Digital Pro"><?= h(app_setting('pay_bank') ?? '') ?></textarea></p>
      <p><label for="pay_wa">WhatsApp admin untuk konfirmasi pembayaran</label><br><input id="pay_wa" name="pay_wa" value="<?= h(app_setting('pay_wa') ?? DEFAULT_PAY_WA) ?>" placeholder="0855xxxxxxxx" inputmode="tel"><br><small style="color:var(--muted)">Tombol "Konfirmasi ke Admin via WhatsApp" di aplikasi mengirim pesan ke nomor ini.</small></p>
      <button type="submit">Simpan</button></form>
    <p style="color:var(--muted);font-size:13px">Harga paket: <?php foreach (PLANS as $p) echo h($p['label'] . ' ' . rupiah($p['price'])) . ' · '; ?>maks <?= MAX_USERS ?> pengguna aktif per toko. Diubah di <code>app/api/_lib.php</code>.</p>
  </section>

<?php else:
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)) . '%';
        $ph = '%' . (norm_phone($q) ?: '#') . '%';
        $st = db()->prepare('SELECT DISTINCT t.* FROM toko t LEFT JOIN users u ON u.toko_id = t.id WHERE LOWER(t.name) LIKE ? OR u.phone LIKE ? OR LOWER(u.name) LIKE ? ORDER BY t.id DESC LIMIT 50');
        $st->execute([$like, $ph, $like]);
    } else {
        $st = db()->query('SELECT * FROM toko ORDER BY id DESC LIMIT 30');
    }
    $tokos = $st->fetchAll();
    $usersOf = db()->prepare('SELECT * FROM users WHERE toko_id = ? ORDER BY CASE role WHEN \'owner\' THEN 0 WHEN \'manager\' THEN 1 ELSE 2 END, name');
    $trxOf = db()->prepare("SELECT COUNT(*) FROM records WHERE toko_id = ? AND kind = 'trx' AND deleted = 0");
?>
  <section class="card"><form method="get"><input type="hidden" name="tab" value="toko"><label for="q">Cari toko (nama toko, nama orang, atau nomor WhatsApp)</label>
    <div style="display:flex;gap:8px;margin-top:6px"><input id="q" name="q" value="<?= h($q) ?>" placeholder="0812xxxx atau nama toko"><button type="submit">Cari</button></div></form>
  <div class="scroll" style="margin-top:12px"><table><thead><tr><th>Toko</th><th>Pengguna</th><th>Transaksi</th><th>Langganan</th></tr></thead><tbody>
  <?php foreach ($tokos as $t): $usersOf->execute([$t['id']]); $us = $usersOf->fetchAll(); $trxOf->execute([$t['id']]); ?>
    <tr><td><b><?= h($t['name']) ?></b><br><small>Daftar <?= h($fmtD($t['created_at'])) ?></small><br><?= $t['disabled'] ? '<span class="pill off">Toko nonaktif</span>' : '' ?>
        <form class="inline" method="post"><?= $hidden($t['id'], 'toko') ?><button type="submit" class="<?= $t['disabled'] ? 'sec' : 'danger' ?>" style="margin-top:6px"><?= $t['disabled'] ? 'Aktifkan toko' : 'Nonaktifkan toko' ?></button></form></td>
      <td><?php foreach ($us as $u): ?><div class="u"><span><b><?= h($u['name']) ?></b> · <?= h($u['role']) ?><br><small><?= h($u['phone']) ?> · masuk <?= h($fmt($u['last_login'])) ?></small></span>
        <?php if ($u['disabled']): ?><span class="pill off">Nonaktif</span><?php endif; ?>
        <form class="inline" method="post"><?= $hidden($u['id'], 'reset') ?><button type="submit">Reset PIN</button></form>
        <form class="inline" method="post"><?= $hidden($u['id'], 'user') ?><button type="submit" class="sec"><?= $u['disabled'] ? 'Aktifkan' : 'Nonaktifkan' ?></button></form></div>
      <?php endforeach; ?></td>
      <td><?= number_format((int)$trxOf->fetchColumn(), 0, ',', '.') ?></td>
      <td><?= $statusPill($t) ?><form method="post" class="acts"><?= $hidden($t['id'], 'extend') ?><input name="days" type="number" min="1" max="400" value="30" style="width:80px" aria-label="Jumlah hari"><button type="submit" class="sec">+ hari</button></form></td></tr>
  <?php endforeach; if (!$tokos): ?><tr><td colspan="4">Tidak ada toko yang cocok.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p style="color:var(--muted);font-size:13px">Sebelum reset PIN, pastikan yang meminta benar orangnya, misalnya chat dari nomor WhatsApp yang terdaftar. "+ hari" memperpanjang langganan tanpa pembayaran (misal kompensasi atau promo).</p></section>
<?php endif; endif; ?>
</main></body></html>
