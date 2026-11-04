package site.kasjo.edc;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.DatePickerDialog;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.util.Base64;
import android.util.Log;

import org.json.JSONObject;
import org.mozilla.geckoview.AllowOrDeny;
import org.mozilla.geckoview.GeckoResult;
import org.mozilla.geckoview.GeckoRuntime;
import org.mozilla.geckoview.GeckoRuntimeSettings;
import org.mozilla.geckoview.GeckoSession;
import org.mozilla.geckoview.GeckoSession.PermissionDelegate.ContentPermission;
import org.mozilla.geckoview.GeckoSession.PermissionDelegate.MediaSource;
import org.mozilla.geckoview.GeckoSession.PromptDelegate.ChoicePrompt;
import org.mozilla.geckoview.GeckoSession.PromptDelegate.DateTimePrompt;
import org.mozilla.geckoview.GeckoSession.PromptDelegate.FilePrompt;
import org.mozilla.geckoview.GeckoSession.PromptDelegate.PromptResponse;
import org.mozilla.geckoview.GeckoSessionSettings;
import org.mozilla.geckoview.GeckoView;
import org.mozilla.geckoview.WebExtension;
import org.mozilla.geckoview.WebRequestError;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;
import java.util.Locale;

/**
 * Engine 2: the Kasjo web app in GeckoView (Firefox's engine, bundled in the APK) for terminals whose
 * system WebView is too old. window.KasjoEDC is provided by the built-in extension in assets/kasjo-ext,
 * which talks to this activity through native messaging ("kasjo").
 */
public class GeckoActivity extends Activity {

    private static final String TAG = "KasjoGecko";
    private static final String EXT_ID = "edc-bridge@kasjo.site";
    private static final String EXT_URI = "resource://android/assets/kasjo-ext/";

    private static GeckoRuntime runtime;

    private GeckoView view;
    private GeckoSession session;
    private EdcCore core;
    private boolean canGoBack = false;

    private FilePrompt filePrompt;
    private GeckoResult<PromptResponse> fileResult;
    private GeckoSession.PermissionDelegate.Callback androidPermsCallback;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (Build.VERSION.SDK_INT >= 21) getWindow().setStatusBarColor(Color.parseColor("#0b4f4c"));

        view = new GeckoView(this);
        setContentView(view);
        core = new EdcCore(this, "gecko");

        if (runtime == null) {
            GeckoRuntimeSettings settings = new GeckoRuntimeSettings.Builder()
                    .consoleOutput(BuildConfig.DEBUG)
                    .remoteDebuggingEnabled(BuildConfig.DEBUG)
                    .build();
            runtime = GeckoRuntime.create(getApplicationContext(), settings);
        }

        session = new GeckoSession(new GeckoSessionSettings.Builder()
                .usePrivateMode(false)
                .userAgentMode(GeckoSessionSettings.USER_AGENT_MODE_MOBILE)
                .viewportMode(GeckoSessionSettings.VIEWPORT_MODE_MOBILE)
                .build());
        session.setNavigationDelegate(new Nav());
        session.setPromptDelegate(new Prompts());
        session.setPermissionDelegate(new Perms());
        session.setContentDelegate(new Content());
        session.open(runtime);
        view.setSession(session);

