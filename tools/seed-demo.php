<?php
// Kasjo demo data: fills ONE existing toko with realistic sample data for videos, screenshots and the Google Play reviewer.
// Command line only (tools/ is blocked from the web by the root .htaccess, and the CLI check below).
//
//   php tools/seed-demo.php --phone=08xxxxxxxxxx            # preview: shows the toko and what would be written, changes nothing
//   php tools/seed-demo.php --phone=08xxxxxxxxxx --replace  # removes that toko's current records and writes the demo set
//   options: --name="Warung Bu Sari"  rename the toko (default: keep its current name)
//
// The login (WhatsApp number + PIN), users, subscription and payments are left alone. Old records are marked deleted,
// not erased, so phones that already have the toko open drop them on their next sync, and they stay in the database.
// Re-run any time for a fresh demo: dates are rebuilt around today, so "hari ini" always has data.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/api/_lib.php';

$opt = getopt('', ['phone:', 'replace', 'name:']);
if (empty($opt['phone'])) { fwrite(STDERR, "Usage: php tools/seed-demo.php --phone=08xxxxxxxxxx [--replace] [--name=\"Nama toko\"]\n"); exit(1); }
if (!installed()) { fwrite(STDERR, "Kasjo is not installed (config not found)\n"); exit(1); }
migrate();
$pdo = db();

$phone = norm_phone((string)$opt['phone']);
$st = $pdo->prepare('SELECT * FROM users WHERE phone = ?'); $st->execute([$phone]); $user = $st->fetch();
if (!$user) { fwrite(STDERR, "No user with WhatsApp number $phone. Register it in the app first.\n"); exit(1); }
$tid = (int)$user['toko_id']; $uid = (int)$user['id'];
$toko = load_toko_row($tid);
if (!$toko || (int)$toko['disabled'] === 1) { fwrite(STDERR, "Toko $tid is missing or disabled.\n"); exit(1); }
$tokoName = isset($opt['name']) ? mb_substr(trim((string)$opt['name']), 0, 120) : (string)$toko['name'];
if ($tokoName === '') $tokoName = (string)$toko['name'];

$cnt = $pdo->prepare('SELECT kind, COUNT(*) n FROM records WHERE toko_id = ? AND deleted = 0 GROUP BY kind'); $cnt->execute([$tid]);
$existing = array_column($cnt->fetchAll(), 'n', 'kind');
$existingTotal = array_sum(array_map('intval', $existing));

/* ---------- build the demo set (same record shapes the app writes) ---------- */
const DAY = 86400000;
$now = now_ms();
$t0 = strtotime('today') * 1000;             // 00:00 today, Asia/Jakarta (set in _lib.php)
$at = fn(int $daysAgo, int $h, int $m = 0) => $t0 - $daysAgo * DAY + ($h * 60 + $m) * 60000;
$seq = 0;
$newId = function () use (&$seq, $now) { return 'demo' . base_convert((string)$now, 10, 36) . str_pad(base_convert((string)$seq++, 10, 36), 3, '0', STR_PAD_LEFT); };

