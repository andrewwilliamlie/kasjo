# KasJo social content kit (Instagram + Facebook)

Playbook for the daily scheduled task that makes and posts KasJo content. Lives in `tools/social/`
(blocked from the website by `.htaccess`). Finished images go to `social/` at the repo root, which
**is** public: `https://kasjo.site/social/<file>.jpg` (Hostinger auto-deploys `main`).

## Accounts (Windsor.ai for Social Media connector)
| Channel | connector | account id | action |
|---|---|---|---|
| Instagram @kasjo_site (feed) | `instagram` | `17841474332628377` | `create_image_post` {image_url, caption} |
| Instagram story | `instagram` | `17841474332628377` | `create_story` {image_url} (no caption) |
| Facebook Page KasJo (feed) | `facebook_organic` | `1466839873183905` | `create_photo_post` {image_url, caption} |

Facebook stories are not supported by the connector: Facebook gets the feed post only.
Instagram needs a public JPEG (≤ 8 MB, 4:5 to 1.91:1 for feed; 9:16 for story), hence `kasjo.site/social/`.

## Daily routine (1 feed + 1 story)
1. `git pull`, read `posted.md` (what is already out) and pick the next topic from the backlog below that
   is not in the log. Follow the date: holidays and paydays (25th–1st) beat the backlog order.
2. Pick a photo from `photos/` not used in the last 7 posts (see log), or an app screenshot (`app-*.png`).
3. Copy a template from `templates/` into `work/<YYYY-MM-DD>/feed.html` and `story.html`
   (paths: `../../brand.css`, `../../photos/...`). Write new copy; keep the GoPay-Merchant-like style:
   one punchy question or problem on top, a big outlined **Lilita One** punchline in a starburst, stickers
   (chips/bubbles), brand pill top-left, CTA `kasjo.site`. Casual Indonesian (gak, banget, auto, bos, cuan).
4. Render: `cd tools/social && npm install && node render.js work/<date>/out work/<date>/*.html`.
   **Look at every JPEG** (Read the file): no text over faces, nothing clipped, story text outside the
   top 250 px and bottom 300 px. Fix and re-render until clean.
5. Copy to `social/<date>-<slug>-feed.jpg` and `social/<date>-<slug>-story.jpg`, commit
   ("Social: <date> <slug>"), push `main`. Check they exist with the Hostinger API
   (`hosting_files_list-website-and-directories`, username `u283956056`, domain `kasjo.site`, directory `social`).
   Do not commit `work/` (gitignored) or `node_modules/`.
6. **Draft for approval (required):** send both images (SendUserFile) and the caption (SendUserMessage),
   then ask with AskUserQuestion: *Posting sekarang* / *Revisi dulu* / *Lewati hari ini*. Only post after
   *Posting sekarang*. If nobody answers, post nothing and say so in the run summary.
7. On OK: post IG feed, FB feed, IG story. Append a row to `posted.md`, commit, push.

## Caption template
```
<hook line with 1 emoji>
<1–2 lines: what KasJo does about it, concrete feature>

🎁 Coba gratis 30 hari di kasjo.site (link di bio)      ← Facebook: drop "(link di bio)"

#KasJo #HausJo #AgenBRILink #MiniATM #AgenLaku #CuanAgen #UMKM
```

## Facts you may use (source: project doc `kasjo-infrastructure.md`)
- For BRILink / Mini ATM agents and warung. Agent transactions: tarik tunai, transfer, setor, top up e-wallet,
  bayar tagihan; fee admin from the owner's own fee table; laba per transaction / day / week / month.
- Balances: kas tunai di laci, bank accounts (BRI, BNI, Mandiri, BSI, BCA…), supplier deposit (PPOB);
  minimum-balance alarm ("saldo menipis"). Tutup kasir: count the drawer per denomination, shows lebih/kurang.
- Shop POS: products with stock, barcode by camera or scanner, QRIS toko shown full screen with the total,
  Bluetooth receipt printer 58/80 mm. Agent and shop profit shown separately.
- Kasbon: due list, one-tap WhatsApp reminder, badge.
- Roles: Pemilik, Manajer, Kasir (kasir can record and sell, cannot edit/delete). Activity log.
- Login with WhatsApp number + PIN. Nightly automatic backup. Works in the browser (app.kasjo.site) and as an
  Android app; EDC mode inside the Kasjo EDC app on PAX terminals.
- Prices: Rp 29.000 / bulan, Rp 79.000 / 3 bulan, Rp 249.000 / tahun; 30-day free trial; 1 toko, up to 3 users.
  Renewal page: kasjo.site/perpanjang.

## Never
- Invent testimonials, user counts, ratings, earnings or discounts. No fake reviews.
- Show other companies' logos or brand marks in images (BRI, BRILink, GoPay…). Hashtags are fine.
- Say "Download di Google Play" until the owner says the app is out of closed test.
- Text over faces; tiny text (< 26 px); more than ~12 words in the punchline.

## Topic backlog (take from the top; move done ones to posted.md)
1. Saldo deposit supplier PPOB kelihatan (pulsa, token PLN)
2. QRIS toko: tampil layar penuh + total, pelanggan tinggal scan
3. Scan barcode pakai kamera HP, gak perlu alat mahal
4. Stok barang ketahuan sebelum habis
5. Mode EDC: KasJo di mesin EDC PAX
6. Laporan mingguan & bulanan sekali tap
7. Paket 1 tahun Rp249rb: lebih hemat dari bulanan
8. Paket 3 bulan Rp79rb
9. Daftar cukup nomor WhatsApp + PIN
10. Bisa di HP Android & browser
11. Tips: atur tabel fee sendiri
12. Tips: tutup kasir per pecahan biar gak selisih
13. Tips: siapkan saldo sebelum jam ramai (gajian, awal bulan)
14. Mitos vs fakta: "pembukuan itu ribet"
15. Tebak-tebakan / kuis: transaksi mana paling cuan? (engagement)
16. Kasir gak bisa hapus transaksi: anti curang
17. Riwayat aktivitas: siapa ubah apa, kapan
18. Laba agen & laba toko dipisah
19. Bayar tagihan & top up e-wallet tercatat juga
20. Trial habis? Perpanjang gampang (kasjo.site/perpanjang)
21. Data aman? FAQ singkat (backup tiap malam, PIN)
22. Before / after: buku tulis vs KasJo
23. Gajian / awal bulan: agen paling sibuk, KasJo siap
24. Libur akhir pekan: cek laba dari rumah
25. Natal & Tahun Baru (Des), Imlek, Ramadan / Lebaran: ucapan + pesan ringan, tanpa jualan keras

## Photo library (`photos/`)
AI photos (no logos): kaget-laci-kasir, agen-hijab-tunjuk-hp, pelanggan-kasbon-canggung, wanita-kacamata-penasaran,
ibu-warung-hp-kasir, agen-teriak-senang-koin, agen-panik-hp, ibu-pusing-buku-tulis, pemilik-hammock-santai,
bapak-ngopi-pantau-hp, wanita-struk-panjang, pria-tidur-nyenyak. App screens: app-1-beranda, app-2-catat-transaksi,
app-3-kasir-toko, app-4-struk, app-5-laporan-laba, app-6-kasbon. Logo: kasjo-icon.png.

New AI photos: Canva `generate-image` works, but its full-size file cannot be downloaded from a session.
If a topic really needs a new photo, generate it, give the owner the Canva link in the draft message and ask
them to drop the downloaded file in the chat; otherwise reuse the library (different crop / layout).
