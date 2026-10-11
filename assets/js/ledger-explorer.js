/*
 * Oscar Ledger Explorer: instant filtering and the hero search.
 *
 * The page is complete without this file. With it, every filter change,
 * chip, pager or "Explore" link fetches the same URL with fragment=1 and
 * swaps in the server-rendered regions, so there is one renderer (PHP) and
 * every view keeps a real, shareable URL. The search box is an ARIA 1.2
 * combobox over /wp-json/lunara-ledger/v1/search; API text is only ever
 * written with textContent.
 */
(function () {
    'use strict';

    if (window.LunaraLedgerExplorer) {
        window.LunaraLedgerExplorer.mount(document);
        return;
    }
    var instance = null;

    function createExplorer(root) {
    if (!root || !window.fetch || !window.history || !window.history.pushState || !window.URLSearchParams || !window.URL) {
        return function () {};
    }

    var disposed = false;
    var listeners = [];
    var requestId = 0;
    var blurTimer = null;
    function listen(target, name, callback) {
        target.addEventListener(name, callback);
        listeners.push(function () { target.removeEventListener(name, callback); });
    }
    function dispose() {
        disposed = true;
        requestId++;
        if (pending) { pending.abort(); }
        if (searching) { searching.abort(); }
        if (manifestRequest) { manifestRequest.abort(); }
        window.clearTimeout(timer);
        window.clearTimeout(blurTimer);
        listeners.forEach(function (remove) { remove(); });
        listeners = [];
        setBusy(false);
    }

    var base = root.getAttribute('data-lle-base');
    var searchUrl = root.getAttribute('data-lle-search');
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var KIND = { person: 'Person', film: 'Film', company: 'Company', category: 'Category', ceremony: 'Ceremony' };
    var regions = {};
    ['filters', 'chips', 'debrief', 'status', 'results', 'pager'].forEach(function (name) {
        regions[name] = root.querySelector('[data-lle-region="' + name + '"]');
    });

    var pending = null;
    var manifestRequest = null;
    var snapshots = {};
    var snapshotConfig = {};
    try { snapshotConfig = JSON.parse(root.getAttribute('data-lle-snapshot') || '{}'); } catch (error) {}

    function abortable() {
        return 'AbortController' in window ? new AbortController() : null;
    }

    function freshSnapshot(stamp) {
        var age = Date.now() / 1000 - Number(stamp && stamp.created_at);
        return stamp && stamp.schema === 1 && stamp.generation === snapshotConfig.generation && age >= -5 && age <= Math.min(300, Number(snapshotConfig.max_age) || 300);
    }
    function trustedSnapshotUrl(url) {
        try {
            var target = new URL(url);
            var manifest = new URL(snapshotConfig.url);
            return /^https?:$/.test(target.protocol) && target.origin === manifest.origin && target.pathname.slice(0, target.pathname.lastIndexOf('/') + 1) === manifest.pathname.slice(0, manifest.pathname.lastIndexOf('/') + 1) && /\/[a-f0-9]{64}\.json$/.test(target.pathname) && !target.search;
        } catch (error) { return false; }
    }
    function readJson(url, signal, isStatic) {
        return fetch(url, { headers: { Accept: 'application/json' }, credentials: isStatic ? 'omit' : 'same-origin', cache: isStatic ? 'force-cache' : 'default', signal: signal }).then(function (response) {
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.json();
        });
    }
    function fragment(url, signal) {
        var entry = snapshots[url];
        var fallback = function () { return readJson(fragmentUrl(url), signal, false); };
        if (!entry || !trustedSnapshotUrl(entry.url) || !freshSnapshot({schema:1, generation:snapshotConfig.generation, created_at:entry.created_at})) { return fallback(); }
        return readJson(entry.url, signal, true).then(function (data) {
            if (!freshSnapshot(data && data.snapshot) || data.url !== url || !data.regions || !Object.keys(regions).every(function (name) { return typeof data.regions[name] === 'string'; })) { throw new Error('Expired or invalid snapshot'); }
            return data;
        }).catch(function (error) {
            if (disposed || (signal && signal.aborted) || (error && error.name === 'AbortError')) { throw error; }
            delete snapshots[url];
            return fallback();
        });
    }
    if (snapshotConfig.url && snapshotConfig.generation) {
        manifestRequest = abortable();
        readJson(snapshotConfig.url, manifestRequest ? manifestRequest.signal : undefined, true).then(function (data) {
            if (!disposed && freshSnapshot(data) && data.entries && typeof data.entries === 'object') { snapshots = data.entries; }
        }).catch(function () {});
    }

    function fragmentUrl(url) {
        var u = new URL(url, window.location.href);
        u.searchParams.set('fragment', '1');
        return u.toString();
    }

    function setBusy(busy) {
        if (regions.results) {
            regions.results.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
    }

    function isExplorerUrl(url) {
        try {
            var target = new URL(url, window.location.href);
            var home = new URL(base, window.location.href);
            return target.origin === home.origin && target.pathname === home.pathname;
        } catch (error) { return false; }
    }

    function historyState(url) {
        return Object.assign({}, window.history.state || {}, { url: new URL(url, window.location.href).href, lle: {
            base: new URL(base, window.location.href).pathname,
            url: new URL(url, window.location.href).href
        } });
    }

    function load(url, push, focusName) {
        if (disposed || !isExplorerUrl(url)) { return Promise.resolve(); }
        var sequence = ++requestId;
        if (pending) {
            pending.abort();
        }
        pending = abortable();
        setBusy(true);
        return fragment(url, pending ? pending.signal : undefined).then(function (data) {
            if (disposed || sequence !== requestId) { return; }
            var fresh = data && data.regions ? data.regions : {};
            if (!isExplorerUrl(data && data.url) || !Object.keys(regions).every(function (name) { return typeof fresh[name] === 'string'; })) {
                throw new Error('Invalid Explorer fragment');
            }
            Object.keys(regions).forEach(function (name) {
                if (regions[name] && typeof fresh[name] === 'string') {
                    regions[name].innerHTML = fresh[name];
                }
            });
            if (data.title) {
                document.title = data.title;
            }
            var next = data.url || url;
            if (data.snapshot_url && trustedSnapshotUrl(data.snapshot_url)) { snapshots[next] = {url: data.snapshot_url, created_at: Date.now() / 1000}; }
            if (push) {
                window.history.pushState(historyState(next), '', next);
            } else {
                window.history.replaceState(historyState(next), '', next);
            }
            setBusy(false);
            if (focusName && regions.filters) {
                var control = regions.filters.querySelector('[name="' + focusName + '"]');
                if (control) {
                    control.focus({ preventScroll: true });
                }
            }
        }).catch(function (error) {
            if (disposed || sequence !== requestId || (error && error.name === 'AbortError')) {
                return;
            }
            window.location.href = url;
        });
    }

    function scrollToResults() {
        if (disposed) { return; }
        var status = root.querySelector('#lle-status');
        if (!status) {
            return;
        }
        var top = status.getBoundingClientRect().top;
        if (top < 0 || top > window.innerHeight * 0.7) {
            status.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        }
    }

    function urlFromForm(form) {
        var params = new URLSearchParams();
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.disabled) {
                return;
            }
            if (el.type === 'checkbox') {
                if (el.checked) {
                    params.set(el.name, el.value);
                }
                return;
            }
            if (el.value !== '') {
                params.set(el.name, el.value);
            }
        });
        var query = params.toString();
        return base + (query ? '?' + query : '');
    }

    function urlFromExplore(explore) {
        var params = new URLSearchParams();
        Object.keys(explore || {}).forEach(function (key) {
            params.set(key, String(explore[key]));
        });
        var query = params.toString();
        return base + (query ? '?' + query : '');
    }

    /* Filters: one change, one fetch. The rules mirror the server's. */

    listen(root, 'change', function (event) {
        var target = event.target;
        var form = target && target.closest ? target.closest('form[data-lle-filters]') : null;
        if (!form || !target.name) {
            return;
        }
        var clear = function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            if (field) {
                field.value = '';
            }
        };
        if (target.name === 'class') {
            clear('category');
        }
        if (target.name === 'decade') {
            clear('ceremony');
        }
        if (target.name === 'ceremony') {
            clear('decade');
        }
        load(urlFromForm(form), true, target.name);
    });

    listen(root, 'submit', function (event) {
        var form = event.target;
        if (form && form.matches && form.matches('form[data-lle-filters]')) {
            event.preventDefault();
            load(urlFromForm(form), true);
        }
    });

    listen(root, 'click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[data-lle-nav]') : null;
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        load(link.href, true).then(scrollToResults);
    });

    window.history.replaceState(historyState(window.location.href), '', window.location.href);
    listen(window, 'popstate', function (event) {
        var state = event.state && event.state.lle;
        if (!disposed && root.isConnected && state && state.base === new URL(base, window.location.href).pathname && state.url === window.location.href && isExplorerUrl(window.location.href)) {
            load(window.location.href, false);
        }
    });

    /* Hero search: an ARIA 1.2 combobox. */

    var form = root.querySelector('[data-lle-search-form]');
    var input = root.querySelector('#lle-q');
    var list = root.querySelector('#lle-suggest');
    if (!form || !input || !list || !searchUrl) {
        return dispose;
    }

    var results = [];
    var active = -1;
    var timer = null;
    var searching = null;
    var shownFor = '';

    // While the list is open the hero rises above the sticky filter bar, which
    // otherwise covers the lower suggestions; closed, the bar stays on top.
    function openList() {
        list.hidden = false;
        root.classList.add('is-suggesting');
        input.setAttribute('aria-expanded', 'true');
    }

    function closeList() {
        list.hidden = true;
        root.classList.remove('is-suggesting');
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function highlight(index) {
        var options = list.querySelectorAll('.lle-suggest__item');
        Array.prototype.forEach.call(options, function (option, i) {
            option.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });
        active = index;
        if (options[index]) {
            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        }
    }

    // A plate for a result with no artwork: up to two initials, leading
    // articles skipped, the same rule as the server's plates.
    function initials(result) {
        if (result.type === 'category' || result.type === 'ceremony') {
            return '\u2605';
        }
        var words = String(result.name || '').split(/[^\p{L}\p{N}]+/u).filter(Boolean);
        if (words.length > 1 && /^(the|a|an)$/i.test(words[0])) {
            words.shift();
        }
        if (!words.length) {
            return '\u2605';
        }
        var mark = words[0].charAt(0);
        if (words.length > 1) {
            mark += words[words.length - 1].charAt(0);
        }
        return mark.toUpperCase();
    }

    function thumb(result) {
        var box = document.createElement('span');
        box.className = 'lle-suggest__thumb';
        box.setAttribute('aria-hidden', 'true');
        if (typeof result.image === 'string' && /^https:\/\//.test(result.image)) {
            var img = document.createElement('img');
            img.alt = '';
            img.width = 32;
            img.height = 48;
            img.decoding = 'async';
            img.src = result.image;
            box.appendChild(img);
        } else {
            box.className += ' is-plate';
            box.textContent = initials(result);
        }
        return box;
    }

    function meta(result) {
        if (!result.nominations) {
            return '';
        }
        var text = result.nominations + (result.nominations === 1 ? ' nomination' : ' nominations');
        if (result.wins) {
            text += ' · ' + result.wins + (result.wins === 1 ? ' win' : ' wins');
        }
        return text;
    }

    function render(found, query) {
        results = found;
        shownFor = query;
        active = -1;
        list.textContent = '';
        if (!found.length) {
            var empty = document.createElement('li');
            empty.className = 'lle-suggest__empty';
            empty.textContent = 'No person, film, company, category or year matches.';
            list.appendChild(empty);
            openList();
            return;
        }
        found.forEach(function (result, index) {
            var option = document.createElement('li');
            option.id = 'lle-option-' + index;
            option.className = 'lle-suggest__item';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.appendChild(thumb(result));
            [['lle-suggest__kind', KIND[result.type] || result.type], ['lle-suggest__name', result.name], ['lle-suggest__meta', meta(result)]].forEach(function (part) {
                var span = document.createElement('span');
                span.className = part[0];
                span.textContent = part[1];
                option.appendChild(span);
            });
            option.setAttribute('data-lle-option', String(index));
            list.appendChild(option);
        });
        openList();
    }

    listen(list, 'mousedown', function (event) {
        var option = event.target.closest('[data-lle-option]');
        if (option && list.contains(option)) {
            event.preventDefault();
            choose(Number(option.getAttribute('data-lle-option')));
        }
    });

    function suggest(query) {
        if (disposed) { return; }
        if (searching) {
            searching.abort();
        }
        searching = abortable();
        var url = new URL(searchUrl, window.location.href);
        url.searchParams.set('q', query);
        url.searchParams.set('limit', '8');
        fetch(url.toString(), { headers: { Accept: 'application/json' }, signal: searching ? searching.signal : undefined })
            .then(function (response) {
                return response.ok ? response.json() : { results: [] };
            })
            .then(function (data) {
                if (!disposed && input.value.trim() === query) {
                    render((data && data.results) || [], query);
                }
            })
            .catch(function () {});
    }

    function choose(index) {
        var result = results[index];
        if (!result) {
            return;
        }
        closeList();
        input.value = '';
        results = [];
        load(urlFromExplore(result.explore), true).then(scrollToResults);
    }

    listen(input, 'input', function () {
        window.clearTimeout(timer);
        var query = input.value.trim();
        if (query.length < 2) {
            results = [];
            closeList();
            return;
        }
        timer = window.setTimeout(function () {
            suggest(query);
        }, 160);
    });

    listen(input, 'keydown', function (event) {
        if (event.key === 'Escape') {
            closeList();
            return;
        }
        if (list.hidden || !results.length) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlight((active + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlight((active - 1 + results.length) % results.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(active >= 0 ? active : 0);
        }
    });

    listen(input, 'blur', function () {
        window.clearTimeout(blurTimer);
        blurTimer = window.setTimeout(function () { if (!disposed) { closeList(); } }, 120);
    });

    listen(form, 'submit', function (event) {
        event.preventDefault();
        var query = input.value.trim();
        if (!query) {
            return;
        }
        if (results.length && shownFor === query) {
            choose(active >= 0 ? active : 0);
            return;
        }
        closeList();
        load(base + '?q=' + encodeURIComponent(query), true).then(scrollToResults);
    });
    return dispose;
    }

    function mount(scope) {
        scope = scope || document;
        var root = scope.matches && scope.matches('[data-lle-root]') ? scope : scope.querySelector('[data-lle-root]');
        if (instance && instance.root === root && root && root.isConnected) { return; }
        if (instance) { instance.dispose(); instance = null; }
        if (root) { instance = { root: root, dispose: createExplorer(root) }; }
    }
    function teardown(scope) {
        if (instance && (!scope || scope === instance.root || (scope.contains && scope.contains(instance.root)))) {
            instance.dispose();
            instance = null;
        }
    }
    window.LunaraLedgerExplorer = { mount: mount, teardown: teardown };
    if (window.LunaraApp && typeof window.LunaraApp.register === 'function') {
        window.LunaraApp.register('ledger-explorer', function (scope) {
            mount(scope);
            return function () { teardown(scope); };
        });
    } else {
        window.addEventListener('lunara:page:mount', function (event) { mount(event.detail && event.detail.root); });
        window.addEventListener('lunara:page:teardown', function (event) { teardown(event.detail && event.detail.root); });
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { mount(document); }, { once: true }); }
        else { mount(document); }
    }
})();
