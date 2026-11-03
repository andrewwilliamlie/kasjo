package site.kasjo.edc;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Window;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Kasjo for EDC: the Kasjo web app (app.kasjo.site) in a WebView, with the PAX printer bridge. */
public class MainActivity extends Activity {

    /** The web app uses modern JavaScript (e.g. "??"), which needs WebView / Chrome 80 or newer. */
    private static final int MIN_WEBVIEW = 80;
    private static final int REQ_FILE = 11, REQ_CAMERA = 12, REQ_STORAGE = 13;
    private static final String OFFLINE_PAGE = "file:///android_asset/offline.html";
    private static final String OLD_PAGE = "file:///android_asset/old-webview.html";

    private WebView web;
    private EdcBridge bridge;
    private ValueCallback<Uri[]> fileCallback;
    private PermissionRequest pendingPermission;

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        Window w = getWindow();
        if (Build.VERSION.SDK_INT >= 21) w.setStatusBarColor(Color.parseColor("#0b4f4c"));

        web = new WebView(this);
        web.setBackgroundColor(Color.WHITE);
        setContentView(web);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setSupportMultipleWindows(false);          // target=_blank links come through shouldOverrideUrlLoading
        s.setTextZoom(100);
        s.setAllowFileAccess(true);                  // only our offline pages in assets
        s.setAllowContentAccess(false);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        s.setUserAgentString(s.getUserAgentString() + " KasjoEDC/" + BuildConfig.VERSION_NAME);

        bridge = new EdcBridge(this, web);
        web.addJavascriptInterface(bridge, "KasjoEDC");
        web.setWebViewClient(new Client());
        web.setWebChromeClient(new Chrome());

