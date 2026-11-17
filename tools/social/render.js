// Render KasJo social posts (plain HTML files) to JPEG.
// Setup once per session:  cd tools/social && npm install   (Playwright is preinstalled globally)
// Usage: node tools/social/render.js out_dir page1.html [page2.html ...]
// Each page's size comes from its first element with class "feed" (1080x1350) or "story" (1080x1920).
// Pages link ../brand.css (or a relative path to it) and images by relative path, e.g. ../photos/x.jpg.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const F = path.join(__dirname, 'node_modules', '@fontsource');
const fontCss = `
@font-face{font-family:'Lilita One';src:url(file://${F}/lilita-one/files/lilita-one-latin-400-normal.woff2)}
@font-face{font-family:'Plus Jakarta Sans';font-weight:600;src:url(file://${F}/plus-jakarta-sans/files/plus-jakarta-sans-latin-600-normal.woff2)}
@font-face{font-family:'Plus Jakarta Sans';font-weight:700;src:url(file://${F}/plus-jakarta-sans/files/plus-jakarta-sans-latin-700-normal.woff2)}
@font-face{font-family:'Plus Jakarta Sans';font-weight:800;src:url(file://${F}/plus-jakarta-sans/files/plus-jakarta-sans-latin-800-normal.woff2)}`;

(async () => {
  const [out, ...files] = process.argv.slice(2);
  if (!out || !files.length) { console.error('usage: node render.js out_dir page.html ...'); process.exit(1); }
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch();
  for (const f of files) {
    const abs = path.resolve(f);
    const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
    await page.goto('file://' + abs);
    await page.addStyleTag({ content: fontCss });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(400);
    const box = await page.evaluate(() => {
      const el = document.querySelector('.feed, .story') || document.body.firstElementChild;
      const r = el.getBoundingClientRect(); return { width: Math.round(r.width), height: Math.round(r.height) };
    });
    const name = path.basename(f).replace(/\.html$/, '');
    const dest = path.join(path.resolve(out), name + '.jpg');
    await page.screenshot({ path: dest, type: 'jpeg', quality: 90, clip: { x: 0, y: 0, ...box } });
    console.log('ok', dest, box.width + 'x' + box.height);
    await page.close();
  }
  await browser.close();
})();
