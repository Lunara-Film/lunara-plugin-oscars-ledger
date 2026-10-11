/** The server-rendered dossier stays visible without JS and after teardown. */
(function () {
    'use strict';
    if (window.LunaraCeremonyDossier) { window.LunaraCeremonyDossier.mount(document); return; }
    var instances = new Map();
    function mount(scope) {
        scope = scope || document;
        if (!('IntersectionObserver' in window) || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) { return; }
        var selector = '.aat-ceremony-dossier > section:not(.aat-ceremony-dossier-hero), .aat-ceremony-dossier > nav, .aat-ceremony-dossier > div.aat-hub-section';
        var blocks = Array.from(scope.querySelectorAll(selector));
        if (scope.matches && scope.matches(selector)) { blocks.unshift(scope); }
        blocks.forEach(function (block) {
            if (instances.has(block)) { return; }
            var active = true;
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (active && entry.isIntersecting) { entry.target.classList.add('aat-inview'); io.unobserve(entry.target); }
                });
            }, {threshold:0,rootMargin:'0px 0px -60px 0px'});
            block.classList.add('aat-dossier-reveal');
            io.observe(block);
            instances.set(block, function () { active = false; io.disconnect(); block.classList.remove('aat-dossier-reveal', 'aat-inview'); });
        });
    }
    function teardown(scope) {
        instances.forEach(function (cleanup, block) {
            if (!scope || scope === block || (scope.contains && scope.contains(block))) { cleanup(); instances.delete(block); }
        });
    }
    window.LunaraCeremonyDossier = {mount:mount,teardown:teardown};
    if (window.LunaraApp && typeof window.LunaraApp.register === 'function') {
        window.LunaraApp.register('ceremony-dossier', function (scope) { mount(scope); return function () { teardown(scope); }; });
    } else {
        window.addEventListener('lunara:page:mount', function (event) { mount(event.detail && event.detail.root); });
        window.addEventListener('lunara:page:teardown', function (event) { teardown(event.detail && event.detail.root); });
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { mount(document); }, {once:true}); }
        else { mount(document); }
    }
})();
