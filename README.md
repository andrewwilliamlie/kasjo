# Kasjo · Kasir Agen Hausjo

Bookkeeping app for BRILink and Mini ATM agents, by PT Hausjo Digital Pro.

- **kasjo.site**: landing page (SEO, sign-up)
- **app.kasjo.site**: the app (installable PWA) with its JSON API under `/api`

## Structure

| Path | What it is |
|---|---|
| `index.html` | Landing page for kasjo.site |
| `.htaccess` | HTTPS, www → apex, `kasjo.site/app/*` → `app.kasjo.site`, hides repo files |
| `robots.txt`, `sitemap.xml` | Search engines |
| `app/index.html` | The app: UI, offline copy on the phone, sync engine |
| `app/sw.js` | Offline cache. Bump `VERSION` on every release |
| `app/manifest.webmanifest`, `app/icons/` | Install as an app on Android / iPhone |
| `app/api/index.php` | JSON API (see below) |
| `app/api/_lib.php` | Shared code: config, database schema, rate limiting, activity log. Blocked from the web |
| `app/install.php` | One-time installer. Writes `kasjo-config.php` next to `public_html` and creates the tables, then locks itself |
| `app/admin.php` | Hausjo support page: find a toko, reset a PIN, disable a user or toko |
| `tools/backup.php` | Nightly database backup, run by cron (command line only, blocked from the web) |
| `tools/seed-demo.php` | Fills one toko with demo data for videos and the Play reviewer: `--phone=08…` previews, add `--replace` to write (command line only) |

## Accounts and roles

Login is **WhatsApp number + 6-digit PIN**. Five wrong PINs lock that number for 15 minutes. WhatsApp OTP comes later.

| Role | Can |
|---|---|
| `owner` (Pemilik) | Everything: settings, fees, delete, users, activity log, wipe data |
| `manager` (Manajer) | Everything except managing users and wiping data |
| `kasir` | Record new entries only (no edit, no delete, no settings) |

The owner adds kasir and manajer in the app (Lainnya → Pengguna & kasir) and gets a temporary PIN to send them; they must choose their own PIN on first login. A forgotten **owner** PIN is reset by Hausjo support in `admin.php`.

## API

`/app/api/?a=<action>`, JSON in and out, token in the `X-Auth-Token` header.

`config`, `register`, `login`, `logout`, `me`, `change_pin`, `pull` (`since` = last server time; 0 = everything), `push` (records + settings, last edit wins, kasir limited to new records), `users`, `user_add`, `user_update`, `audit`, `wipe`.

## Data