$fees = [
    'tarik'    => [['upTo' => 1000000, 'fee' => 5000], ['upTo' => 2500000, 'fee' => 10000], ['upTo' => 5000000, 'fee' => 15000], ['upTo' => null, 'fee' => 20000]],
    'transfer' => [['upTo' => 1000000, 'fee' => 5000], ['upTo' => 2500000, 'fee' => 10000], ['upTo' => 5000000, 'fee' => 15000], ['upTo' => null, 'fee' => 20000]],
    'setor'    => [['upTo' => 1000000, 'fee' => 5000], ['upTo' => 2500000, 'fee' => 10000], ['upTo' => 5000000, 'fee' => 15000], ['upTo' => null, 'fee' => 20000]],
    'topup'    => [['upTo' => 500000, 'fee' => 3000], ['upTo' => 1000000, 'fee' => 5000], ['upTo' => null, 'fee' => 10000]],
    'tagihan'  => [['upTo' => null, 'fee' => 3000]],
];
$feeFor = function (string $type, int $a) use ($fees): int { foreach ($fees[$type] as $t) if ($t['upTo'] === null || $a <= $t['upTo']) return $t['fee']; return 0; };
$accounts = [
    ['id' => 'kas', 'name' => 'Kas Tunai', 'kind' => 'cash', 'min' => 1000000],
    ['id' => 'bri', 'name' => 'BRI', 'kind' => 'bank', 'min' => 2000000],
    ['id' => 'bni', 'name' => 'BNI', 'kind' => 'bank', 'min' => 1000000],   // ends below its limit, so the "Saldo menipis" alert shows
    ['id' => 'bsi', 'name' => 'BSI', 'kind' => 'bank'],
    ['id' => 'supplier', 'name' => 'Supplier PPOB', 'kind' => 'supplier', 'min' => 200000],
];
$settings = ['name' => $tokoName, 'accounts' => $accounts, 'fees' => $fees, 'createdAt' => $at(7, 7)];

$recs = [];   // [kind, id, data]
$trx = function (array $t) use (&$recs, $newId) { $recs[] = ['trx', $newId(), $t]; };

// opening capital, 7 days ago
foreach (['kas' => 5000000, 'bri' => 8000000, 'bni' => 2000000, 'bsi' => 3000000, 'supplier' => 500000] as $acct => $amt)
    $trx(['ts' => $at(7, 7, 0), 'kind' => 'modal', 'acct' => $acct, 'amount' => $amt, 'd' => [$acct => $amt], 'profit' => 0, 'note' => 'Modal awal', 'cust' => '']);

// 21 agent transactions over the last 7 days: [daysAgo, hour, minute, type, bank, amount, feeTo, bankCost, customer]
$agent = [
    [6, 8, 15, 'tarik', 'bri', 500000, 'kas', 0, 'Pak Joko'],
    [6, 10, 40, 'transfer', 'bri', 1000000, 'kas', 0, 'Bu Rina'],
    [6, 16, 5, 'topup', 'bni', 100000, 'kas', 0, ''],
    [5, 9, 10, 'setor', 'bri', 2000000, 'kas', 0, 'Pak Haji Umar'],
    [5, 13, 30, 'tarik', 'bni', 300000, 'kas', 0, 'Mas Andi'],
    [5, 19, 20, 'tagihan', 'bri', 152000, 'kas', 0, 'Dewi'],
    [4, 8, 45, 'tarik', 'bri', 1500000, 'kas', 0, 'Pak Budi'],
    [4, 11, 0, 'transfer', 'bsi', 750000, 'kas', 2500, 'Siti'],
    [4, 17, 35, 'tarik', 'bri', 200000, 'ke_saldo', 0, ''],
    [3, 9, 25, 'transfer', 'bni', 500000, 'kas', 0, 'Bu Rina'],
    [3, 14, 10, 'topup', 'bri', 200000, 'kas', 0, ''],
    [2, 8, 30, 'tarik', 'bri', 1000000, 'kas', 0, 'Pak Joko'],
    [2, 12, 50, 'setor', 'bsi', 500000, 'kas', 0, 'Mas Andi'],
    [2, 18, 15, 'tagihan', 'bni', 245000, 'kas', 0, 'Pak Budi'],
    [1, 9, 0, 'transfer', 'bri', 2000000, 'kas', 0, 'Pak Haji Umar'],
    [1, 15, 40, 'tarik', 'bni', 500000, 'kas', 0, 'Dewi'],
    [1, 19, 10, 'topup', 'bri', 50000, 'kas', 0, ''],
    [0, 7, 40, 'tarik', 'bri', 300000, 'kas', 0, 'Pak Budi'],
    [0, 8, 20, 'transfer', 'bni', 1000000, 'kas', 0, 'Bu Rina'],
    [0, 8, 55, 'tarik', 'bri', 2500000, 'ke_saldo', 0, 'Pak Haji Umar'],
    [0, 9, 30, 'setor', 'bsi', 1000000, 'kas', 0, 'Siti'],
];
$dir = ['tarik' => 'out', 'transfer' => 'in', 'setor' => 'in', 'topup' => 'in', 'tagihan' => 'in'];
$agentCount = 0;
foreach ($agent as [$ago, $h, $m, $type, $bank, $amount, $feeTo, $cost, $cust]) {
    $ts = $at($ago, $h, $m);
    if ($ts > $now) $ts = $now - (count($agent) - $agentCount) * 60000; // early-morning run: keep today's entries in the past
    $fee = $feeFor($type, $amount); $feeTo = $feeTo === 'kas' ? 'kas' : $bank;
    $d = ['kas' => 0, $bank => 0];                                        // same as agentEffect() in the app
    if ($dir[$type] === 'out') { $d['kas'] -= $amount; $d[$bank] += $amount; } else { $d['kas'] += $amount; $d[$bank] -= $amount; }
    $d[$feeTo] += $fee; if ($cost) $d[$bank] -= $cost;
    $trx(['ts' => $ts, 'kind' => $type, 'acct' => $bank, 'amount' => $amount, 'fee' => $fee, 'feeTo' => $feeTo, 'cost' => $cost, 'd' => $d, 'profit' => $fee - $cost, 'cust' => $cust, 'note' => '']);
    $agentCount++;
}
$trx(['ts' => $at(3, 19), 'kind' => 'biaya', 'acct' => 'kas', 'amount' => 45000, 'd' => ['kas' => -45000], 'profit' => -45000, 'note' => 'Kertas struk 5 roll', 'cust' => '']);

