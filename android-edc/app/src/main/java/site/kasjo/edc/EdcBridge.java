package site.kasjo.edc;

import android.Manifest;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * window.KasjoEDC in the web app. Only answers while the page is https://app.kasjo.site.
 * Slow methods take a call id and answer through window.__kasjoEdc.done(id, result) when finished.
 */
final class EdcBridge {

    private final MainActivity activity;
    private final WebView web;
    private final PaxPrinter printer;
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    volatile boolean trusted = false;
    private volatile Boolean printerOk = null;

    EdcBridge(MainActivity activity, WebView web) {
        this.activity = activity;
        this.web = web;
        this.printer = new PaxPrinter(activity);
        worker.execute(() -> printerOk = printer.available());
    }

    void shutdown() { worker.shutdownNow(); }

    // ------------------------------------------------------------------ JS API

    @JavascriptInterface
    public String info() {
        JSONObject o = new JSONObject();
        try {
            o.put("app", BuildConfig.VERSION_NAME);
            o.put("versionCode", BuildConfig.VERSION_CODE);
            o.put("model", Build.MODEL);
            o.put("manufacturer", Build.MANUFACTURER);
            o.put("android", Build.VERSION.RELEASE);
            o.put("sdk", Build.VERSION.SDK_INT);
            o.put("paxSdk", BuildConfig.HAS_PAX_SDK);
            o.put("printer", trusted && Boolean.TRUE.equals(printerOk));
            o.put("lineWidth", PaxPrinter.LINE_WIDTH);
        } catch (Exception ignored) { }
        return o.toString();
    }

    /** lines: [{t, c, big}] already laid out for 32 columns. opts: {feed: dots}. */
    @JavascriptInterface
    public void printLines(final String id, final String linesJson, final String optsJson) {
        if (!trusted) { reply(id, fail("Tidak diizinkan.")); return; }
        worker.execute(() -> {
            try {
                JSONArray a = new JSONArray(linesJson);
                List<PaxPrinter.Line> lines = new ArrayList<>();
                for (int i = 0; i < a.length() && i < 400; i++) {
                    JSONObject l = a.getJSONObject(i);
                    lines.add(new PaxPrinter.Line(l.optString("t"), l.optInt("c") == 1, l.optInt("big") == 1));
                }
                int feed = 150;
                if (optsJson != null && optsJson.length() > 0) feed = new JSONObject(optsJson).optInt("feed", 150);
                reply(id, result(printer.print(lines, feed)));
            } catch (Exception e) {
                reply(id, fail("Data struk tidak bisa dibaca."));
            }
        });
    }

    @JavascriptInterface
    public void printerStatus(final String id) {
        if (!trusted) { reply(id, fail("Tidak diizinkan.")); return; }
        worker.execute(() -> reply(id, result(printer.status())));
    }

    @JavascriptInterface
    public void feed(final String id, final int dots) {
        if (!trusted) { reply(id, fail("Tidak diizinkan.")); return; }
        worker.execute(() -> reply(id, result(printer.feed(dots))));
    }

    /** Saves a report (CSV, backup) to the Download folder; the web app cannot use blob downloads in a WebView. */
    @JavascriptInterface
    public void saveFile(final String id, final String name, final String mime, final String base64) {
        if (!trusted) { reply(id, fail("Tidak diizinkan.")); return; }
        if (Build.VERSION.SDK_INT < 29 && Build.VERSION.SDK_INT >= 23
                && activity.checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
            activity.runOnUiThread(activity::askStoragePermission);
            reply(id, fail("Izinkan akses penyimpanan, lalu unduh lagi."));
            return;
        }
        worker.execute(() -> {
            String safe = name == null ? "kasjo" : name.replaceAll("[^A-Za-z0-9._ -]", "_");
            if (safe.length() == 0) safe = "kasjo";
            try {
                byte[] data = Base64.decode(base64, Base64.DEFAULT);
                if (Build.VERSION.SDK_INT >= 29) {
                    ContentResolver cr = activity.getContentResolver();
                    ContentValues v = new ContentValues();
                    v.put(MediaStore.MediaColumns.DISPLAY_NAME, safe);
                    v.put(MediaStore.MediaColumns.MIME_TYPE, mime == null || mime.isEmpty() ? "application/octet-stream" : mime);
                    v.put(MediaStore.MediaColumns.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS);
                    Uri uri = cr.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v);
                    if (uri == null) throw new IllegalStateException("no uri");
                    try (OutputStream os = cr.openOutputStream(uri)) { os.write(data); }
                } else {
                    File dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                    if (!dir.exists()) dir.mkdirs();
                    try (FileOutputStream os = new FileOutputStream(new File(dir, safe))) { os.write(data); }
                }
                reply(id, ok());
            } catch (Exception e) {
                reply(id, fail("File tidak bisa disimpan."));
            }
        });
    }

    // ------------------------------------------------------------------ helpers

    private static JSONObject ok() {
        JSONObject o = new JSONObject();
        try { o.put("ok", true); } catch (Exception ignored) { }
        return o;
    }

    private static JSONObject fail(String msg) {
        JSONObject o = new JSONObject();
        try { o.put("ok", false); o.put("msg", msg); } catch (Exception ignored) { }
        return o;
    }

    private JSONObject result(int status) {
        if (status == PaxPrinter.NO_SDK) printerOk = false;
        JSONObject o = new JSONObject();
        try {
            o.put("ok", status == 0);
            o.put("code", status);
            if (status != 0) o.put("msg", PaxPrinter.message(status));
        } catch (Exception ignored) { }
        return o;
    }

    private void reply(String id, JSONObject r) {
        final String js = "window.__kasjoEdc&&window.__kasjoEdc.done(" + JSONObject.quote(id) + "," + r.toString() + ")";
        web.post(() -> web.evaluateJavascript(js, null));
    }
}
