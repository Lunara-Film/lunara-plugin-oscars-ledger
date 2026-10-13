/* Production motion markup and scripts; controlled observers verify disposal. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright-core');
const root=path.resolve(__dirname,'..');
const motion=execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'fixtures/editorial-surfaces.php'),'motion'],{encoding:'utf8'});
const scripts=['ledger-motion','ceremony-dossier'].map(name=>fs.readFileSync(path.join(root,'assets/js/'+name+'.js'),'utf8'));
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});let checks=0;
 try{
  for(const reduced of [false,true]){
   const context=await browser.newContext({viewport:{width:390,height:900},reducedMotion:reduced?'reduce':'no-preference'});const page=await context.newPage();
   await page.setContent('<main id="scope">'+motion+'<div class="aat-ceremony-dossier"><section class="aat-ceremony-dossier-hero">Title</section><section>Complete factual record</section><nav>Categories</nav></div></main>');
   await page.evaluate(registry=>{
    window.fixtureObservers=[];window.fixtureFrames=new Map();let next=0;
    window.IntersectionObserver=class{constructor(callback){this.callback=callback;this.targets=new Set();this.disconnected=false;window.fixtureObservers.push(this);}observe(el){this.targets.add(el);}unobserve(el){this.targets.delete(el);}disconnect(){this.disconnected=true;this.targets.clear();}};
    window.requestAnimationFrame=callback=>{const id=++next;window.fixtureFrames.set(id,callback);return id;};
    window.cancelAnimationFrame=id=>window.fixtureFrames.delete(id);
    if(registry){window.fixtureCleanups=[];window.LunaraApp={register(name,mount){window.fixtureCleanups.push(mount(document,{root:document}));}};}
   },reduced);
   for(const content of scripts)await page.addScriptTag({content});
   const sections=await page.locator('[data-aat-motion]').count();
   await page.evaluate(()=>{window.LunaraLedgerMotion.mount(document);window.LunaraCeremonyDossier.mount(document);});
   assert.equal(await page.locator('.aat-motion-caption').count(),sections,'Repeated mount never duplicates phone captions');checks++;
   assert.equal(await page.locator('[data-aat-motion].is-armed').count(),reduced?0:sections,'Reduced motion keeps finished records visible');checks++;
   assert.equal(await page.evaluate(()=>window.fixtureObservers.filter(io=>[...io.targets].some(el=>el.closest('.aat-ceremony-dossier'))).length),reduced?0:1,'Ceremony sections share one observer without observing reduced-motion content');checks++;
   await page.evaluate(()=>{const section=document.querySelector('.aat-ceremony-dossier > section:not(.aat-ceremony-dossier-hero)');window.LunaraCeremonyDossier.teardown(section);window.LunaraCeremonyDossier.mount(section);});
   assert.equal(await page.locator('.aat-dossier-reveal').count(),reduced?0:2,'A section-scoped remount includes that root while leaving the hero visible');checks++;
   await page.locator('.aat-motion-link').first().click();
   assert.equal(await page.locator('.aat-motion-caption.has-pick').count(),1,'Phone tap still shows a caption');checks++;
   await page.evaluate(()=>{for(const observer of window.fixtureObservers){observer.callback([...observer.targets].map(target=>({target,isIntersecting:true})));}});
   await page.evaluate(registry=>{
    if(registry){window.fixtureCleanups.forEach(cleanup=>cleanup());}
    else{window.dispatchEvent(new CustomEvent('lunara:page:teardown',{detail:{root:document.querySelector('#scope')}}));}
   },reduced);
   const state=await page.evaluate(()=>({captions:document.querySelectorAll('.aat-motion-caption').length,armed:document.querySelectorAll('.is-armed,.is-playing,.aat-dossier-reveal,.aat-inview,.is-selected').length,frames:window.fixtureFrames.size,observers:window.fixtureObservers.every(io=>io.disconnected),counts:[...document.querySelectorAll('[data-aat-count]')].every(el=>el.textContent===el.dataset.aatCount)}));
   assert.deepEqual(state,{captions:0,armed:0,frames:0,observers:true,counts:true},'Teardown cancels work and restores the final readable picture');checks++;
   await page.evaluate(()=>{window.LunaraLedgerMotion.mount(document);window.LunaraCeremonyDossier.mount(document);});
   assert.equal(await page.locator('.aat-motion-caption').count(),sections,'A replaced-page mount remains functional');checks++;
   await context.close();
  }
  console.log('Ledger motion lifecycle runtime passed: '+checks+' checks.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