        int v = webViewMajor(s.getUserAgentString());
        if (v > 0 && v < MIN_WEBVIEW) web.loadUrl(OLD_PAGE + "?v=" + v);
        else if (state != null) web.restoreState(state);
        else web.loadUrl(startUrl(getIntent()));
    }

    /** Manifest shortcuts and links can pass ?mode= or #kasbon through the intent data. */
    private String startUrl(Intent i) {
        Uri d = i == null ? null : i.getData();
        if (d != null && "https".equals(d.getScheme()) && BuildConfig.APP_HOST.equals(d.getHost())) {
            String u = d.toString();
            return u.contains("src=pax") ? u : u + (u.contains("?") ? "&" : "?") + "src=pax";
        }
        return BuildConfig.START_URL;
    }

    private static int webViewMajor(String ua) {
        Matcher m = Pattern.compile("Chrome/(\\d+)").matcher(ua == null ? "" : ua);
        return m.find() ? Integer.parseInt(m.group(1)) : 0;
    }

    static boolean isAppUrl(Uri u) {
        return u != null && "https".equals(u.getScheme()) && BuildConfig.APP_HOST.equals(u.getHost());
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        web.saveState(out);
    }

    @Override
    public void onBackPressed() {
        if (web.canGoBack()) web.goBack();
        else super.onBackPressed();
    }

    @Override
    protected void onDestroy() {
        bridge.shutdown();
        web.destroy();
        super.onDestroy();
    }

    void askStoragePermission() {
        if (Build.VERSION.SDK_INT >= 23 && Build.VERSION.SDK_INT < 29)
            requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_STORAGE);
    }

    /** Links leaving the app: WhatsApp (wa.me), intent:, tel:, other sites. */
    private boolean openOutside(Uri u) {
        String scheme = u.getScheme() == null ? "" : u.getScheme();
        try {
            Intent i;
            if ("intent".equals(scheme)) {
                i = Intent.parseUri(u.toString(), Intent.URI_INTENT_SCHEME);
                i.addCategory(Intent.CATEGORY_BROWSABLE);
                i.setComponent(null);
                i.setSelector(null);
                try { startActivity(i); return true; }
                catch (ActivityNotFoundException e) {
                    String fb = i.getStringExtra("browser_fallback_url");
                    if (fb != null) return openOutside(Uri.parse(fb));
                    throw e;
                }
            }
            if ("wa.me".equals(u.getHost()) || "api.whatsapp.com".equals(u.getHost())) {
                // straight to WhatsApp: these terminals usually have no browser to pass the link on
                Intent wa = new Intent(Intent.ACTION_VIEW, u);
                for (String pkg : new String[]{"com.whatsapp", "com.whatsapp.w4b"}) {
                    wa.setPackage(pkg);
                    try { startActivity(wa); return true; } catch (ActivityNotFoundException ignored) { }
                }
                toast("WhatsApp belum terpasang di mesin ini.");
                return true;
            }
            i = new Intent(Intent.ACTION_VIEW, u);
            startActivity(i);
        } catch (Exception e) {
            toast("Tidak ada aplikasi untuk membuka tautan ini.");
        }
        return true;
    }

    private void toast(String m) { Toast.makeText(this, m, Toast.LENGTH_LONG).show(); }

    private final class Client extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            Uri u = Uri.parse(url);
            if (isAppUrl(u) || url.startsWith(OFFLINE_PAGE) || url.startsWith(OLD_PAGE)) return false;
            return openOutside(u);
        }

        @Override
        public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
            bridge.trusted = isAppUrl(Uri.parse(url));
        }

        @Override
        public void doUpdateVisitedHistory(WebView view, String url, boolean isReload) {
            bridge.trusted = isAppUrl(Uri.parse(url));
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest req, WebResourceError err) {
            if (Build.VERSION.SDK_INT >= 23 && req.isForMainFrame() && isAppUrl(req.getUrl()))
                view.loadUrl(OFFLINE_PAGE);
        }

        @SuppressWarnings("deprecation")
        @Override
        public void onReceivedError(WebView view, int code, String desc, String url) {
            if (Build.VERSION.SDK_INT < 23 && isAppUrl(Uri.parse(url))) view.loadUrl(OFFLINE_PAGE);
        }
    }

    private final class Chrome extends WebChromeClient {
        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> cb, FileChooserParams params) {
            if (fileCallback != null) fileCallback.onReceiveValue(null);
            fileCallback = cb;
            try {
                startActivityForResult(params.createIntent(), REQ_FILE);
            } catch (Exception e) {
                fileCallback = null;
                toast("Tidak ada aplikasi galeri / file di mesin ini.");
                return false;
            }
            return true;
        }

        /** Camera for barcode scanning, only for the Kasjo page. */
        @Override
        public void onPermissionRequest(final PermissionRequest req) {
            runOnUiThread(() -> {
                boolean wantsCamera = false;
                for (String r : req.getResources())
                    if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r)) wantsCamera = true;
                if (!wantsCamera || !isAppUrl(req.getOrigin())) { req.deny(); return; }
                if (Build.VERSION.SDK_INT >= 23 && checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                    pendingPermission = req;
                    requestPermissions(new String[]{Manifest.permission.CAMERA}, REQ_CAMERA);
                } else {
                    req.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                }
            });
        }
    }

    @Override
    protected void onActivityResult(int req, int result, Intent data) {
        if (req == REQ_FILE && fileCallback != null) {
            fileCallback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(result, data));
            fileCallback = null;
            return;
        }
        super.onActivityResult(req, result, data);
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] res) {
        if (req == REQ_CAMERA && pendingPermission != null) {
            if (res.length > 0 && res[0] == PackageManager.PERMISSION_GRANTED)
                pendingPermission.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
            else pendingPermission.deny();
            pendingPermission = null;
        } else if (req == REQ_STORAGE && res.length > 0 && res[0] == PackageManager.PERMISSION_GRANTED) {
            toast("Akses penyimpanan diizinkan. Silakan unduh lagi.");
        }
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        if (intent != null && intent.getData() != null) web.loadUrl(startUrl(intent));
    }
}
