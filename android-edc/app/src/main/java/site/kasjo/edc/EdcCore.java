package site.kasjo.edc;

import android.Manifest;
import android.app.Activity;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

import android.os.Handler;
import android.os.Looper;
import android.util.Log;

/**
 * What the web app can ask the EDC app to do, shared by both engines (system WebView and GeckoView).
 * Slow work runs on one worker thread; results are JSON {ok, code, msg}.
 */
final class EdcCore {

    final String engine;
    private final Activity activity;
    private final PaxPrinter printer;
    private volatile ExecutorService worker = Executors.newSingleThreadExecutor();
    private final ScheduledExecutorService watchdog = Executors.newSingleThreadScheduledExecutor();
    private volatile Boolean printerOk = null;

    /** A print that takes longer than this is treated as hung: the agent gets an answer and can try again. */
    static final int TIMEOUT_S = 20;

    interface Task { JSONObject run(); }
    interface Reply { void send(JSONObject r); }

    EdcCore(Activity activity, String engine) {
        this.activity = activity;
        this.engine = engine;
        this.printer = new PaxPrinter(activity);
        // Connect to the PAX service on the main thread shortly after start, like PAX's demo app.
        new Handler(Looper.getMainLooper()).postDelayed(() -> {
            PaxPrinter.connect(activity);
            printerOk = printer.available();
            Log.i("KasjoEDC", "PAX DAL " + PaxPrinter.dalState + " " + PaxPrinter.lastError);
        }, 400);
    }

    /** Runs a printer/file task on the worker thread; always replies once, at the latest after TIMEOUT_S. */
    void submit(final String what, final Task task, final Reply reply) {
        final AtomicBoolean done = new AtomicBoolean(false);
        final ExecutorService w = worker;
        w.execute(() -> {
            JSONObject r;
            try { r = task.run(); }
            catch (Throwable t) { PaxPrinter.lastError = PaxPrinter.describe(t); r = fail("Printer error. (" + PaxPrinter.lastError + ")"); }
            if (done.compareAndSet(false, true)) reply.send(r);
        });
        watchdog.schedule(() -> {
            if (!done.compareAndSet(false, true)) return;
            PaxPrinter.lastError = what + ": tidak ada jawaban " + TIMEOUT_S + " dtk (DAL " + PaxPrinter.dalState + ")";
            Log.e("KasjoEDC", PaxPrinter.lastError);
            // abandon the stuck thread and connection; the next call starts fresh
            worker = Executors.newSingleThreadExecutor();
            w.shutdownNow();
            PaxPrinter.reset();
            reply.send(fail("Printer tidak merespons. Matikan lalu nyalakan lagi mesin EDC, kemudian coba lagi."));
        }, TIMEOUT_S, TimeUnit.SECONDS);
    }

    void shutdown() { worker.shutdownNow(); watchdog.shutdownNow(); }

    JSONObject info() {
        JSONObject o = new JSONObject();
        try {
            o.put("app", BuildConfig.VERSION_NAME);
            o.put("versionCode", BuildConfig.VERSION_CODE);
            o.put("engine", engine);
            o.put("model", Build.MODEL);
            o.put("manufacturer", Build.MANUFACTURER);
            o.put("android", Build.VERSION.RELEASE);
            o.put("sdk", Build.VERSION.SDK_INT);
            o.put("paxSdk", BuildConfig.HAS_PAX_SDK);
            o.put("printer", Boolean.TRUE.equals(printerOk));
            o.put("lineWidth", PaxPrinter.LINE_WIDTH);
            o.put("dal", PaxPrinter.dalState);
            o.put("err", PaxPrinter.lastError);
        } catch (Exception ignored) { }
        return o;
    }

    /** Storage permission is only needed to write to Download on Android 6–9. Call on the UI thread. */
    boolean needsStoragePermission() {
        return Build.VERSION.SDK_INT >= 23 && Build.VERSION.SDK_INT < 29
                && activity.checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED;
    }

    // ------------------------------------------------------------ worker-thread operations

    JSONObject printLines(String linesJson, String optsJson) {
        try {
            JSONArray a = new JSONArray(linesJson);
            List<PaxPrinter.Line> lines = new ArrayList<>();
            for (int i = 0; i < a.length() && i < 400; i++) {
                JSONObject l = a.getJSONObject(i);
                lines.add(new PaxPrinter.Line(l.optString("t"), l.optInt("c") == 1, l.optInt("big") == 1));
            }
            int feed = 150;
            if (optsJson != null && optsJson.length() > 0) feed = new JSONObject(optsJson).optInt("feed", 150);
            return result(printer.print(lines, feed));
        } catch (Exception e) {
            return fail("Data struk tidak bisa dibaca.");
        }
    }

    JSONObject printerStatus() { return result(printer.status()); }

    JSONObject feed(int dots) { return result(printer.feed(dots)); }

    JSONObject btPrint(String addr, String base64) {
        try { return BtPrinter.print(addr, Base64.decode(base64 == null ? "" : base64, Base64.DEFAULT)); }
        catch (IllegalArgumentException e) { return fail("Data struk tidak bisa dibaca."); }
    }

    /** Android 12+ asks for "Nearby devices" before Bluetooth can be used. Call on the UI thread. */
    boolean needsBtPermission() {
        return Build.VERSION.SDK_INT >= 31
                && activity.checkSelfPermission("android.permission.BLUETOOTH_CONNECT") != PackageManager.PERMISSION_GRANTED;
    }

    /** Saves a report (CSV, backup) to the Download folder. */
    JSONObject saveFile(String name, String mime, String base64) {
        String safe = name == null ? "kasjo" : name.replaceAll("[^A-Za-z0-9._ -]", "_");
        if (safe.length() == 0) safe = "kasjo";
        try {
            byte[] data = Base64.decode(base64 == null ? "" : base64, Base64.DEFAULT);
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
            return ok();
        } catch (Exception e) {
            return fail("File tidak bisa disimpan.");
        }
    }

    /** Message form used by the GeckoView bridge: {m: "printLines" | "printerStatus" | "feed" | "saveFile" | "info", …}. */
    JSONObject handle(JSONObject m) {
        switch (m.optString("m")) {
            case "info": return info();
            case "printLines": return printLines(m.optString("lines"), m.optString("opts"));
            case "printerStatus": return printerStatus();
            case "feed": return feed(m.optInt("dots", 120));
            case "saveFile": return saveFile(m.optString("name"), m.optString("mime"), m.optString("data"));
            case "btList": return BtPrinter.list();
            case "btPrint": return btPrint(m.optString("addr"), m.optString("data"));
            default: return fail("Perintah tidak dikenal.");
        }
    }

    // ------------------------------------------------------------ results

    static JSONObject ok() {
        JSONObject o = new JSONObject();
        try { o.put("ok", true); } catch (Exception ignored) { }
        return o;
    }

    static JSONObject fail(String msg) {
        JSONObject o = new JSONObject();
        try { o.put("ok", false); o.put("msg", msg); } catch (Exception ignored) { }
        return o;
    }

    private JSONObject result(int status) {
        if (status == PaxPrinter.NO_SDK) printerOk = false;
        else if (status == 0) printerOk = true;
        JSONObject o = new JSONObject();
        try {
            o.put("ok", status == 0);
            o.put("code", status);
            if (status != 0) o.put("msg", PaxPrinter.message(status));
        } catch (Exception ignored) { }
        return o;
    }
}