// 10 products. Barcodes use the 200–299 in-store prefix (never a real product) with a valid EAN-13 check digit.
$ean = function (string $twelve): string { $s = 0; foreach (str_split($twelve) as $i => $c) $s += (int)$c * ($i % 2 ? 3 : 1); return $twelve . ((10 - $s % 10) % 10); };
$products = [ // name, category, digital, price, cost
    ['Mi instan goreng', 'sembako', false, 3500, 2900], ['Kopi sachet', 'minuman', false, 2000, 1500],
    ['Gula pasir 1 kg', 'sembako', false, 18000, 15500], ['Minyak goreng 1 L', 'sembako', false, 19000, 16800],
    ['Air mineral 600 ml', 'minuman', false, 4000, 2600], ['Telur ayam 1 kg', 'sembako', false, 30000, 27000],
    ['Beras 5 kg', 'sembako', false, 72000, 66000], ['Teh celup isi 25', 'minuman', false, 7000, 5600],
    ['Pulsa 10.000', 'pulsa', true, 12000, 10400], ['Token PLN 50.000', 'token', true, 52500, 50300],
];
$P = [];
foreach ($products as $i => [$name, $cat, $dig, $price, $cost]) {
    $id = $newId(); $bc = $dig ? '' : $ean('20000000' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT));
    $P[$name] = ['id' => $id, 'name' => $name, 'dig' => $dig, 'price' => $price, 'cost' => $cost, 'barcode' => $bc];
    $recs[] = ['product', $id, ['name' => $name, 'cat' => $cat, 'digital' => $dig, 'price' => $price, 'cost' => $cost, 'sup' => $dig ? 'supplier' : null, 'min' => $dig ? null : 3, 'barcode' => $bc, 'ts' => $at(7, 7, 5)]];
}
// opening stock, bought from cash
$stock = ['Mi instan goreng' => 40, 'Kopi sachet' => 60, 'Gula pasir 1 kg' => 12, 'Minyak goreng 1 L' => 12, 'Air mineral 600 ml' => 24, 'Telur ayam 1 kg' => 8, 'Beras 5 kg' => 5, 'Teh celup isi 25' => 10];
foreach ($stock as $name => $qty) {
    $p = $P[$name]; $amt = $qty * $p['cost'];
    $trx(['ts' => $at(7, 7, 10), 'kind' => 'stok', 'items' => [['pid' => $p['id'], 'name' => $name, 'qty' => $qty, 'cost' => $p['cost']]], 'amount' => $amt, 'acct' => 'kas', 'd' => ['kas' => -$amt], 'profit' => 0, 'cust' => '', 'note' => '']);
}

