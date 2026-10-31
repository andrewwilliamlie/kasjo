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
