/* Behavioral contracts for the public Ledger routes. Every page is the real
 * template (templates/hub-page.php or templates/entity-page.php) rendered by
 * tests/fixtures/ledger-routes.php, dressed with the plugin stylesheets in
 * enqueue order and the Theme Studio variables production inlines. Everything
 * is measured, nothing is pinned to text, and every failure on every page and
 * width is collected before the run is judged. Rendered at 320, 390, 768 and
 * 1440 with reduced motion and no JavaScript; keyboard checks get a second
 * context with scripts on. */
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');
const root = path.resolve(__dirname, '..');
const output = process.env.LEDGER_VISUAL_OUTPUT || path.join(os.tmpdir(), 'lunara-ledger-routes');
const assets = ['academy-awards-table', 'ledger-explorer', 'ledger-motion', 'ceremony-dossier', 'hub-polish'];
const css = assets.map(name => fs.readFileSync(path.join(root, 'assets/css', `${name}.css`), 'utf8')).join('\n');
const base = 'html{font-size:16px}body{margin:0;background:#0a1520;color:#e6eef7;font-family:Georgia,serif}*,*::before,*::after{box-sizing:border-box}a{color:#eadbb3}button,input,select{font:inherit}';
const variables = 'body.aat-shell-page{--lunara-oscars-dossier-section-gap:48px;--lunara-oscars-dossier-card-min:280px;--lunara-oscars-dossier-density-scale:1;--lunara-oscars-dossier-hero-max:1040px;--lunara-oscars-profile-media-max:340px;--lunara-oscars-profile-media-scale-max:340px;--lunara-oscars-profile-media-width:340px;--lunara-oscars-profile-media-height:500px;--lunara-oscars-profile-media-fit:cover;--lunara-oscars-profile-media-aspect:2 / 3;--lunara-oscars-writeup-max:880px;--lunara-oscars-major-race-gap:18px;--lunara-oscars-related-review-min:280px;--lunara-oscars-related-review-media-aspect:16 / 10;--lunara-oscars-related-review-count:6;--lunara-oscars-image-focus:center center}';
const php = args => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'fixtures/ledger-routes.php'), ...args], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
const routes = [
  ['ceremonies'], ['categories'], ['about'],
  ['ceremony', 'latest'], ['ceremony', 'latest', 'full'], ['ceremony', 'early'],
  ['category', 'premium'], ['category', 'premium', 'full'], ['category', 'generic'], ['category', 'generic', 'full'],
  ['title', 'poster'], ['title', 'noposter'], ['name', 'portrait'], ['name', 'noportrait'], ['company'], ['explorer'],
];
const pages = routes.map(args => ({ name: args.join('-'), route: args[0], variant: args[1] || '', html: php(args) }));

const controls = 'a.aat-btn, a.aat-hub-card-action, a.aat-winner-circle-action, a.aat-decade-pill, a.aat-hub-chip, a.aat-hub-chip-rich, .aat-nominee-trail-summary, .aat-tracker-tab, .lle-select, .lle-search__go, .lle-pager a, .lle-group__explore';
const reading = '.aat-hub-subtitle, .aat-hub-copy, .aat-section-description, .aat-entity-overview, .aat-category-history-meta, .aat-category-history-detail, .aat-nominee-trail-item, .aat-history-line, .aat-ceremony-editorial-body p, .lle-row__sub, .lle-dek';
const metadata = '.aat-hub-card-type, .aat-hub-card-meta, .aat-stat-label, .aat-dossier-command-card > span, .aat-ceremony-command-card > span, .aat-profile-command-card > span, .aat-major-race-nominee-secondary, .aat-timeline-meta, .aat-category-ceremony-year, .lle-field__label, .lle-status';
const surfaces = '.aat-hub-card, .aat-dossier-hero, .aat-dossier-command-card, .aat-ceremony-dossier-hero, .aat-ceremony-command-card, .aat-major-race-card, .aat-entity-hero, .aat-profile-command-card, .aat-timeline-card, .aat-history-item, .lle-hero, .lle-debrief';
// One class per fixture record: ballot rows, race nominees, trail items, timeline entries, cards, explorer rows.
const records = ['aat-hub-card', 'aat-ceremony-ballot-row', 'aat-major-race-card', 'aat-major-race-nominee-primary', 'aat-major-race-briefing-card', 'aat-winner-circle-card', 'aat-best-picture-card', 'aat-filmography-card', 'aat-related-review-card', 'aat-category-ceremony-row', 'aat-category-history-winner', 'aat-nominee-trail-item', 'aat-decade-pill', 'aat-timeline-card', 'aat-history-item', 'aat-crossroad-pill', 'aat-editorial-ref-card', 'aat-motion-spoke', 'lle-row', 'lle-group'];
const countInOutput = (html, cls) => (html.match(new RegExp(`class="(?:[^"]*\\s)?${cls}(?:\\s[^"]*)?"`, 'g')) || []).length;
const expectedRecords = html => Object.fromEntries(records.map(cls => [cls, countInOutput(html, cls)]));
const placeholder = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="600"><rect width="400" height="600" fill="#142a3d"/><path d="M0 400L400 140V600H0Z" fill="#29465a"/></svg>';
const wrap = page => `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${base}${variables}${css}</style></head><body class="aat-shell-page${page.route === 'explorer' ? ' aat-shell-explorer' : ''}">${page.html}</body></html>`;

