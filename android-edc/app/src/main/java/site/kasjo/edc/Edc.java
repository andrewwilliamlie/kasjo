package site.kasjo.edc;

import android.Manifest;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.webkit.WebSettings;
import android.widget.Toast;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Small helpers shared by both engines. */
final class Edc {
    private Edc() { }

    /**
     * Oldest system WebView (Chrome) that shows Kasjo correctly. The web app uses CSS color-mix() (Chrome 111),
     * flex gap (84) and "??" (80). Older WebViews — e.g. version 53 on the A620 — get the built-in GeckoView engine.
     */
    static final int MIN_WEBVIEW = 111;

    static final int REQ_FILE = 11, REQ_CAMERA = 12, REQ_STORAGE = 13, REQ_ANDROID_PERMS = 14;

    static boolean isAppUrl(Uri u) {
        return u != null && "https".equals(u.getScheme()) && BuildConfig.APP_HOST.equals(u.getHost());
    }

    /** Manifest shortcuts and links can pass ?mode= or #kasbon through the intent data. */
    static String startUrl(Intent i) {
        Uri d = i == null ? null : i.getData();
        if (isAppUrl(d)) {
            String u = d.toString();
            return u.contains("src=pax") ? u : u + (u.contains("?") ? "&" : "?") + "src=pax";
        }
        return BuildConfig.START_URL;
    }

    /** Major version of the system WebView, 0 if unknown (no WebView installed, or it failed to load). */
    static int webViewMajor(Context c) {
        try {
            Matcher m = Pattern.compile("Chrome/(\\d+)").matcher(WebSettings.getDefaultUserAgent(c));
            return m.find() ? Integer.parseInt(m.group(1)) : 0;
        } catch (Throwable t) {
            return 0;
        }
    }

    static void askStoragePermission(Activity a) {
        if (Build.VERSION.SDK_INT >= 23 && Build.VERSION.SDK_INT < 29)
            a.requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_STORAGE);
    }

    static void toast(Context c, String m) { Toast.makeText(c, m, Toast.LENGTH_LONG).show(); }

    /** Links leaving the app: WhatsApp (wa.me), intent:, tel:, other sites. */
    static void openOutside(Activity a, Uri u) {
        String scheme = u.getScheme() == null ? "" : u.getScheme();
        try {
            Intent i;
            if ("intent".equals(scheme)) {
                i = Intent.parseUri(u.toString(), Intent.URI_INTENT_SCHEME);
                i.addCategory(Intent.CATEGORY_BROWSABLE);
                i.setComponent(null);
                i.setSelector(null);
                try { a.startActivity(i); return; }
                catch (ActivityNotFoundException e) {
                    String fb = i.getStringExtra("browser_fallback_url");
                    if (fb != null) { openOutside(a, Uri.parse(fb)); return; }
                    throw e;
                }
            }
            if ("wa.me".equals(u.getHost()) || "api.whatsapp.com".equals(u.getHost())) {
                // straight to WhatsApp: these terminals usually have no browser to pass the link on
                Intent wa = new Intent(Intent.ACTION_VIEW, u);
                for (String pkg : new String[]{"com.whatsapp", "com.whatsapp.w4b"}) {
                    wa.setPackage(pkg);
                    try { a.startActivity(wa); return; } catch (ActivityNotFoundException ignored) { }
                }
                toast(a, "WhatsApp belum terpasang di mesin ini.");
                return;
            }
            a.startActivity(new Intent(Intent.ACTION_VIEW, u));
        } catch (Exception e) {
            toast(a, "Tidak ada aplikasi untuk membuka tautan ini.");
        }
    }
}