MySQL tables: `toko` (business, settings JSON with accounts and fee table, plan and trial dates), `users`, `sessions`, `records` (kind `trx` / `kasbon` / `closing`, the app's record as JSON plus copied columns `ts`, `k`, `amount`, `profit` for reports), `audit_log`, `attempts`. Every query is scoped to the logged-in user's toko. Deletes are soft (`deleted = 1`) so other phones of the toko also remove them on their next sync.

The app keeps a copy on the phone (localStorage) and a queue of unsent changes, so agents can record while offline. It syncs every 20 seconds, when the app comes back to the foreground, and when the phone goes back online.

## Server

- Hostinger Premium Web Hosting, account `u283956056`, website `kasjo.site`, subdomain `app` → `public_html/app`
- Database `u283956056_kasjo`, host `127.0.0.1` from the app
- Config: `/home/u283956056/domains/kasjo.site/kasjo-config.php` (never in this repository)

## First install

1. In hPanel set the password of database user `u283956056_kasjo`.
2. Open `https://app.kasjo.site/install.php`, enter the database password, an admin password (for `admin.php`) and the support WhatsApp number.
3. Register the first toko at `https://app.kasjo.site/`.

## Backups

- **Nightly copy:** a Hostinger cron job runs `php /home/u283956056/domains/kasjo.site/public_html/tools/backup.php --if-due` every hour; the script only backs up in the 02:00 hour Jakarta time (or catches up if the newest copy is over 26 hours old). It writes `kasjo-YYYYMMDD-HHMMSS.sql.gz` to `/home/u283956056/domains/kasjo.site/backups/` (outside `public_html`, never downloadable from the web) and keeps the last 14 days.
- **Weekly copy:** Hostinger's own account backups (hPanel → Files → Backups) as a second layer.
- `tools/` is blocked from the web by the root `.htaccess`, and the script refuses to run outside the command line.

### Restoring a backup

1. hPanel → Files → File Manager → `domains/kasjo.site/backups/`. Download the file from the day you want.
2. hPanel → Databases → phpMyAdmin → select `u283956056_kasjo` → **Import** → choose the `.sql.gz` file → **Go**. The file drops and recreates each Kasjo table, so the database returns exactly to the moment of that backup. Anything recorded after it is lost, unless it is still waiting in a phone's offline queue.
3. Ask users to reopen the app. Phones re-sync automatically.

To restore only one toko, import into a new empty database first and copy that toko's rows across.

## Deploying

Pushes to `main` deploy to kasjo.site through Hostinger Git auto-deployment (the repository root becomes `public_html`).

## Shop POS (Penjualan toko)

Catat → **Penjualan toko** (switch at the top; the choice is remembered per phone).

- **Products** (`records` kind `product`, owner and manager only): name, category, selling price, cost price, barcode, low-stock level. *Barang* products keep stock; *digital* products (pulsa, paket data, token) have no stock and take their cost from a **supplier deposit** account.
- **Supplier deposit**: an account of kind `supplier` (Lainnya → Rekening & EDC, or from the product form). Top it up with Mutasi saldo → Pindah saldo. Its balance shows on Beranda.
- **Sale** (`trx` kind `jual`): cart with `items` (`pid`, `name`, `qty`, `price`, `cost`, `sup`), payment `kas` (with cash given and change), `bank` (QRIS / transfer into a chosen account) or `kasbon` (adds to a customer's kasbon, new or existing). Profit = price − cost per item. A kasir can sell and add one-off items not in the list.
- **Stock** is derived, never stored: barang masuk (`stok`, can be paid from an account or recorded as opening stock) minus sales plus adjustments (`stokadj`). Two phones selling at once can't overwrite each other's counts.
- **Barcode**: phone camera (Chrome on Android, BarcodeDetector) or any USB / Bluetooth scanner, which types the code and presses Enter in the search box.
- Agent profit and shop profit are shown separately on Beranda and in Laporan (with best sellers). CSV export has an Item column.
- **QRIS toko** (Lainnya → QRIS toko, owner and manager only): upload the shop's static QRIS picture (compressed in the phone to under ~800 KB, stored as `records` kind `qris`, id `main`) and pick the bank account it pays into. Choosing *QRIS / Transfer* at checkout opens the QRIS full screen with the total to pay and selects that account. Static QRIS carries no amount: the customer types it.

## Subscription (Langganan)

- 30-day free trial on sign-up, then **Rp 29.000 / 1 bulan, Rp 79.000 / 3 bulan, Rp 249.000 / 1 tahun** (`PLANS` in `app/api/_lib.php`). Every plan: all features, 1 toko, up to `MAX_USERS` = 3 active users (owner included).
- **Paying** (owner only, Lainnya → Langganan): choose a plan → the app shows Hausjo's payment QRIS and an amount with a unique code (price + 1–299, e.g. Rp 29.137) → the owner uploads a screenshot of the payment → status *Sedang dicek*.
- **Confirming** (`admin.php` → Pembayaran): match the exact amount against the bank / QRIS history, then *Konfirmasi lunas* or *Tolak* with a reason the owner sees. Approval adds the plan's days after the current end date, so paying early loses nothing. *+ hari* on the Toko tab extends a toko without payment (promo, compensation).
- **Payment QRIS, bank info and the admin WhatsApp** for confirmations (default +62 855-9630-1230) are set in `admin.php` → QRIS pembayaran (stored in `app_settings`). The bill in the app has a *Konfirmasi ke Admin via WhatsApp* button that opens a chat with toko, owner, plan, exact amount and bill number filled in.
- **Expired** toko is view-only: login, reports, kasbon and CSV still work; `push` answers 402 `expired`. Anything recorded on a phone at that moment stays in its queue and is sent automatically after renewal. Banners warn 5 days before the end.
- New tables (`payments`, `app_settings`) are created automatically on the first request after deploy (`migrate()` with a `.kasjo-schema` marker next to the config file).

## Alerts

- **Saldo minimum** (Lainnya → Peringatan saldo, owner/manager): a minimum per account (`min` on each account in `toko.settings`, shared by every phone). Beranda shows a red *Saldo menipis* card and highlights the account; a toast warns right after a transaction, sale or mutasi that pushes an account under its minimum.
- **Pengingat kasbon**: kasbon due tomorrow, today or overdue appear in *Perlu ditagih* on the Kasbon screen (overdue first) and as a card on Beranda; the Kasbon menu shows a badge with the number due today or overdue. *Ingatkan* opens WhatsApp to the customer with the amount and due date filled in, and marks it *Sudah diingatkan* for the day (per phone). Owner/manager can add a phone number or change the due date on an existing kasbon; new kasbon have quick due-date chips (3 hari, 1 minggu, 2 minggu, 1 bulan).

## Receipt printing

Lainnya → Printer struk (settings are saved per phone):

- **Bluetooth langsung**: Web Bluetooth from Chrome on Android or a laptop, for BLE thermal printers. The app finds the printer's writable characteristic and sends ESC/POS in 20-byte chunks. The printer picker opens once per app session.
- **Lewat aplikasi RawBT**: hands the ESC/POS bytes to the free RawBT Android app (`intent:base64,...;package=ru.a402d.rawbtprinter`), which works with most Bluetooth Classic and BLE printers.
- 58 mm (32 characters) or 80 mm (48 characters), custom footer line, optional auto-print after saving a transaction.
- iPhone browsers have no Web Bluetooth: share the receipt by WhatsApp instead.

## Testing locally

```sh
cd app
KASJO_CONFIG=/tmp/kasjo-config.php KASJO_ALLOW_SQLITE=1 php -S 127.0.0.1:8099
# open http://127.0.0.1:8099/install.php, choose driver "sqlite"
```
