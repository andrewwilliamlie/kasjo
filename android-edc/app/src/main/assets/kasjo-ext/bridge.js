// window.KasjoEDC for the GeckoView engine: same methods as the WebView bridge (EdcBridge.java).
// Runs at document_start on https://app.kasjo.site only, before the page's own script.
(function () {
  "use strict";
  const page = window.wrappedJSObject;
  const send = (m) => browser.runtime.sendNativeMessage("kasjo", m);
  let info = JSON.stringify({ app: "", engine: "gecko", printer: false });

  function done(id, r) {
    try {
      const k = page.__kasjoEdc;
      if (k && typeof k.done === "function") k.done(String(id), JSON.stringify(r || { ok: false }));
    } catch (e) { /* page went away */ }
  }
  function call(m, id, args) {
    send(Object.assign({ m: m }, args)).then(
      (r) => done(id, r),
      () => done(id, { ok: false, msg: "Aplikasi EDC tidak bisa dihubungi." })
    );
  }
  function refreshInfo(tries) {
    send({ m: "info" }).then(
      (r) => { if (r) info = JSON.stringify(r); },
      () => { if (tries > 0) setTimeout(() => refreshInfo(tries - 1), 300); }
    );
  }
  refreshInfo(10);

  const api = new page.Object();
  // info() must answer at once: return the last known state and refresh it in the background
  exportFunction(() => { refreshInfo(0); return info; }, api, { defineAs: "info" });
  exportFunction((id, lines, opts) => call("printLines", id, { lines: String(lines), opts: String(opts || "") }), api, { defineAs: "printLines" });
  exportFunction((id) => call("printerStatus", id, {}), api, { defineAs: "printerStatus" });
  exportFunction((id, dots) => call("feed", id, { dots: Number(dots) || 120 }), api, { defineAs: "feed" });
  exportFunction((id, name, mime, data) => call("saveFile", id, { name: String(name), mime: String(mime), data: String(data) }), api, { defineAs: "saveFile" });
  exportFunction((id) => call("btList", id, {}), api, { defineAs: "btList" });
  exportFunction((id, addr, data) => call("btPrint", id, { addr: String(addr), data: String(data) }), api, { defineAs: "btPrint" });
  page.KasjoEDC = api;
})();
