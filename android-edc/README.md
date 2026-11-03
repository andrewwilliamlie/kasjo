# Kasjo EDC (Android app for PAX terminals)

A small Android app that opens the Kasjo web app (`https://app.kasjo.site/?src=pax`) in a WebView and gives it the terminal's built-in printer. Every Kasjo feature and fix still ships with a normal push to `main`; this app only needs a new build when the native part changes (printer bridge, icon, name).

| | |
|---|---|
| Package | `site.kasjo.edc` (permanent once on PAXSTORE) |
| Name | Kasjo EDC |
| Terminals | PAX A920, A920 Pro, A620, A930 (no Google Play / Chrome) |
| Android | 5.1+ (minSdk 22); the web app needs Android System WebView 80+, older shows an update notice |
| Printer | PAX NeptuneLite SDK (V4.05.00), built-in fonts, 32 columns on 58 mm |

The Google Play app (`site.kasjo.app.twa`) is separate and unchanged.

## How it fits together
- `MainActivity` – WebView: JavaScript + storage, file picker (QRIS / payment proof), camera for barcode scanning, `wa.me` links opened straight in WhatsApp, `intent:` / other links handed to Android, back button = page back, offline page.
- `EdcBridge` – `window.KasjoEDC` for the web app, only while the page is `https://app.kasjo.site`:
  - `info()` → JSON `{app, model, android, printer, …}`
  - `printLines(id, linesJson, optsJson)` – lines `[{t, c, big}]` as made by `receiptLines()` in `app/index.html`
  - `printerStatus(id)`, `feed(id, dots)`
  - `saveFile(id, name, mime, base64)` – reports and backups to the Download folder (WebView cannot download `blob:` links)
  - Slow calls answer through `window.__kasjoEdc.done(id, {ok, code, msg})`.
- `PaxPrinter` – the PAX printer calls (from HausJo's `ReceiptPrinter` sample) and Indonesian messages for paper out, overheating, low battery, etc.
- `pax-stub/` – same class names as the PAX SDK so the app compiles without the licensed jar. Such a build is named `…-noprinter.apk` and its printer reports "tidak tersedia".

In the web app (`app/index.html`): `IN_EDC` is true inside this app (`window.KasjoEDC` or `?src=pax`), `IN_PLAY` is then always false (full Langganan and QRIS payment), and Lainnya → Printer struk offers **Printer bawaan EDC** (default) or RawBT.

## Building
GitHub Actions (`.github/workflows/android-edc.yml`) builds on every push that touches `android-edc/`, or by hand under Actions → Kasjo EDC app → Run workflow. Download the APK from the run's Artifacts.

Repository secrets:
- `PAX_SDK_JAR_B64` – the NeptuneLite jar as base64 (`base64 -w0 NeptuneLiteApi_V4.05.00.jar`). The repo is public, so the jar itself is never committed.
- Release signing (optional, for PAXSTORE uploads): `KASJO_EDC_KEYSTORE_B64`, `KASJO_EDC_KEYSTORE_PASSWORD`, `KASJO_EDC_KEY_ALIAS`, `KASJO_EDC_KEY_PASSWORD`. Use a Kasjo EDC keystore, separate from the Play and AIPOS keystores; never commit it.

On your own computer: Android Studio → open `android-edc/`, put the jar in `app/libs/`, Run.

## Releasing a new version
Raise `versionCode` (and `versionName`) in `app/build.gradle`, push, take the release APK from Actions and upload it in the PAXSTORE developer center.