// 8 shop sales: [daysAgo, hour, minute, pay ('kas' | 'bank' = QRIS / transfer), bank, [[name, qty], ...]]
$sales = [
    [5, 10, 5, 'kas', null, [['Mi instan goreng', 5], ['Kopi sachet', 4]]],
    [4, 16, 20, 'kas', null, [['Pulsa 10.000', 1]]],
    [3, 11, 45, 'bank', 'bri', [['Beras 5 kg', 1], ['Minyak goreng 1 L', 1]]],
    [2, 9, 50, 'kas', null, [['Telur ayam 1 kg', 1], ['Gula pasir 1 kg', 1]]],
    [1, 13, 15, 'kas', null, [['Token PLN 50.000', 1]]],
    [1, 17, 30, 'bank', 'bri', [['Air mineral 600 ml', 6], ['Teh celup isi 25', 1]]],
    [0, 8, 10, 'kas', null, [['Kopi sachet', 3], ['Mi instan goreng', 2]]],
    [0, 9, 5, 'bank', 'bri', [['Kopi sachet', 3], ['Mi instan goreng', 2], ['Gula pasir 1 kg', 1]]],
];
foreach ($sales as [$ago, $h, $m, $pay, $bank, $lines]) {
    $ts = min($at($ago, $h, $m), $now - 30000);
    $items = []; $total = 0; $cost = 0; $d = [];
    foreach ($lines as [$name, $qty]) {
        $p = $P[$name]; $sup = $p['dig'] ? 'supplier' : null;
        $items[] = ['pid' => $p['id'], 'name' => $name, 'qty' => $qty, 'price' => $p['price'], 'cost' => $p['cost'], 'sup' => $sup];
        $total += $qty * $p['price']; $cost += $qty * $p['cost'];
        if ($sup) $d[$sup] = ($d[$sup] ?? 0) - $qty * $p['cost'];
    }
    $acct = $pay === 'kas' ? 'kas' : $bank; $d[$acct] = ($d[$acct] ?? 0) + $total;
    $trx(['ts' => $ts, 'kind' => 'jual', 'items' => $items, 'amount' => $total, 'itemCost' => $cost, 'profit' => $total - $cost, 'pay' => $pay, 'acct' => $acct, 'given' => $pay === 'kas' ? $total : 0, 'ref' => null, 'cust' => '', 'd' => $d, 'note' => '']);
}

// 3 kasbon: one due today, one 2 days late, one due later. Phone numbers are fictional (0812-0000-xxxx).
$dayKey = fn(int $daysFromToday) => date('Y-m-d', intdiv($t0, 1000) + $daysFromToday * 86400 + 3600);
$kasbon = [ // name, phone, due (days from today), [[daysAgo, hour, kind, amount, note], ...]
    ['Pak Budi', '081200001001', 0, [[5, 18, 'kasbon', 200000, 'Pinjam tunai']]],
    ['Bu Rina', '081200001002', -2, [[6, 12, 'kasbon', 125000, 'Belanja sembako'], [3, 10, 'bayarkasbon', 50000, '']]],
    ['Mas Andi', '081200001003', 11, [[1, 20, 'kasbon', 50000, 'Transfer belum dibayar']]],
];
foreach ($kasbon as [$name, $kphone, $due, $moves]) {
    $kid = $newId();
    $recs[] = ['kasbon', $kid, ['name' => $name, 'phone' => $kphone, 'due' => $dayKey($due), 'ts' => $at(6, 12)]];
    foreach ($moves as [$ago, $h, $kind, $amt, $note]) {
        $sign = $kind === 'kasbon' ? -1 : 1;
        $trx(['ts' => $at($ago, $h), 'kind' => $kind, 'ref' => $kid, 'acct' => 'kas', 'amount' => $amt, 'd' => ['kas' => $sign * $amt], 'profit' => 0, 'cust' => $name, 'note' => $note]);
    }
}

