package site.kasjo.edc;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.util.Log;

/**
 * Starts Kasjo with the right engine: the terminal's system WebView when it is new enough (Chrome 111+),
 * otherwise the GeckoView engine built into this app. Many PAX terminals ship a WebView that can't be
 * updated (the A620 has version 53).
 */
public class LauncherActivity extends Activity {

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        int v = Edc.webViewMajor(this);
        boolean gecko = v < Edc.MIN_WEBVIEW;
        Log.i("KasjoEDC", "system WebView " + v + " -> " + (gecko ? "GeckoView" : "WebView"));
        Intent i = new Intent(this, gecko ? GeckoActivity.class : MainActivity.class);
        if (getIntent() != null && getIntent().getData() != null) i.setData(getIntent().getData());
        startActivity(i);
        finish();
        overridePendingTransition(0, 0);
    }
}