/* Runs inside the page. Returns [check, detail] pairs for everything that misses the contract. */
function measure({ controls, reading, metadata, surfaces, records }) {
  const bad = [];
  const label = el => {
    const classes = typeof el.className === 'string' ? el.className : (el.getAttribute('class') || '');
    return `${el.tagName.toLowerCase()}${classes ? '.' + classes.trim().split(/\s+/).join('.') : ''} "${(el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 48)}"`;
  };
  const visible = el => {
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0 && getComputedStyle(el).visibility !== 'hidden';
  };
  const root = document.documentElement;
  if (root.scrollWidth > innerWidth + 1) bad.push(['overflow', `the document is ${root.scrollWidth}px wide in a ${innerWidth}px viewport`]);
  for (const h1 of document.querySelectorAll('h1')) {
    const rect = h1.getBoundingClientRect();
    if (rect.left < -0.5 || rect.right > innerWidth + 0.5 || h1.scrollWidth > h1.clientWidth + 1) bad.push(['h1 fit', `${label(h1)} spans ${Math.round(rect.left)}..${Math.round(rect.right)} of ${innerWidth}px, text ${h1.scrollWidth}px in a ${h1.clientWidth}px box`]);
  }
  for (const el of document.querySelectorAll(controls)) {
    if (!visible(el)) continue;
    const height = el.getBoundingClientRect().height;
    if (height < 43.9) bad.push(['touch target', `${label(el)} is ${height.toFixed(1)}px tall`]);
  }
  for (const el of document.querySelectorAll(reading)) {
    if (!visible(el)) continue;
    const size = parseFloat(getComputedStyle(el).fontSize);
    if (size < 16) bad.push(['reading copy', `${label(el)} is ${size}px`]);
  }
  for (const el of document.querySelectorAll(metadata)) {
    if (!visible(el)) continue;
    const style = getComputedStyle(el);
    const size = parseFloat(style.fontSize);
    const lineHeight = style.lineHeight === 'normal' ? NaN : parseFloat(style.lineHeight);
    if (size < 13 || !(lineHeight >= size * 1.3) || !['normal', '0px'].includes(style.letterSpacing)) bad.push(['metadata', `${label(el)} ${style.fontSize} / ${style.lineHeight} / ${style.letterSpacing}`]);
  }
  for (const el of document.querySelectorAll(surfaces)) {
    const style = getComputedStyle(el);
    if (style.backgroundImage !== 'none' || style.boxShadow !== 'none' || parseFloat(style.borderLeftWidth) > 1) bad.push(['ornament', `${label(el)} background-image ${style.backgroundImage.slice(0, 48)}, box-shadow ${style.boxShadow.slice(0, 32)}, border-left ${style.borderLeftWidth}`]);
    if (style.opacity === '0' || style.visibility === 'hidden') bad.push(['hidden surface', label(el)]);
  }
  for (const el of document.querySelectorAll('body *')) {
    const style = getComputedStyle(el);
    if (style.animationName !== 'none' && style.animationDuration.split(',').some(duration => parseFloat(duration) > 0)) bad.push(['animation', `${label(el)} runs ${style.animationName} for ${style.animationDuration}`]);
    if (style.opacity === '0' && (el.textContent || '').trim() !== '') bad.push(['staged entrance', `${label(el)} is opacity 0 with text inside`]);
  }
  for (const [cls, expected] of Object.entries(records)) {
    const all = [...document.querySelectorAll('.' + cls)];
    const present = all.filter(el => el.closest('details') || (el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden')).length;
    if (all.length !== expected || present !== expected) bad.push(['record completeness', `.${cls}: ${expected} in the PHP output, ${all.length} in the DOM, ${present} present`]);
  }
  return bad;
}

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  const failures = [];
  let checks = 0;
  const fail = (page, width, check, detail) => failures.push({ page, width, check, detail });
  const check = (page, width, name, ok, detail) => { if (ok) checks++; else fail(page, width, name, detail); };
  const intercept = page => page.route('**/*', route => route.request().resourceType() === 'image'
    ? route.fulfill({ contentType: 'image/svg+xml', body: placeholder })
    : route.abort());
  const open = async (context, page) => {
    const tab = await context.newPage();
    await intercept(tab);
    await tab.setContent(wrap(page), { waitUntil: 'load' });
    return tab;
  };
  try {
    for (const width of [320, 390, 768, 1440]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce', javaScriptEnabled: false });
      for (const page of pages) {
        const tab = await open(context, page);
        const bad = await tab.evaluate(measure, { controls, reading, metadata, surfaces, records: expectedRecords(page.html) });
        const groups = ['overflow', 'h1 fit', 'touch target', 'reading copy', 'metadata', 'ornament', 'hidden surface', 'animation', 'staged entrance', 'record completeness'];
        for (const group of groups) {
          const misses = bad.filter(([name]) => name === group);
          check(page.name, width, group, misses.length === 0, misses.map(([, detail]) => detail));
        }
        if ((page.route === 'title' && page.variant === 'poster') || (page.route === 'name' && page.variant === 'portrait')) {
          const artwork = () => { const img = document.querySelector('.aat-entity-poster-wrap img'); if (!img) return null; const s = getComputedStyle(img); return { fit: s.objectFit, focus: s.objectPosition }; };
          const saved = await tab.evaluate(artwork);
          check(page.name, width, 'artwork fit', saved && saved.fit === 'cover' && saved.focus === '50% 50%', `object-fit/object-position ${JSON.stringify(saved)}; expected cover at 50% 50% from the Theme Studio variables`);
          // Appended by hand: addStyleTag waits for a load event that never fires with scripts off.
          await tab.evaluate(rules => { const style = document.createElement('style'); style.textContent = rules; document.head.appendChild(style); }, 'body.aat-shell-page{--lunara-oscars-profile-media-fit:contain;--lunara-oscars-image-focus:25% 70%}');
          const changed = await tab.evaluate(artwork);
          check(page.name, width, 'artwork follows variables', changed && changed.fit === 'contain' && changed.focus === '25% 70%', `after setting the variables to contain / 25% 70% the image computes ${JSON.stringify(changed)}`);
        }
        if ((page.route === 'title' && page.variant === 'noposter') || (page.route === 'name' && page.variant === 'noportrait')) {
          const fallback = await tab.evaluate(() => { const el = document.querySelector('.aat-entity-poster-wrap .aat-entity-poster-fallback'); return el ? el.getBoundingClientRect().height : null; });
          check(page.name, width, 'artwork fallback', fallback !== null && fallback >= 100, fallback === null ? 'no .aat-entity-poster-fallback in the hero' : `fallback is ${fallback.toFixed(1)}px tall`);
        }
        if (width === 390 || width === 1440) {
          try { await tab.screenshot({ path: path.join(output, `${page.name}-${width}.png`), fullPage: true }); }
          catch (error) { fail(page.name, width, 'screenshot', error.message.split('\n')[0]); }
        }
        await tab.close();
      }
      await context.close();
    }

    // Keyboard, with scripts on: the nominee trail opens from the keyboard and focus is visible.
    for (const width of [390, 1440]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
      for (const page of pages.filter(page => page.route === 'category' || page.route === 'explorer')) {
        const tab = await open(context, page);
        if (page.route === 'category') {
          const summary = 'summary.aat-nominee-trail-summary';
          let reached = await tab.evaluate(summary => !document.querySelector(summary), summary) ? null : false;
          for (let presses = 0; reached === false && presses < 500; presses++) {
            await tab.keyboard.press('Tab');
            reached = await tab.evaluate(summary => document.activeElement === document.querySelector(summary), summary);
          }
          check(page.name, width, 'keyboard reach', reached === true, reached === null ? `no ${summary} on the page` : 'Tab never reached the first nominee trail summary');
          if (reached) {
            const outline = await tab.evaluate(() => getComputedStyle(document.activeElement).outlineStyle);
            check(page.name, width, 'focus visible', outline === 'solid', `outline-style on the focused summary is ${outline}`);
            await tab.keyboard.press('Enter');
            const opened = await tab.evaluate(summary => document.querySelector(summary).closest('details').open, summary);
            check(page.name, width, 'keyboard opens trail', opened === true, 'Enter on the summary did not open its details');
          }
        } else {
          const select = tab.locator('select[name="category"]');
          await select.focus();
          const outline = await select.evaluate(el => getComputedStyle(el).outlineStyle);
          check(page.name, width, 'focus visible', outline === 'solid', `outline-style on the focused category select is ${outline}`);
        }
        await tab.close();
      }
      await context.close();
    }
  } finally {
    await browser.close();
  }

  if (failures.length) {
    console.error(`Ledger routes browser failed: ${failures.length} failing checks (${checks} passed); screenshots ${output}`);
    for (const { page, width, check, detail } of failures) {
      const details = Array.isArray(detail) ? detail : [detail];
      console.error(`- ${page} @ ${width}px [${check}]`);
      for (const line of details.slice(0, 6)) console.error(`    ${line}`);
      if (details.length > 6) console.error(`    ... ${details.length - 6} more`);
    }
    process.exit(1);
  }
  console.log(`Ledger routes browser passed: ${checks} checks; screenshots ${output}`);
})().catch(error => { console.error(error); process.exit(1); });
