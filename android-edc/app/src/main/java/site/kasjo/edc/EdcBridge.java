package site.kasjo.edc;

import android.webkit.JavascriptInterface;
import android.webkit.WebView;

import org.json.JSONObject;

/**
 * window.KasjoEDC for the system WebView engine. Only answers while the page is https://app.kasjo.site.
 * Slow methods take a call id and answer through window.__kasjoEdc.done(id, result) when finished.
 * (The GeckoView engine offers the same object through assets/kasjo-ext/bridge.js.)
 */
final class EdcBridge {

    private final MainActivity activity;
    private final WebView web;
    private final EdcCore core;
    volatile boolean trusted = false;

    EdcBridge(MainActivity activity, WebView web) {
        this.activity = activity;
        this.web = web;
        this.core = new EdcCore(activity, "webview");
    }

    void shutdown() { core.shutdown(); }

    @JavascriptInterface
    public String info() {
        JSONObject o = core.info();
        try { if (!trusted) o.put("printer", false); } catch (Exception ignored) { }
        return o.toString();
    }

    @JavascriptInterface
    public void printLines(final String id, final String linesJson, final String optsJson) {
        if (!trusted) { reply(id, EdcCore.fail("Tidak diizinkan.")); return; }
        core.run(() -> reply(id, core.printLines(linesJson, optsJson)));
    }

    @JavascriptInterface
    public void printerStatus(final String id) {
        if (!trusted) { reply(id, EdcCore.fail("Tidak diizinkan.")); return; }
        core.run(() -> reply(id, core.printerStatus()));
    }

    @JavascriptInterface
    public void feed(final String id, final int dots) {
        if (!trusted) { reply(id, EdcCore.fail("Tidak diizinkan.")); return; }
        core.run(() -> reply(id, core.feed(dots)));
    }

    @JavascriptInterface
    public void saveFile(final String id, final String name, final String mime, final String base64) {
        if (!trusted) { reply(id, EdcCore.fail("Tidak diizinkan.")); return; }
        if (core.needsStoragePermission()) {
            activity.runOnUiThread(() -> Edc.askStoragePermission(activity));
            reply(id, EdcCore.fail("Izinkan akses penyimpanan, lalu unduh lagi."));
            return;
        }
        core.run(() -> reply(id, core.saveFile(name, mime, base64)));
    }

    private void reply(String id, JSONObject r) {
        final String js = "window.__kasjoEdc&&window.__kasjoEdc.done(" + JSONObject.quote(id) + "," + r.toString() + ")";
        web.post(() -> web.evaluateJavascript(js, null));
    }
}
