<?php
// Kasjo one-time installer. Writes the config file next to public_html and creates the tables.
// Refuses to run again once the config file exists.
declare(strict_types=1);
require __DIR__ . '/api/_lib.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$cfgPath = config_path();
$done = false; $err = '';
$v = fn(string $k, string $d = '') => trim((string)($_POST[$k] ?? $d));
$allowSqlite = getenv('KASJO_ALLOW_SQLITE') === '1'; // local testing only

if (is_file($cfgPath)) {
    http_response_code(403);
    $already = true;
} else {
    $already = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            $adminPass = (string)($_POST['admin_pass'] ?? '');
            if (strlen($adminPass) < 10) throw new RuntimeException('Kata sandi admin minimal 10 karakter.');
            if ($adminPass !== (string)($_POST['admin_pass2'] ?? '')) throw new RuntimeException('Konfirmasi kata sandi admin tidak sama.');
            if ($allowSqlite && $v('driver') === 'sqlite') {
                $db = ['driver' => 'sqlite', 'path' => $v('sqlite_path')];
            } else {
                $db = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'name' => $v('db_name'), 'user' => $v('db_user'), 'pass' => (string)($_POST['db_pass'] ?? '')];
                if ($db['name'] === '' || $db['user'] === '' || $db['pass'] === '') throw new RuntimeException('Isi nama database, user, dan kata sandi database.');
            }
            try { $pdo = db_connect($db); }
            catch (PDOException $e) {
                if (($db['driver'] ?? '') === 'mysql') { $db['host'] = 'localhost'; $pdo = db_connect($db); }
                else throw $e;
            }
            create_schema($pdo, $db['driver']);
            $cfg = [
                'db' => $db,
                'admin_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                'support_wa' => norm_phone($v('support_wa')),
                'installed_at' => date('c'),
            ];
            $php = "<?php\n// Kasjo configuration. Keep private: contains the database password.\nreturn " . var_export($cfg, true) . ";\n";
            if (@file_put_contents($cfgPath, $php, LOCK_EX) === false) throw new RuntimeException('Tidak bisa menulis file konfigurasi di ' . $cfgPath);
            @chmod($cfgPath, 0600);
            $done = true;
        } catch (PDOException $e) {
            $err = 'Tidak bisa terhubung ke database. Periksa nama database, user, dan kata sandi. (' . $e->getCode() . ')';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Pasang Kasjo</title>
<style>
:root{--bg:#f4f8f7;--card:#fff;--ink:#1d2a29;--muted:#6b7b79;--line:#e1e9e8;--teal:#0e7c73;--red:#e0483e;--ok:#0e9b8b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Roboto,system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:560px;margin:0 auto;padding:32px 16px 60px}h1{font-size:22px;margin:0 0 4px}p{color:var(--muted)}
fieldset{border:1px solid var(--line);background:var(--card);border-radius:14px;padding:16px;margin:16px 0}legend{font-weight:700;padding:0 6px}
label{display:block;font-size:13px;font-weight:600;color:var(--muted);margin-top:12px}input{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;margin-top:4px}
small{display:block;color:var(--muted);margin-top:4px}button{background:var(--teal);color:#fff;border:0;border-radius:12px;padding:13px 18px;font:inherit;font-weight:700;width:100%;cursor:pointer}
.err{background:#fdeceb;color:var(--red);padding:12px;border-radius:10px}.ok{background:#e0f4f1;color:var(--ok);padding:14px;border-radius:10px}code{background:#eef3f2;padding:1px 5px;border-radius:5px}
</style></head><body><main>
<h1>Pasang Kasjo</h1>
<?php if ($already): ?>
  <p class="ok">Kasjo sudah terpasang. Halaman ini tidak bisa dipakai lagi.</p>
  <p>Untuk memasang ulang, hapus file <code>kasjo-config.php</code> di folder <code>domains/kasjo.site</code> (di atas <code>public_html</code>) lewat File Manager.</p>
<?php elseif ($done): ?>
  <p class="ok"><b>Berhasil.</b> Tabel database sudah dibuat dan konfigurasi disimpan di luar folder publik.</p>
  <ol><li>Buka <a href="./">aplikasi Kasjo</a> dan daftarkan toko pertama untuk tes.</li><li>Buka <a href="admin.php">halaman admin</a> dengan kata sandi admin yang baru dibuat.</li></ol>
  <p>Halaman pemasangan ini sekarang terkunci otomatis.</p>
<?php else: ?>
  <p>Isi sekali saja. Data ini disimpan di luar folder publik dan tidak pernah dikirim ke aplikasi di HP.</p>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <fieldset><legend>Database MySQL</legend>
      <?php if ($allowSqlite): ?><label>Driver (uji lokal)<input name="driver" value="<?= h($v('driver','mysql')) ?>"></label><label>Lokasi SQLite<input name="sqlite_path" value="<?= h($v('sqlite_path')) ?>"></label><?php endif; ?>
      <label for="db_name">Nama database</label><input id="db_name" name="db_name" required value="<?= h($v('db_name','u283956056_kasjo')) ?>">
      <label for="db_user">User database</label><input id="db_user" name="db_user" required value="<?= h($v('db_user','u283956056_kasjo')) ?>">
      <label for="db_pass">Kata sandi database</label><input id="db_pass" name="db_pass" type="password">
      <small>Kata sandi yang Anda atur di hPanel → Database → u283956056_kasjo. Host otomatis 127.0.0.1.</small>
    </fieldset>
    <fieldset><legend>Admin Kasjo (tim Hausjo)</legend>
      <label for="admin_pass">Kata sandi halaman admin</label><input id="admin_pass" name="admin_pass" type="password" minlength="10" required>
      <label for="admin_pass2">Ulangi kata sandi admin</label><input id="admin_pass2" name="admin_pass2" type="password" minlength="10" required>
      <small>Dipakai tim support untuk reset PIN pemilik toko di <code>admin.php</code>. Minimal 10 karakter.</small>
      <label for="support_wa">Nomor WhatsApp support Kasjo</label><input id="support_wa" name="support_wa" value="<?= h($v('support_wa')) ?>" placeholder="0812xxxxxxxx">
      <small>Ditampilkan ke pemilik toko yang lupa PIN.</small>
    </fieldset>
    <button type="submit">Pasang</button>
  </form>
<?php endif; ?>
</main></body></html>