/* ---------- summary ---------- */
$bal = array_fill_keys(array_column($accounts, 'id'), 0); $profitToday = 0; $byKind = [];
foreach ($recs as [$kind, , $d]) {
    $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
    if ($kind !== 'trx') continue;
    foreach ($d['d'] ?? [] as $a => $v) $bal[$a] = ($bal[$a] ?? 0) + $v;
    if ($d['ts'] >= $t0) $profitToday += (int)($d['profit'] ?? 0);
}
echo "Toko #$tid \"{$toko['name']}\"" . ($tokoName !== $toko['name'] ? " → \"$tokoName\"" : '') . ", owner {$user['name']} ($phone)\n";
echo 'Current records: ' . ($existingTotal ? implode(', ', array_map(fn($k, $n) => "$k=$n", array_keys($existing), $existing)) : 'none') . "\n";
echo 'Demo set: ' . implode(', ', array_map(fn($k, $n) => "$k=$n", array_keys($byKind), $byKind)) . " ($agentCount agent transactions, " . count($sales) . " shop sales)\n";
echo 'Balances after: ' . implode(', ', array_map(fn($a) => $a['name'] . ' ' . rupiah($bal[$a['id']]), $accounts)) . "\n";
echo 'Profit today: ' . rupiah($profitToday) . "\n";
echo 'Barcodes: ' . implode(', ', array_map(fn($p) => $p['name'] . ' ' . $p['barcode'], array_filter($P, fn($p) => $p['barcode'] !== ''))) . "\n";

if (!isset($opt['replace'])) { echo "\nPreview only, nothing changed. Run again with --replace to write the demo data.\n"; exit(0); }

/* ---------- write ---------- */
$srv = now_ms();
$pdo->beginTransaction();
try {
    // soft-delete what is there now; the old content stays in `data`, only hidden from the app
    $pdo->prepare('UPDATE records SET deleted = 1, updated_at = ?, srv = ?, updated_by = ? WHERE toko_id = ? AND deleted = 0')->execute([$srv, $srv, $uid, $tid]);
    $ins = $pdo->prepare('INSERT INTO records (toko_id, kind, id, data, updated_at, deleted, srv, ts, k, amount, profit, created_by, updated_by) VALUES (?,?,?,?,?,0,?,?,?,?,?,?,?)');
    foreach ($recs as [$kind, $id, $d]) {
        $ins->execute([$tid, $kind, $id, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $srv, $srv,
            (int)($d['ts'] ?? 0), $kind === 'trx' ? $d['kind'] : null, (int)($d['amount'] ?? 0), $kind === 'trx' ? (int)($d['profit'] ?? 0) : null, $uid, $uid]);
    }
    $pdo->prepare('UPDATE toko SET settings = ?, settings_updated = ?, settings_srv = ?, name = ? WHERE id = ?')
        ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $srv, $srv, $tokoName, $tid]);
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); fwrite(STDERR, 'Failed, nothing changed: ' . $e->getMessage() . "\n"); exit(1); }
audit($tid, null, 'demo', null, null, 'Data contoh dimuat oleh support (' . count($recs) . ' data)' . ($existingTotal ? ", $existingTotal data lama disembunyikan" : ''));
echo "\nDone: wrote " . count($recs) . " records" . ($existingTotal ? ", hid $existingTotal old ones" : '') . ". Open the app (or pull down to refresh) to see the demo data.\n";