        final String start = Edc.startUrl(getIntent());
        runtime.getWebExtensionController().ensureBuiltIn(EXT_URI, EXT_ID).accept(
                ext -> {
                    if (ext != null) session.getWebExtensionController().setMessageDelegate(ext, new Bridge(), "kasjo");
                    session.loadUri(start);
                },
                e -> {
                    Log.e(TAG, "bridge extension failed", e);
                    session.loadUri(start);
                });
    }

    @Override
    public void onBackPressed() {
        if (canGoBack) session.goBack();
        else super.onBackPressed();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        if (intent != null && intent.getData() != null) session.loadUri(Edc.startUrl(intent));
    }

    @Override
    protected void onDestroy() {
        core.shutdown();
        if (session != null) session.close();
        super.onDestroy();
    }

    // ------------------------------------------------------------------ bridge

    private final class Bridge implements WebExtension.MessageDelegate {
        @Override
        public GeckoResult<Object> onMessage(String nativeApp, Object message, WebExtension.MessageSender sender) {
            final GeckoResult<Object> r = new GeckoResult<>();
            if (!(message instanceof JSONObject) || !Edc.isAppUrl(Uri.parse(sender.url))) {
                r.complete(EdcCore.fail("Tidak diizinkan."));
                return r;
            }
            final JSONObject m = (JSONObject) message;
            if ("saveFile".equals(m.optString("m")) && core.needsStoragePermission()) {
                Edc.askStoragePermission(GeckoActivity.this);
                r.complete(EdcCore.fail("Izinkan akses penyimpanan, lalu unduh lagi."));
                return r;
            }
            if ("info".equals(m.optString("m"))) {
                r.complete(core.info());
                return r;
            }
            core.run(() -> {
                final JSONObject out = core.handle(m);
                runOnUiThread(() -> r.complete(out));
            });
            return r;
        }
    }

    // ------------------------------------------------------------------ navigation

    private final class Nav implements GeckoSession.NavigationDelegate {
        @Override
        public void onCanGoBack(GeckoSession s, boolean b) { canGoBack = b; }

        @Override
        public GeckoResult<AllowOrDeny> onLoadRequest(GeckoSession s, LoadRequest req) {
            String url = req.uri;
            Uri u = Uri.parse(url);
            if (Edc.isAppUrl(u)) {
                if (req.target == LoadRequest.TARGET_WINDOW_NEW) { s.loadUri(url); return GeckoResult.deny(); }
                return GeckoResult.allow();
            }
            if (url.startsWith("data:") || url.startsWith("about:") || url.startsWith("resource:")) return GeckoResult.allow();
            runOnUiThread(() -> Edc.openOutside(GeckoActivity.this, u));
            return GeckoResult.deny();
        }

        @Override
        public GeckoResult<String> onLoadError(GeckoSession s, String uri, WebRequestError error) {
            if (uri != null && !Edc.isAppUrl(Uri.parse(uri))) return null;
            return GeckoResult.fromValue(offlinePage());
        }
    }

    private String offlinePage() {
        try (InputStream in = getAssets().open("offline.html")) {
            ByteArrayOutputStream b = new ByteArrayOutputStream();
            byte[] buf = new byte[4096];
            int n;
            while ((n = in.read(buf)) > 0) b.write(buf, 0, n);
            return "data:text/html;charset=utf-8;base64," + Base64.encodeToString(b.toByteArray(), Base64.NO_WRAP);
        } catch (Exception e) {
            return "data:text/html;charset=utf-8,Tidak%20ada%20koneksi%20internet";
        }
    }

    private final class Content implements GeckoSession.ContentDelegate {
        @Override
        public void onCrash(GeckoSession s) { Log.e(TAG, "content crashed"); runOnUiThread(GeckoActivity.this::recreate); }

        @Override
        public void onKill(GeckoSession s) { Log.e(TAG, "content killed"); runOnUiThread(GeckoActivity.this::recreate); }
    }

    // ------------------------------------------------------------------ dialogs: <select>, <input type=date>, file picker

    private final class Prompts implements GeckoSession.PromptDelegate {

        @Override
        public GeckoResult<PromptResponse> onChoicePrompt(GeckoSession s, final ChoicePrompt prompt) {
            final GeckoResult<PromptResponse> res = new GeckoResult<>();
            final List<ChoicePrompt.Choice> flat = new ArrayList<>();
            flatten(prompt.choices, flat);
            if (flat.isEmpty()) { res.complete(prompt.dismiss()); return res; }
            String[] labels = new String[flat.size()];
            int checked = -1;
            for (int i = 0; i < flat.size(); i++) {
                labels[i] = flat.get(i).label;
                if (flat.get(i).selected) checked = i;
            }
            AlertDialog.Builder b = new AlertDialog.Builder(GeckoActivity.this);
            b.setSingleChoiceItems(labels, checked, (d, which) -> {
                ChoicePrompt.Choice c = flat.get(which);
                if (c.disabled) return;
                d.dismiss();
                if (!res.isComplete()) res.complete(prompt.confirm(c));
            });
            b.setOnCancelListener(d -> { if (!res.isComplete()) res.complete(prompt.dismiss()); });
            b.show();
            return res;
        }

        private void flatten(ChoicePrompt.Choice[] in, List<ChoicePrompt.Choice> out) {
            if (in == null) return;
            for (ChoicePrompt.Choice c : in) {
                if (c.items != null) flatten(c.items, out);
                else if (!c.separator) out.add(c);
            }
        }

        @Override
        public GeckoResult<PromptResponse> onDateTimePrompt(GeckoSession s, final DateTimePrompt prompt) {
            final GeckoResult<PromptResponse> res = new GeckoResult<>();
            if (prompt.type != DateTimePrompt.Type.DATE) { res.complete(prompt.dismiss()); return res; }
            Calendar c = Calendar.getInstance();
            String v = prompt.defaultValue;
            if (v != null && v.matches("\\d{4}-\\d{2}-\\d{2}")) {
                c.set(Integer.parseInt(v.substring(0, 4)), Integer.parseInt(v.substring(5, 7)) - 1, Integer.parseInt(v.substring(8, 10)));
            }
            DatePickerDialog d = new DatePickerDialog(GeckoActivity.this, (dp, y, mo, day) -> {
                if (!res.isComplete()) res.complete(prompt.confirm(String.format(Locale.US, "%04d-%02d-%02d", y, mo + 1, day)));
            }, c.get(Calendar.YEAR), c.get(Calendar.MONTH), c.get(Calendar.DAY_OF_MONTH));
            d.setOnCancelListener(x -> { if (!res.isComplete()) res.complete(prompt.dismiss()); });
            d.setOnDismissListener(x -> { if (!res.isComplete()) res.complete(prompt.dismiss()); });
            d.show();
            return res;
        }

        @Override
        public GeckoResult<PromptResponse> onFilePrompt(GeckoSession s, FilePrompt prompt) {
            final GeckoResult<PromptResponse> res = new GeckoResult<>();
            if (fileResult != null && !fileResult.isComplete()) fileResult.complete(filePrompt.dismiss());
            Intent i = new Intent(Intent.ACTION_GET_CONTENT);
            i.addCategory(Intent.CATEGORY_OPENABLE);
            String mime = "*/*";
            if (prompt.mimeTypes != null && prompt.mimeTypes.length == 1 && prompt.mimeTypes[0].contains("/")) mime = prompt.mimeTypes[0];
            else if (prompt.mimeTypes != null && prompt.mimeTypes.length > 0) {
                boolean allImages = true;
                for (String m : prompt.mimeTypes) if (m == null || !m.startsWith("image")) allImages = false;
                if (allImages) mime = "image/*";
            }
            i.setType(mime);
            filePrompt = prompt;
            fileResult = res;
            try {
                startActivityForResult(Intent.createChooser(i, "Pilih file"), Edc.REQ_FILE);
            } catch (Exception e) {
                Edc.toast(GeckoActivity.this, "Tidak ada aplikasi galeri / file di mesin ini.");
                fileResult = null;
                res.complete(prompt.dismiss());
            }
            return res;
        }

        @Override
        public GeckoResult<PromptResponse> onAlertPrompt(GeckoSession s, final AlertPrompt prompt) {
            final GeckoResult<PromptResponse> res = new GeckoResult<>();
            new AlertDialog.Builder(GeckoActivity.this)
                    .setMessage(prompt.message)
                    .setPositiveButton("OK", (d, w) -> { if (!res.isComplete()) res.complete(prompt.dismiss()); })
                    .setOnCancelListener(d -> { if (!res.isComplete()) res.complete(prompt.dismiss()); })
                    .show();
            return res;
        }

        @Override
        public GeckoResult<PromptResponse> onButtonPrompt(GeckoSession s, final ButtonPrompt prompt) {
            final GeckoResult<PromptResponse> res = new GeckoResult<>();
            new AlertDialog.Builder(GeckoActivity.this)
                    .setMessage(prompt.message)
                    .setPositiveButton("OK", (d, w) -> { if (!res.isComplete()) res.complete(prompt.confirm(ButtonPrompt.Type.POSITIVE)); })
                    .setNegativeButton("Batal", (d, w) -> { if (!res.isComplete()) res.complete(prompt.confirm(ButtonPrompt.Type.NEGATIVE)); })
                    .setOnCancelListener(d -> { if (!res.isComplete()) res.complete(prompt.dismiss()); })
                    .show();
            return res;
        }
    }

    // ------------------------------------------------------------------ permissions: camera for barcode scanning

    private final class Perms implements GeckoSession.PermissionDelegate {

        @Override
        public void onAndroidPermissionsRequest(GeckoSession s, String[] permissions, Callback callback) {
            if (permissions == null || Build.VERSION.SDK_INT < 23) { callback.grant(); return; }
            List<String> missing = new ArrayList<>();
            for (String p : permissions) if (checkSelfPermission(p) != PackageManager.PERMISSION_GRANTED) missing.add(p);
            if (missing.isEmpty()) { callback.grant(); return; }
            androidPermsCallback = callback;
            requestPermissions(missing.toArray(new String[0]), Edc.REQ_ANDROID_PERMS);
        }

        @Override
        public GeckoResult<Integer> onContentPermissionRequest(GeckoSession s, ContentPermission perm) {
            boolean app = Edc.isAppUrl(Uri.parse(perm.uri));
            if (app && (perm.permission == PERMISSION_PERSISTENT_STORAGE || perm.permission == PERMISSION_AUTOPLAY_INAUDIBLE
                    || perm.permission == PERMISSION_AUTOPLAY_AUDIBLE))
                return GeckoResult.fromValue(ContentPermission.VALUE_ALLOW);
            return GeckoResult.fromValue(ContentPermission.VALUE_DENY);
        }

        @Override
        public void onMediaPermissionRequest(GeckoSession s, String uri, MediaSource[] video, MediaSource[] audio, MediaCallback callback) {
            if (!Edc.isAppUrl(Uri.parse(uri)) || video == null || video.length == 0) { callback.reject(); return; }
            MediaSource pick = video[0];
            for (MediaSource m : video) {
                String n = m.name == null ? "" : m.name.toLowerCase(Locale.US);
                if (n.contains("back")) { pick = m; break; }
            }
            callback.grant(pick, null);
        }
    }

    // ------------------------------------------------------------------ activity results

    @Override
    protected void onActivityResult(int req, int result, Intent data) {
        if (req == Edc.REQ_FILE && fileResult != null) {
            GeckoResult<PromptResponse> res = fileResult;
            FilePrompt prompt = filePrompt;
            fileResult = null;
            filePrompt = null;
            Uri uri = (result == RESULT_OK && data != null) ? data.getData() : null;
            if (uri != null) res.complete(prompt.confirm(this, uri));
            else res.complete(prompt.dismiss());
            return;
        }
        super.onActivityResult(req, result, data);
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] res) {
        if (req == Edc.REQ_ANDROID_PERMS && androidPermsCallback != null) {
            boolean all = res.length > 0;
            for (int r : res) if (r != PackageManager.PERMISSION_GRANTED) all = false;
            if (all) androidPermsCallback.grant();
            else androidPermsCallback.reject();
            androidPermsCallback = null;
        } else if (req == Edc.REQ_STORAGE && res.length > 0 && res[0] == PackageManager.PERMISSION_GRANTED) {
            Edc.toast(this, "Akses penyimpanan diizinkan. Silakan unduh lagi.");
        }
    }
}
