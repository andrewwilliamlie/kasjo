# Kasjo on Google Play

Kasjo is published to Google Play as a **Trusted Web Activity (TWA)**: a small Android wrapper, made with PWABuilder, that opens `https://app.kasjo.site` full screen in Chrome. Every update pushed to GitHub reaches the Play app instantly. A new Play upload is only needed to change the icon, name or package settings.

## 1. Policy decisions already built into the app

| Google Play rule | How Kasjo handles it |
|---|---|
| Subscriptions for app features must use Google Play Billing, and an app may not point users to another payment method ([Payments policy](https://support.google.com/googleplay/android-developer/answer/10281818)) | **Play mode**: the Play app opens `https://app.kasjo.site/?src=twa`. In that session, Langganan shows status only: no prices, plans, QRIS, bank info or "pay via WhatsApp", and the banners have no Perpanjang button. Owners pay at app.kasjo.site in a normal browser, as before. This is Google's *consumption-only* model (0% Google fee). |
| In-app account deletion plus a web deletion page ([account deletion](https://support.google.com/googleplay/android-developer/answer/13327111)) | Lainnya → **Hapus akun** (owner: whole toko and all data; manager/kasir: own login). Web page: **https://kasjo.site/hapus-akun.html** (request via WhatsApp; support deletes in admin.php → Toko → *Hapus permanen*). |
| Privacy policy | **https://kasjo.site/privasi.html**, linked from the app, the sign-in screen and the landing page. |

Do **not** add prices, "beli", "perpanjang di kasjo.site" or payment contacts to anything inside the app. They may be shown in the browser only (`IN_PLAY` is false there).

## 2. Build the Android package (PWABuilder)

1. Open **https://www.pwabuilder.com**, enter `https://app.kasjo.site`, click **Start**.
2. **Package for stores → Android → Generate package**, then open **Options** and set:

| Field | Value |
|---|---|
| Package ID | `site.kasjo.app` (permanent, can never change) |
| App name | `Kasjo: Pembukuan Agen & Kasir` |
| Launcher name | `Kasjo` |
| App version / version code | `1.0.0` / `1` (raise the code by 1 on every later upload) |
| Host | `app.kasjo.site` |
| **Start URL** | **`/?src=twa`** (this switches on Play mode, so it must be exact) |
| Theme color / Background color | `#0E7C73` / `#F4F8F7` |
| Status bar / Navigation bar color | `#0E7C73` / `#FFFFFF` |
| Display mode | Standalone |
| Notification delegation | Off |
| Location delegation | Off |
| Google Play billing | Off |
| Signing key | **Create new** |
| Fallback behavior | Custom Tabs |

3. Click **Download**. The zip has the `.aab` (upload this), `signing.keystore`, `signing-key-info.txt` and `assetlinks.json`.
4. **Keep `signing.keystore` and `signing-key-info.txt` safe** (password manager plus a private drive). Without them you can never update the app. Never put them in GitHub (`.gitignore` already blocks `*.keystore`).

## 3. Connect the app to the website (no address bar)

1. Send the content of `assetlinks.json` from the zip to Claude. It is public information, not a secret. Claude saves it as `app/.well-known/assetlinks.json`, served at `https://app.kasjo.site/.well-known/assetlinks.json`.
2. After the first upload, open Play Console → **Test and release → App integrity → App signing**. Copy the **App signing key certificate SHA-256** and send it as well: Google re-signs the app, so both fingerprints must be listed.
3. Until both are in place, the app shows a small address bar at the top. That is the only symptom.

## 4. Play Console

1. **Create app**: name `Kasjo: Pembukuan Agen & Kasir`, default language **Indonesian – id-ID**, App, **Free**.
2. **Testing first.** For a personal account created after 13 Nov 2023, run a **closed test with at least 12 testers opted in for 14 days straight** before Production is unlocked ([testing requirements](https://support.google.com/googleplay/android-developer/answer/14151465)). Agents in your network are ideal testers. Older personal accounts can go straight to production review.
3. Upload the `.aab` to **Closed testing** (or Internal testing for a quick check first).

### App content answers

| Section | Answer |
|---|---|
| Privacy policy | `https://kasjo.site/privasi.html` |
| App access | **All or some functionality is restricted.** Give the reviewer a demo login (see section 6). |
| Ads | No, the app has no ads |
| Content rating | Category *Utility, Productivity, Communication, or other*. No to every content question (violence, sexuality, gambling and so on). The app does not share the user's location and has no user-to-user public content. Expected rating: **Everyone / 3+**. |
| Target audience | **18 and over** only |
| News app | No |
| Government app | No |
| **Financial features** | **"My app doesn't provide any financial features"**. Kasjo records the agent's own bookkeeping; it does not move money, lend, or connect to bank accounts ([financial features](https://support.google.com/googleplay/android-developer/answer/13849271)). |
| Health | No |
| In-app purchases / monetization | None in the Play app |

### Data safety

- **Does your app collect or share user data?** Yes, it collects data. It does **not** share data (the hosting provider is a service provider, which doesn't count as sharing).
- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes. Deletion URL: `https://kasjo.site/hapus-akun.html`

| Data type | Collected | Required? | Purpose |
|---|---|---|---|
| Personal info → Name | Yes | Required | App functionality, Account management |
| Personal info → Phone number | Yes | Required | App functionality, Account management |
| Financial info → Other financial info (business transactions, balances, kasbon) | Yes | Required | App functionality |
| Photos and videos → Photos (shop QRIS picture, subscription proof) | Yes | Optional | App functionality |
| App activity → Other user-generated content (product names, notes, customers on kasbon) | Yes | Optional | App functionality |
| App activity → Other actions (activity log: who recorded or deleted what) | Yes | Required | App functionality, Fraud prevention / security |

Not collected: location, contacts, email, device IDs, web history, health, messages, audio, files outside what the user picks.

### Store listing (Indonesian)

- **App name (30):** `Kasjo: Pembukuan Agen & Kasir`
- **Short description (80):** `Catat transaksi agen bank & Mini ATM, fee otomatis, kasir toko dan kasbon.`
- **App category:** Business. **Tags:** Bookkeeping, Point of sale.
- **Contact:** WhatsApp support, website `https://kasjo.site`, plus an email address (required by Play).
- **Graphics** (in this folder): `icon-512.png`, `feature-graphic.png` (1024×500), `screenshot-1.png` … `screenshot-6.png` (1080×1920, phone).

**Full description:**

```
Kasjo (Kasir Agen Hausjo) adalah aplikasi pembukuan untuk agen bank dan Mini ATM: agen BRILink, Mandiri Agen, BNI Agen46, BCA, BSI, bank daerah, dan konter Mini ATM.

Catat setiap transaksi dalam hitungan detik. Fee admin terhitung otomatis, saldo kas di laci dan saldo tiap rekening langsung diperbarui, dan laba harian kelihatan tanpa hitung manual tiap malam.

TRANSAKSI AGEN
• Tarik tunai, transfer, setor tunai, top up e-wallet, bayar tagihan
• Tombol nominal cepat dan tabel fee admin yang bisa diatur sendiri
• Saldo kas tunai dan setiap rekening / EDC di satu layar
• Peringatan saat saldo kas, bank, atau deposit supplier menipis

KASIR TOKO
• Jual pulsa, paket data, token listrik, sembako, dan barang lain
• Stok barang otomatis berkurang, peringatan stok menipis
• Modal pulsa dan token dipotong dari saldo deposit supplier
• Bayar tunai dengan hitung kembalian, QRIS toko, atau kasbon
• Scan barcode dengan kamera atau scanner

KASBON & KAS HARIAN
• Buku kasbon dengan jatuh tempo dan pengingat lewat WhatsApp
• Tutup kasir: hitung uang di laci per pecahan dan lihat selisihnya
• Mutasi saldo, modal masuk, biaya operasional

STRUK & LAPORAN
• Cetak struk ke printer thermal Bluetooth 58/80 mm, atau kirim ke WhatsApp
• Laporan harian dan bulanan: laba agen dan laba toko terpisah, produk terlaris
• Unduh laporan ke Excel (CSV), cetak poster tabel biaya admin untuk toko

UNTUK PEMILIK & KASIR
• Masuk dengan nomor WhatsApp dan PIN
• Tambah kasir dan manajer dengan hak akses berbeda
• Riwayat aktivitas: siapa mencatat, mengubah, atau menghapus apa
• Tetap bisa mencatat saat sinyal hilang, data terkirim otomatis begitu online

Gratis dicoba 30 hari.

Kasjo dibuat oleh PT Hausjo Digital Pro. Kasjo adalah aplikasi pencatatan dan tidak berafiliasi dengan BRI, bank, atau penyelenggara agen mana pun. Transaksi tetap dilakukan di mesin EDC atau aplikasi agen resmi, lalu dicatat di Kasjo.
```

The title deliberately leaves out "BRILink": third-party brand names in app titles often get flagged for impersonation. The description mentions them only to say who the app is for, with the "tidak berafiliasi" line.

## 5. Updating the app later

- **App features and fixes:** push to GitHub as usual. The Play app picks them up on next launch, with no new upload.
- **Icon, name or Android settings:** regenerate in PWABuilder **with the same signing key** (Options → Signing key → *Use mine*, upload `signing.keystore` and its passwords). Raise the version code, then upload the new `.aab`.

## 6. Reviewer demo account

Before submitting, create a demo toko on the live app so Google's reviewer can sign in:

1. Open `https://app.kasjo.site/?daftar`, register `Kasjo Demo` with a spare WhatsApp number and a PIN.
2. On the setup screen tap **Lihat dulu dengan data contoh**.
3. In admin.php → Toko, give it **+ hari** (for example 365) so the review never hits an expired subscription.
4. Enter the number and PIN in Play Console → **App access**, with the instruction "Masuk dengan nomor WhatsApp dan PIN di layar awal".
