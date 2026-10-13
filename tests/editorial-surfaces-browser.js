/* Computed presentation contracts. Explorer records/filters and award diagrams
 * come from the real PHP renderers; the other component fixtures stress the
 * template class combinations without pretending to be a full WordPress site. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');
const root = path.resolve(__dirname, '..');
const output = process.env.LEDGER_VISUAL_OUTPUT || path.join(os.tmpdir(), 'lunara-ledger-editorial');
const assets = ['academy-awards-table', 'ledger-explorer', 'ledger-motion', 'ceremony-dossier', 'hub-polish', 'ballot'];
const css = assets.map(name => fs.readFileSync(path.join(root, 'assets/css', `${name}.css`), 'utf8')).join('\n');
const php = mode => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'fixtures/editorial-surfaces.php'), mode], { encoding: 'utf8' });
const command = cls => `<div class="${cls}"><span>First ceremony and complete historical span</span><strong>1931–2026</strong></div>`;
const nominee = `<ul class="aat-major-race-nominee-list">${Array.from({ length: 5 }, (_, i) => `<li><a class="aat-major-race-nominee-primary" href="#record-${i}">A very long film title with several names, ${i + 1}</a><span class="aat-major-race-nominee-secondary">Writer, composer and producing company</span></li>`).join('')}</ul>`;
const record = `<article class="aat-ledger-card aat-category-ceremony-row"><div class="aat-category-ceremony-meta"><a href="#ceremony" class="aat-timeline-link">98th Academy Awards</a></div><div><h3 class="aat-category-history-title">The complete winning title remains readable</h3><p class="aat-category-history-meta">Nominee names and company credits remain in the record.</p><a class="aat-winner-circle-action" href="#history">Company history</a></div></article>`;
const panels = `<main class="aat-container aat-hub-page">
<h1>The Oscar Ledger</h1><div class="aat-hub-page-index-grid"><article class="aat-hub-card"><h2 class="aat-hub-card-title">The complete ceremony index</h2><p class="aat-hub-card-type">Ceremony · 2026</p><a href="#first" class="aat-hub-card-action">Open ceremony</a></article></div>
<section class="aat-category-dossier aat-premium-category-dossier"><div class="aat-dossier-hero"><div class="aat-dossier-hero-copy"><h2 class="aat-hub-title">A long category name with real historical context</h2><p class="aat-hub-subtitle">The record retains the full category history and all nominees.</p></div><div class="aat-dossier-command-band">${command('aat-dossier-command-card is-latest')}${command('aat-dossier-command-card')}</div></div>${record}</section>
<section class="aat-category-dossier aat-generic-category-dossier"><div class="aat-generic-category-hero"><h2 class="aat-hub-title">A technical category</h2><div class="aat-dossier-command-band">${command('aat-dossier-command-card')}</div></div>${record}</section>
<section class="aat-ceremony-dossier"><div class="aat-ceremony-dossier-hero"><div class="aat-ceremony-hero-copy"><h2 class="aat-hub-title">The 98th Academy Awards</h2></div><div class="aat-ceremony-command-band">${command('aat-ceremony-command-card')}</div></div><section class="aat-ceremony-major-races"><article class="aat-major-race-card"><h3 class="aat-major-race-title">Best Picture</h3>${nominee}</article></section></section></main>`;
const entity = type => `<main class="aat-container aat-entity-page aat-profile-file is-${type}"><section class="aat-entity-hero"><div class="aat-entity-poster-wrap is-${type}"><div class="aat-entity-poster-fallback">${type}</div></div><div class="aat-entity-hero-copy"><h1 class="aat-entity-title">A complete ${type} profile with a long name</h1><p class="aat-entity-overview">Complete film, company and person history remains available with every credit and result.</p><div class="aat-profile-command-band">${command('aat-profile-command-card')}${command('aat-profile-command-card')}</div><div class="aat-entity-actions"><a class="aat-btn aat-btn-primary" href="#complete">Open the complete record</a></div></div></section><section class="aat-entity-timeline"><article class="aat-timeline-card"><h2>2026</h2><div class="aat-history-item is-winner"><p class="aat-history-line">Full factual record and winner status</p></div></article></section></main>`;
const tracker = `<main class="aat-container aat-tracker-v2"><h1>Awards tracker</h1><div class="aat-tracker-season-selector"><label for="season">Season</label><select id="season" class="aat-tracker-season-select"><option>99th Academy Awards, 2027</option></select></div><div class="aat-tracker-tabs"><button class="aat-tracker-tab active">Predictions</button><button class="aat-tracker-tab">Watchlist</button></div><section class="aat-tracker-category"><h2 class="aat-tracker-category-title">Best Picture</h2><div class="aat-tracker-picks"><article class="aat-tracker-pick"><div class="aat-tracker-poster">Film</div><div><a class="aat-tracker-pick-title" href="#film">The complete title of a future film, without truncation</a><p class="aat-tracker-panel-kicker">The reasoning stays readable.</p></div></article></div></section></main>`;
const fixtures = { panels, title: entity('title'), person: entity('person'), company: entity('company'), tracker, explorer: php('explorer'), motion: php('motion') };
const surfaces = '.aat-hub-card,.aat-dossier-hero,.aat-dossier-command-card,.aat-ceremony-dossier-hero,.aat-ceremony-command-card,.aat-major-race-card,.aat-major-race-nominee-list li,.aat-entity-hero,.aat-profile-command-card,.aat-timeline-card,.aat-history-item,.aat-tracker-category,.aat-tracker-pick,.lle-hero,.lle-debrief';
const labels = '.aat-hub-card-type,.aat-hub-card-action,.aat-dossier-command-card > span,.aat-ceremony-command-card > span,.aat-major-race-nominee-secondary,.aat-profile-command-card > span,.lle-field__label,.lle-row__sub';

(async () => {
 fs.mkdirSync(output, { recursive: true });
 const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
 let checks = 0;
 try {
  for (const width of [320, 390, 768, 1440]) {
   const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
   const page = await context.newPage();
   await page.route('**/*', route => route.request().resourceType()==='image'
     ? route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="200" height="300"><rect width="200" height="300" fill="#142a3d"/><path d="M0 200L200 70V300H0Z" fill="#29465a"/></svg>'})
     : route.abort());
   for (const [name, html] of Object.entries(fixtures)) {
    await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>html{font-size:16px}body{margin:0;background:#0a1520;color:#e6eef7;font-family:Georgia,serif}*,*::before,*::after{box-sizing:border-box}a{color:#eadbb3}button,input,select{font:inherit}${css}</style></head><body>${html}</body></html>`);
    const measured = await page.evaluate(({surfaces,labels}) => {
      const bad = [], detail = el => `${el.className || el.tagName}: ${el.textContent.slice(0,60)}`;
      for (const el of document.querySelectorAll(surfaces)) {
       const s = getComputedStyle(el);
       if (s.backgroundImage !== 'none' || s.boxShadow !== 'none' || parseFloat(s.borderLeftWidth)>1) bad.push(`ornament ${detail(el)}`);
       if(s.opacity==='0'||s.visibility==='hidden')bad.push(`hidden record ${detail(el)}`);
      }
      for (const el of document.querySelectorAll(labels)) {
       const s = getComputedStyle(el);
       if(parseFloat(s.fontSize)<14||parseFloat(s.lineHeight)<parseFloat(s.fontSize)*1.3||!['normal','0px'].includes(s.letterSpacing))bad.push(`unreadable metadata ${detail(el)} ${s.fontSize}/${s.lineHeight}/${s.letterSpacing}`);
      }
      for(const el of document.querySelectorAll('.lle-select,.lle-search__go,.aat-winner-circle-action,.aat-tracker-tab')){
       if(el.getBoundingClientRect().height<44)bad.push(`small control ${detail(el)}`);
      }
      if(document.documentElement.scrollWidth>innerWidth+1)bad.push(`page overflow ${document.documentElement.scrollWidth}/${innerWidth}`);
      const filters=document.querySelector('.lle-filters__row');
      if(filters&&filters.scrollWidth>filters.clientWidth+1)bad.push('filters hidden behind horizontal scrolling');
      return bad;
    }, {surfaces,labels});
    assert.deepEqual(measured, [], `${name} at ${width}px`); checks++;
    if(name==='explorer'){
     assert.equal(await page.locator('.lle-row').count(),6,'Every fixture record survives the layout');
     const select=page.locator('select[name="category"]'); await select.selectOption('sound');
     assert.equal(await select.inputValue(),'sound'); await select.focus();
     assert.equal(await select.evaluate(el=>getComputedStyle(el).outlineStyle),'solid','Keyboard focus remains visible');
     const colors=await page.locator('.lle-search__go').evaluate(el=>{const s=getComputedStyle(el);return [s.color,s.backgroundColor]});
     assert.deepEqual(colors,['rgb(6, 13, 21)','rgb(201, 169, 97)'],'Gold action retains dark readable ink'); checks+=4;
    }
    if(name==='motion'){
     await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/js/ledger-motion.js'),'utf8')});
     assert.equal(await page.locator('.is-armed').count(),0,'Reduced motion never hides award records');
     assert.equal(await page.locator('.aat-motion-link').count(),9,'Ring and career arc keep every linked nomination');checks+=2;
    }
    if(width===390||width===1440)await page.screenshot({path:path.join(output,`${name}-${width}.png`),fullPage:true});
   }
   await context.close();
  }
 }finally{await browser.close()}
 console.log(`Ledger editorial browser passed: ${checks} checks; screenshots ${output}`);
})().catch(error=>{console.error(error);process.exit(1)});
