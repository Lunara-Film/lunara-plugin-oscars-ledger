/* Real Explorer JS against a deterministic HTTP transport fixture. This tests
 * lifecycle/history/fallback behavior, not a complete WordPress installation. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright-core');
const script = fs.readFileSync(path.join(__dirname, '../assets/js/ledger-explorer.js'), 'utf8');
let origin, requests = [], hold = false, release = null, invalidStatic = false;
const generation = 'fixture-generation';
const hash = 'a'.repeat(64);
const stamp = () => ({schema:1,generation,created_at:Math.floor(Date.now()/1000)});
const regions = (query = '') => ({
 filters: '<form data-lle-filters><label>Group<select name="by"><option value="">Nominations</option><option value="company">Company</option><option value="person">Person</option></select></label><button>Apply</button></form>',
 chips: '<p>Complete filters</p>', debrief: '<p>Linked film and nominee context</p>',
 status: '<p id="lle-status">125 records — '+query+'</p>',
 results: Array.from({length:25}, (_,i)=>'<article data-record="'+i+'">Record '+i+' — '+query+'</article>').join(''),
 pager: '<a data-lle-nav href="'+origin+'/oscars/explore/?pg=5">Page 5</a><a data-lle-nav href="'+origin+'/oscars/explore/?winner=1">Winners</a>'
});
const payload = query => ({url:origin+'/oscars/explore/'+query,title:'Explorer '+query,regions:regions(query)});
const root = () => `<div data-lle-root data-lle-base="${origin}/oscars/explore/" data-lle-search="${origin}/search" data-lle-snapshot='${JSON.stringify({generation,url:origin+'/snap/manifest.json',max_age:300})}'><form data-lle-search-form><label for="lle-q">Search</label><input id="lle-q" role="combobox" aria-controls="lle-suggest" aria-expanded="false"><ul id="lle-suggest" hidden></ul><button>Search</button></form>${Object.entries(regions()).map(([name,html])=>`<div data-lle-region="${name}">${html}</div>`).join('')}</div>`;
const server = http.createServer((req,res)=>{
 const url = new URL(req.url,origin); requests.push(url.pathname+url.search);
 const json = data => {res.setHeader('Cache-Control','no-store');res.setHeader('Content-Type','application/json');res.end(JSON.stringify(data));};
 if(url.pathname==='/runtime.js'){res.setHeader('Content-Type','text/javascript');res.end(script);return;}
 if(url.pathname==='/snap/manifest.json'){json({...stamp(),entries:{[origin+'/oscars/explore/?winner=1']:{url:origin+'/snap/'+hash+'.json',created_at:stamp().created_at}}});return;}
 if(url.pathname.startsWith('/snap/')){json({...payload('?winner=1'),snapshot:{...stamp(),generation:invalidStatic?'retired':generation}});return;}
 if(url.pathname==='/search'){json({results:[{type:'person',name:'<img src=x onerror=alert(1)>',nominations:12,wins:1,explore:{entity:'nm0000001'}}]});return;}
 if(url.searchParams.get('fragment')==='1'){
   url.searchParams.delete('fragment'); const finish=()=>json(payload(url.search));
   if(hold){release=finish;}else{finish();}return;
 }
 res.setHeader('Content-Type','text/html');res.end('<!doctype html><html><head><title>SSR Explorer</title></head><body><main id="main">'+root()+'</main><script>history.replaceState({swup:{id:"preserve-me"}},"",location.href)</script><script src="/runtime.js"></script></body></html>');
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
 let checks=0;
 const check=(actual,expected,message)=>{assert.deepEqual(actual,expected,message);checks++;};
 try{
  const page=await browser.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/oscars/explore/');
  await page.waitForTimeout(75);
  check(await page.locator('[data-record]').count(),25,'SSR content survives mounting');
  check(await page.evaluate(()=>history.state.swup.id),'preserve-me','Foreign history metadata survives first mount');
  await page.evaluate(()=>{for(let i=0;i<3;i++)window.dispatchEvent(new CustomEvent('lunara:page:mount',{detail:{root:document.querySelector('#main')}}));});
  requests=[];
  await page.locator('a[href$="winner=1"]').click();
  await page.waitForURL('**/?winner=1');
  check(requests.filter(u=>u.startsWith('/snap/'+hash)).length,1,'A fresh snapshot supplies the existing renderer payload');
  check(requests.filter(u=>u.includes('fragment=1')).length,0,'Warm static state avoids a WordPress fragment request');
  check(await page.evaluate(()=>history.state.swup.id),'preserve-me','Filter history preserves navigator metadata');
  check(await page.evaluate(()=>history.state.lle.base),'/oscars/explore/','Explorer history declares route ownership');
  check(await page.evaluate(()=>history.state.url),origin+'/oscars/explore/?winner=1','Navigator history URL tracks the filter destination');
  requests=[];
  await page.locator('a[href$="pg=5"]').click();await page.waitForURL('**/?pg=5');
  check(requests.filter(u=>u.includes('fragment=1')).length,1,'An arbitrary later page keeps its dynamic fallback and has one listener');
  check(await page.locator('[data-record]').count(),25,'The later page retains every fixture row');
  await page.locator('select[name=by]').selectOption('company');await page.waitForURL('**/?by=company');
  check(await page.locator('[data-lle-region=status]').textContent(),'125 records — ?by=company','Grouping still uses the canonical server transport');
  requests=[];await page.goBack();await page.waitForURL('**/?pg=5');await page.waitForFunction(()=>document.querySelector('[data-lle-region=status]').textContent.includes('?pg=5'));
  check(requests.filter(u=>u.includes('fragment=1')).length,1,'Back restores the owned Explorer state exactly once');
  await page.locator('#lle-q').fill('actor');await page.waitForSelector('[role=option]');
  check(await page.locator('[role=option] img').count(),0,'API text is rendered as text, never executable HTML');
  check(await page.locator('#lle-q').getAttribute('aria-expanded'),'true','Accessible search remains functional');
  requests=[];
  await page.evaluate(()=>{history.replaceState({swup:{id:'foreign'}},'', '/journal/');window.dispatchEvent(new PopStateEvent('popstate',{state:history.state}));});
  await page.waitForTimeout(75);
  check(requests.filter(u=>u.includes('fragment=1')).length,0,'Explorer never fetches a non-Explorer popstate');
  await page.evaluate(()=>{history.replaceState({},'', '/oscars/explore/');window.dispatchEvent(new CustomEvent('lunara:page:teardown',{detail:{root:document.querySelector('#main')}}));});
  await page.evaluate(html=>{document.querySelector('#main').innerHTML=html;window.dispatchEvent(new CustomEvent('lunara:page:mount',{detail:{root:document.querySelector('#main')}}));},root());
  await page.waitForTimeout(75);invalidStatic=true;requests=[];
  await page.locator('a[href$="winner=1"]').click();await page.waitForURL('**/?winner=1');
  check(requests.filter(u=>u.includes('fragment=1')).length,1,'Retired static generations fall back without losing content');
  hold=true;requests=[];
  await page.locator('a[href$="pg=5"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-lle-region=results]').getAttribute('aria-busy')==='true');
  await page.evaluate(()=>{window.dispatchEvent(new CustomEvent('lunara:page:teardown',{detail:{root:document.querySelector('#main')}}));document.querySelector('#main').innerHTML='<p id="new-page">Journal remains here</p>';history.replaceState({swup:{id:'new-page'}},'', '/journal/');});
  if(release)release();await page.waitForTimeout(100);
  check(await page.locator('#new-page').textContent(),'Journal remains here','Late responses cannot mutate the replacement page');
  check(await page.evaluate(()=>location.pathname),'/journal/','Late responses cannot navigate away after teardown');
  check(errors,[],'Lifecycle transitions raise no browser errors');
  await page.close();
  const nojs=await browser.newContext({javaScriptEnabled:false});const plain=await nojs.newPage();await plain.goto(origin+'/oscars/explore/');
  check(await plain.locator('[data-record]').count(),25,'The page stays complete with JavaScript disabled');await nojs.close();
  console.log('Explorer lifecycle runtime passed: '+checks+' checks.');
 }finally{await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;server.closeAllConnections();server.close();});
