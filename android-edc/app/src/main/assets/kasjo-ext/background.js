// Relays messages from the content script on app.kasjo.site to the Android app (native app "kasjo").
// Background-page native messaging is GeckoView's standard path (extension-level message delegate).
"use strict";
browser.runtime.onMessage.addListener((msg, sender) => {
  const url = (sender && (sender.url || (sender.tab && sender.tab.url))) || "";
  if (!/^https:\/\/app\.kasjo\.site\//.test(url)) return Promise.resolve({ ok: false, msg: "Tidak diizinkan." });
  return browser.runtime.sendNativeMessage("kasjo", msg).then(
    (r) => r || { ok: false, msg: "Jembatan EDC: jawaban kosong" },
    (e) => ({ ok: false, msg: "Jembatan EDC: " + ((e && e.message) || String(e)) })
  );
});
