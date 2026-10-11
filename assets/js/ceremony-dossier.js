(function () {
  'use strict';
  var key = 'LunaraCeremonyDossier', blocks = new Set(), io;
  if (window[key]) { window[key].mount(document); return; }
  function mount(root) {
    root = root || document;
    if (!window.IntersectionObserver || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    if (!io) io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (blocks.has(entry.target) && entry.isIntersecting) {
          entry.target.classList.add('aat-inview'); io.unobserve(entry.target);
        }
      });
    }, {threshold:0, rootMargin:'0px 0px -60px 0px'});
    var s = '.aat-ceremony-dossier > :is(section:not(.aat-ceremony-dossier-hero),nav,div.aat-hub-section)';
    var targets = Array.from(root.querySelectorAll(s));
    if (root.matches && root.matches(s)) targets.push(root);
    targets.forEach(function (block) {
      if (blocks.has(block)) return;
      blocks.add(block); block.classList.add('aat-dossier-reveal'); io.observe(block);
    });
  }
  function teardown(root) {
    blocks.forEach(function (block) {
      if (!root || root.contains(block)) {
        io.unobserve(block); blocks.delete(block); block.classList.remove('aat-dossier-reveal', 'aat-inview');
      }
    });
    if (io && !blocks.size) io.disconnect();
  }
  window[key] = {mount:mount, teardown:teardown};
  if (window.LunaraApp && typeof window.LunaraApp.register === 'function') {
    window.LunaraApp.register('ceremony-dossier', function (root) { mount(root); return function () { teardown(root); }; });
  } else {
    ['mount', 'teardown'].forEach(function (event) {
      window.addEventListener('lunara:page:' + event, function (e) { window[key][event](e.detail && e.detail.root); });
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { mount(document); }, {once:true});
    else mount(document);
  }
})();
