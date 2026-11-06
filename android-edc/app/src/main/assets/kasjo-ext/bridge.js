// window.KasjoEDC for the GeckoView engine: same methods as the WebView bridge (EdcBridge.java).
// Runs at document_start on https://app.kasjo.site only, before the page's own script.
// Messages go content script -> background.js -> Android app.
(function () {
  "use strict";
  const page = window.wrappedJSObject;
  let bridge = "menunggu";
  const send = (m) => browser.runtime.sendMessage(m);
  let native = { app: "", engine: "gecko", printer: false };
  const infoStr = () => JSON.stringify(Object.assign({}, native, { engine: native.engine || "gecko", bridge: bridge }));

  function done(id, r) {
    try {
      const k = page.__kasjoEdc;
      if (k && typeof k.done === "function") k.done(String(id), JSON.stringify(r || { ok: false }));
    } catch (e) { bridge = "done: " + e.message; }
  }
  function call(m, id, args) {
    send(Object.assign({ m: m }, args)).then(
      (r) => { if (r && r.msg && /^Jembatan/.test(r.msg)) bridge = r.msg; else bridge = "ok"; done(id, r); },
      (e) => { bridge = "kirim: " + ((e && e.message) || e); done(id, { ok: false, msg: "Aplikasi EDC tidak bisa dihubungi (" + bridge + ")" }); }
    );
  }
  let refreshing = false;
  function refreshInfo(tries) {
    if (refreshing) return;
    refreshing = true;
    send({ m: "info" }).then(
      (r) => {
        refreshing = false;
        if (r && r.app) { native = r; bridge = "ok"; }
        else { bridge = (r && r.msg) || "info kosong"; if (tries > 0) setTimeout(() => refreshInfo(tries - 1), 500); }
      },
      (e) => {
        refreshing = false;
        bridge = "kirim: " + ((e && e.message) || e);
        if (tries > 0) setTimeout(() => refreshInfo(tries - 1), 500);
      }
    );
  }
  refreshInfo(10);

  const api = new page.Object();
  // info() must answer at once: return the last known state and refresh it in the background
  exportFunction(() => { refreshInfo(0); return infoStr(); }, api, { defineAs: "info" });
  exportFunction((id, lines, opts) => call("printLines", id, { lines: String(lines), opts: String(opts || "") }), api, { defineAs: "printLines" });
  exportFunction((id) => call("printerStatus", id, {}), api, { defineAs: "printerStatus" });
  exportFunction((id, dots) => call("feed", id, { dots: Number(dots) || 120 }), api, { defineAs: "feed" });
  exportFunction((id, name, mime, data) => call("saveFile", id, { name: String(name), mime: String(mime), data: String(data) }), api, { defineAs: "saveFile" });
  exportFunction((id) => call("btList", id, {}), api, { defineAs: "btList" });
  exportFunction((id, addr, data) => call("btPrint", id, { addr: String(addr), data: String(data) }), api, { defineAs: "btPrint" });
  page.KasjoEDC = api;
})();
