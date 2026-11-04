package site.kasjo.edc;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

/**
 * Engine 1: the Kasjo web app in the terminal's own system WebView (used when it is Chrome 111+).
 * LauncherActivity picks this or GeckoActivity.
 */
public class MainActivity extends Activity {

    private static final String OFFLINE_PAGE = "file:///android_asset/offline.html";

    private WebView web;
    private EdcBridge bridge;
    private ValueCallback<Uri[]> fileCallback;
    private PermissionRequest pendingPermission;

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (Build.VERSION.SDK_INT >= 21) getWindow().setStatusBarColor(Color.parseColor("#0b4f4c"));

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
        s.setAllowFileAccess(true);                  // only our offline page in assets
        s.setAllowContentAccess(false);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        s.setUserAgentString(s.getUserAgentString() + " KasjoEDC/" + BuildConfig.VERSION_NAME);

        bridge = new EdcBridge(this, web);
        web.addJavascriptInterface(bridge, "KasjoEDC");
        web.setWebViewClient(new Client());
        web.setWebChromeClient(new Chrome());

        if (state != null) web.restoreState(state);
        else web.loadUrl(Edc.startUrl(getIntent()));
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

    private final class Client extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            Uri u = Uri.parse(url);
            if (Edc.isAppUrl(u) || url.startsWith(OFFLINE_PAGE)) return false;
            Edc.openOutside(MainActivity.this, u);
            return true;
        }

        @Override
        public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
            bridge.trusted = Edc.isAppUrl(Uri.parse(url));
        }

        @Override
        public void doUpdateVisitedHistory(WebView view, String url, boolean isReload) {
            bridge.trusted = Edc.isAppUrl(Uri.parse(url));
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest req, WebResourceError err) {
            if (Build.VERSION.SDK_INT >= 23 && req.isForMainFrame() && Edc.isAppUrl(req.getUrl()))
                view.loadUrl(OFFLINE_PAGE);
        }

        @SuppressWarnings("deprecation")
        @Override
        public void onReceivedError(WebView view, int code, String desc, String url) {
            if (Build.VERSION.SDK_INT < 23 && Edc.isAppUrl(Uri.parse(url))) view.loadUrl(OFFLINE_PAGE);
        }
    }

    private final class Chrome extends WebChromeClient {
        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> cb, FileChooserParams params) {
            if (fileCallback != null) fileCallback.onReceiveValue(null);
            fileCallback = cb;
            try {
                startActivityForResult(params.createIntent(), Edc.REQ_FILE);
            } catch (Exception e) {
                fileCallback = null;
                Edc.toast(MainActivity.this, "Tidak ada aplikasi galeri / file di mesin ini.");
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
                if (!wantsCamera || !Edc.isAppUrl(req.getOrigin())) { req.deny(); return; }
                if (Build.VERSION.SDK_INT >= 23 && checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                    pendingPermission = req;
                    requestPermissions(new String[]{Manifest.permission.CAMERA}, Edc.REQ_CAMERA);
                } else {
                    req.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                }
            });
        }
    }

    @Override
    protected void onActivityResult(int req, int result, Intent data) {
        if (req == Edc.REQ_FILE && fileCallback != null) {
            fileCallback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(result, data));
            fileCallback = null;
            return;
        }
        super.onActivityResult(req, result, data);
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] res) {
        if (req == Edc.REQ_CAMERA && pendingPermission != null) {
            if (res.length > 0 && res[0] == PackageManager.PERMISSION_GRANTED)
                pendingPermission.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
            else pendingPermission.deny();
            pendingPermission = null;
        } else if (req == Edc.REQ_STORAGE && res.length > 0 && res[0] == PackageManager.PERMISSION_GRANTED) {
            Edc.toast(this, "Akses penyimpanan diizinkan. Silakan unduh lagi.");
        }
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        if (intent != null && intent.getData() != null) web.loadUrl(Edc.startUrl(intent));
    }
}
