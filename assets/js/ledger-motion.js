/**
 * Ledger Motion (2.8.8): plays the Nomination Ring and the Career Arc.
 *
 * The server renders the finished picture. This script hides it (.is-armed)
 * and, when the section is a third into view, plays it back once
 * (.is-playing): the CSS carries the choreography, the script only counts
 * the tally up in step. Nothing plays under prefers-reduced-motion (the phone
 * caption still works), and a section already on screen at load plays immediately.
 */
( function () {
    'use strict';
    if (window.LunaraLedgerMotion) { window.LunaraLedgerMotion.mount(document); return; }
    var instances = new Map();
    function create(section) {
        var disposed = false, timers = new Set(), frames = new Set(), removers = [], boxes = [], observer = null;
        function listen(target, type, callback, options) {
            target.addEventListener(type, callback, options);
            removers.push(function () { target.removeEventListener(type, callback, options); });
        }
        function frame(callback) {
            var id = window.requestAnimationFrame(function (at) { frames.delete(id); if (!disposed) { callback(at); } });
            frames.add(id); return id;
        }
        function later(callback, delay) {
            var id = window.setTimeout(function () { timers.delete(id); if (!disposed) { callback(); } }, delay);
            timers.add(id); return id;
        }
        function cleanup() {
            disposed = true;
            if (observer) { observer.disconnect(); }
            timers.forEach(window.clearTimeout); frames.forEach(window.cancelAnimationFrame);
            removers.forEach(function (remove) { remove(); });
            boxes.forEach(function (box) { box.remove(); });
            section.classList.remove('is-armed', 'is-playing');
            section.querySelectorAll('.is-selected').forEach(function (link) { link.classList.remove('is-selected'); });
            section.querySelectorAll('[data-aat-count]').forEach(function (el) { el.textContent = el.getAttribute('data-aat-count'); });
        }
	var still = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	var phone = window.matchMedia ? window.matchMedia( '(max-width: 640px)' ) : null;

	function countUp( el, from, to, delay, duration ) {
		if ( to <= from ) {
			el.textContent = String( to );
			return;
		}
		el.textContent = String( from );
		var start = 0;
		var step = function ( now ) {
			if ( ! start ) {
				start = now;
			}
			var p = Math.min( 1, ( now - start ) / duration );
			el.textContent = String( Math.round( from + ( to - from ) * p ) );
			if ( p < 1 ) {
				frame( step );
			}
		};
		later( function () {
			frame( step );
		}, delay );
	}

	/**
	 * On a narrow screen the arc scrolls inside its frame. While it plays, the
	 * frame pans with the career, first nomination to last; any touch, wheel or
	 * key hands control straight back to the reader. The right edge fades while
	 * there is more to see.
	 */
	function edge( stage ) {
		var more = stage.scrollLeft + stage.clientWidth < stage.scrollWidth - 4;
		stage.classList.toggle( 'has-more', more );
	}

	function pan( stage ) {
		if ( ! stage || stage.scrollWidth <= stage.clientWidth + 4 ) {
			return;
		}
		var stopped = false;
		var stop = function () { stopped = true; };
		[ 'pointerdown', 'wheel', 'touchstart', 'keydown' ].forEach( function ( type ) {
			listen( stage, type, stop, { passive: true, once: true } );
		} );
		var max = stage.scrollWidth - stage.clientWidth;
		var start = 0;
		var duration = 1700;
		var step = function ( now ) {
			if ( stopped ) {
				return;
			}
			if ( ! start ) {
				start = now;
			}
			var p = Math.min( 1, ( now - start ) / duration );
			var eased = p < 0.5 ? 2 * p * p : 1 - Math.pow( -2 * p + 2, 2 ) / 2;
			stage.scrollLeft = max * eased;
			if ( p < 1 ) {
				frame( step );
			}
		};
		later( function () {
			frame( step );
		}, 250 );
	}

	function play( section ) {
		var ignite = parseFloat( getComputedStyle( section ).getPropertyValue( '--ignite' ) ) || 2;
		var isRing = section.classList.contains( 'aat-ledger-motion--ring' );
		var spokes = section.querySelectorAll( '.aat-motion-spoke' ).length;
		var wins = section.querySelectorAll( '.aat-motion-spoke.is-win' ).length;
		var winStep = ( parseFloat( getComputedStyle( section ).getPropertyValue( '--wstep' ) ) || ( isRing ? 0.26 : 0.22 ) ) * 1000;

		// Two frames: commit the armed state, then let the transitions run.
		frame( function () {
			frame( function () {
				section.classList.add( 'is-playing' );
			} );
		} );

		if ( ! isRing ) {
			pan( section.querySelector( '.aat-motion-stage' ) );
		}

		section.querySelectorAll( '[data-aat-count]' ).forEach( function ( el ) {
			var to = parseInt( el.getAttribute( 'data-aat-count' ), 10 ) || 0;
			if ( 'ignite' === el.getAttribute( 'data-aat-count-phase' ) ) {
				countUp( el, 0, to, ignite * 1000, Math.max( 1, wins ) * winStep );
			} else {
				countUp( el, 0, to, 350, isRing ? spokes * 90 + 300 : 1500 );
			}
		} );
	}

	/**
	 * 2.8.11: phones hide the labels, so a dot alone says nothing. There the
	 * first tap on a dot names it in a caption under the drawing, with a link
	 * into its race; a second tap on the same dot (or the link) opens the race.
	 * Wider screens keep their labels and one-click links.
	 */
	function caption( section ) {
		if ( ! section.querySelector( '.aat-motion-link' ) ) {
			return;
		}
		var isRing = section.classList.contains( 'aat-ledger-motion--ring' );
		var box = document.createElement( 'div' );
		box.className = 'aat-motion-caption';
		box.setAttribute( 'role', 'status' );
		box.setAttribute( 'aria-live', 'polite' );
		var title = document.createElement( 'span' );
		title.className = 'aat-motion-caption-title';
		title.textContent = isRing ? 'Tap any dot to see the award.' : 'Tap any dot to see the nomination.';
		var meta = document.createElement( 'span' );
		meta.className = 'aat-motion-caption-meta';
		var go = document.createElement( 'a' );
		go.className = 'aat-motion-caption-go';
		go.textContent = 'Open the race \u2192';
		go.hidden = true;
		box.appendChild( title );
		box.appendChild( meta );
		box.appendChild( go );
		var stage = section.querySelector( '.aat-motion-stage' );
		if ( ! stage ) { return; }
		stage.parentNode.insertBefore( box, stage.nextSibling );
		boxes.push( box );

		var current = null;
		listen( section, 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.aat-motion-link' ) : null;
			if ( ! link || ! phone || ! phone.matches || link === current ) {
				return; // Desktop, or the second tap: follow the link.
			}
			event.preventDefault();
			if ( current ) {
				current.classList.remove( 'is-selected' );
			}
			current = link;
			link.classList.add( 'is-selected' );
			box.classList.add( 'has-pick' );
			box.classList.toggle( 'is-win', !! link.closest( '.is-win' ) );
			title.textContent = link.getAttribute( 'data-aat-cap' ) || '';
			meta.textContent = link.getAttribute( 'data-aat-cap-meta' ) || '';
			go.href = link.getAttribute( 'href' ) || link.getAttribute( 'xlink:href' ) || '';
			go.hidden = ! go.href;
		} );
	}


        caption(section);
        if (still || !('IntersectionObserver' in window)) { return cleanup; }
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!disposed && entry.isIntersecting) { observer.unobserve(entry.target); play(entry.target); }
            });
        }, {threshold:0.3});
        var stage = section.querySelector('.aat-motion-stage');
        if (stage && section.classList.contains('aat-ledger-motion--arc')) {
            edge(stage);
            listen(stage, 'scroll', function () { edge(stage); }, {passive:true});
            listen(window, 'resize', function () { edge(stage); });
        }
        section.classList.add('is-armed');
        section.querySelectorAll('[data-aat-count]').forEach(function (el) { el.textContent = '0'; });
        observer.observe(section);
        return cleanup;
    }
    function mount(scope) {
        scope = scope || document;
        var sections = Array.from(scope.querySelectorAll('[data-aat-motion]'));
        if (scope.matches && scope.matches('[data-aat-motion]')) { sections.unshift(scope); }
        sections.forEach(function (section) { if (!instances.has(section)) { instances.set(section, create(section)); } });
    }
    function teardown(scope) {
        instances.forEach(function (cleanup, section) {
            if (!scope || scope === section || (scope.contains && scope.contains(section))) { cleanup(); instances.delete(section); }
        });
    }
    window.LunaraLedgerMotion = {mount:mount,teardown:teardown};
    if (window.LunaraApp && typeof window.LunaraApp.register === 'function') {
        window.LunaraApp.register('ledger-motion', function (scope) { mount(scope); return function () { teardown(scope); }; });
    } else {
        window.addEventListener('lunara:page:mount', function (event) { mount(event.detail && event.detail.root); });
        window.addEventListener('lunara:page:teardown', function (event) { teardown(event.detail && event.detail.root); });
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { mount(document); }, {once:true}); }
        else { mount(document); }
    }
}() );
