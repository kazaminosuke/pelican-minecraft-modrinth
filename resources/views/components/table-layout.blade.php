<script data-navigate-once>
    (() => {
        'use strict';

        // Everything about the table's vertical layout is expressed in CSS
        // (see MinecraftModrinthPlugin's injected stylesheet): .fi-ta-main is
        // a fixed-height flex column, the row viewport takes the slack, and
        // the paginator is the last auto-sized item in that column. The three
        // numbers CSS cannot derive on its own are all this script supplies.
        //
        // Every one of them is measured so that (a) it does not depend on how
        // far the page is scrolled and (b) applying the result cannot change
        // what the next measurement sees. Both properties matter: the version
        // this replaced derived the row viewport from document.scrollHeight
        // and window.scrollY, so the answer moved with the scroll position and
        // each pass fed the next. Because these readings are idempotent, how
        // many things trigger a re-measure stops being a correctness concern.
        const WRAPPER_SELECTOR = '.mmr-table-scroll-ctn';
        const DEBUG_STORAGE_KEY = 'mmrSwrDebug';

        if (window.__mmrTableLayout) {
            window.__mmrTableLayout.refresh('re-execute');

            return;
        }

        const root = document.documentElement;

        const debugEnabled = () => {
            try {
                return window.localStorage.getItem(DEBUG_STORAGE_KEY) === '1';
            } catch (_error) {
                return false;
            }
        };

        const debugLog = (event, detail = {}) => {
            if (!debugEnabled()) {
                return;
            }

            console.info(`[mmr-layout +${Math.round(window.performance?.now?.() ?? 0)}ms] ${event}`, detail);
        };

        // The page's trailing chrome below the table - Filament's page
        // container carries a bottom padding, and a panel may add more.
        // Guessing at it is what left the document 64px taller than the
        // viewport, so it is measured too.
        //
        // The catch is that .fi-body is min-h-dvh, so while the page fits, the
        // body's box is held open to the viewport and reading its bottom would
        // report the slack rather than the real trailing space - and a reading
        // that is too large shrinks the table, which keeps the page fitting,
        // which keeps the reading too large. Forcing the table tall for the
        // duration of the read takes that clamp out of the picture. It happens
        // within one task, so nothing is painted in between, and the document
        // ends up the size it started at, so the scroll position is untouched.
        const measureTrailingSpace = (target, viewport) => {
            const previousHeight = target.style.height;
            target.style.height = `${Math.max(viewport * 2, 2000)}px`;

            const space = Math.round(
                document.body.getBoundingClientRect().bottom - target.getBoundingClientRect().bottom,
            );

            target.style.height = previousHeight;

            return Math.max(space, 0);
        };

        const measure = (caller = 'unknown') => {
            const wrapper = document.querySelector(WRAPPER_SELECTOR);

            if (!wrapper) {
                return;
            }

            // Measure the element that actually carries the height. Reading
            // the wrapper instead would fold in whatever schema markup
            // Filament nests in between, which differs per Filament release.
            const target = wrapper.querySelector('.fi-ta-main') ?? wrapper;

            if (target.getClientRects().length === 0) {
                debugLog(`measure skipped (from: ${caller})`, { reason: 'not-rendered' });

                return;
            }

            // clientHeight, not innerHeight: it excludes a horizontal
            // scrollbar, and it is the same box the CSS viewport units resolve
            // against, so the arithmetic below stays exact.
            const viewport = root.clientHeight;
            const rect = target.getBoundingClientRect();

            // rect.top falls by exactly as much as scrollY rises, so this sum
            // is the same number whether the page sits at the top or at the
            // bottom. That is what makes repeated measurement idempotent.
            const top = Math.round(rect.top + window.scrollY);
            const bottom = measureTrailingSpace(target, viewport);

            if (!Number.isFinite(top) || top < 0 || !Number.isFinite(viewport) || viewport <= 0) {
                debugLog(`measure skipped (from: ${caller})`, { reason: 'implausible-geometry', top, viewport });

                return;
            }

            const previous = {
                top: Number(root.dataset.mmrTableTop ?? NaN),
                bottom: Number(root.dataset.mmrTableBottom ?? NaN),
                viewport: Number(root.dataset.mmrViewportHeight ?? NaN),
            };

            // A scrollbar appearing, a font swapping in or a sub-pixel
            // rounding difference would otherwise rewrite the variables on
            // every observer callback, and each rewrite resizes the table,
            // which calls the observer again.
            const unchanged = (was, now) => Number.isFinite(was) && Math.abs(was - now) < 1;

            if (unchanged(previous.top, top) && unchanged(previous.bottom, bottom) && unchanged(previous.viewport, viewport)) {
                return;
            }

            root.dataset.mmrTableTop = String(top);
            root.dataset.mmrTableBottom = String(bottom);
            root.dataset.mmrViewportHeight = String(viewport);
            root.style.setProperty('--mmr-table-top', `${top}px`);
            root.style.setProperty('--mmr-table-bottom', `${bottom}px`);
            root.style.setProperty('--mmr-viewport-height', `${viewport}px`);

            debugLog(`measured (from: ${caller})`, {
                top,
                bottom,
                viewport,
                previous,
                // Zero once the layout has settled; anything else means the
                // page is still able to scroll.
                documentOverflow: Math.max(root.scrollHeight - viewport, 0),
            });
        };

        const refresh = (caller = 'unknown') => {
            measure(caller);
        };

        let bodyObserver = null;
        let morphHookRegistered = false;

        // A morph can replace the markup above the table - the installed
        // status badge appearing is the common case - which moves where the
        // table starts. Livewire may or may not have booted by the time this
        // end-of-body script runs, hence both the direct attempt and the event.
        const registerMorphHook = () => {
            if (morphHookRegistered || typeof window.Livewire?.hook !== 'function') {
                return;
            }

            morphHookRegistered = true;
            window.Livewire.hook('morphed', () => refresh('morphed'));
        };

        const observeBody = () => {
            if (bodyObserver || typeof window.ResizeObserver !== 'function' || !document.body) {
                return;
            }

            // Anything above the table changing height - the sidebar
            // collapsing, the header wrapping onto a second line - moves where
            // the table starts. Applying the result changes the body's height
            // too, so this observer does re-enter once; the no-change guard
            // above is what stops it there.
            bodyObserver = new ResizeObserver(() => refresh('resize-observer'));
            bodyObserver.observe(document.body);
        };

        const init = () => {
            registerMorphHook();
            refresh('init');
            observeBody();
        };

        window.__mmrTableLayout = { measure, refresh };

        window.addEventListener('resize', () => refresh('window-resize'));
        document.addEventListener('livewire:init', registerMorphHook);

        document.addEventListener('livewire:navigated', () => {
            registerMorphHook();
            refresh('navigated');
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init, { once: true });
        } else {
            init();
        }
    })();
</script>
