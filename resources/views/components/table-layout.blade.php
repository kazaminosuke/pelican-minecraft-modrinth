<script data-navigate-once>
    (() => {
        'use strict';

        // Everything about the table's vertical layout is expressed in CSS
        // (see MinecraftModrinthPlugin's injected stylesheet): .fi-ta-main is
        // a fixed-height flex column, the row viewport takes the slack, and
        // the paginator is the last auto-sized item in that column. The only
        // value CSS cannot derive on its own is where the table starts, so
        // that is all this script contributes.
        //
        // The measurement is deliberately taken in DOCUMENT coordinates and
        // of an element whose own height it does not affect. Both properties
        // matter: the previous implementation derived the row viewport from
        // document.scrollHeight and window.scrollY, which made the result a
        // function of how far the page happened to be scrolled at the moment
        // a re-render fired, and made each pass change the input to the next
        // one. Running this one repeatedly cannot converge on a different
        // answer, so the number of triggers stops being a correctness
        // concern.
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

        const measure = (caller = 'unknown') => {
            const wrapper = document.querySelector(WRAPPER_SELECTOR);

            if (!wrapper) {
                return;
            }

            // Measure the element that actually carries the height. Reading
            // the wrapper instead would fold in whatever schema markup
            // Filament nests in between, which differs per Filament release.
            const target = wrapper.querySelector('.fi-ta-main') ?? wrapper;
            const rect = target.getBoundingClientRect();

            if (target.getClientRects().length === 0) {
                debugLog(`measure skipped (from: ${caller})`, { reason: 'not-rendered' });

                return;
            }

            // rect.top falls by exactly as much as scrollY rises, so this sum
            // is the same number whether the page sits at the top or at the
            // bottom. That is what makes repeated measurement idempotent.
            const top = Math.round(rect.top + window.scrollY);

            if (!Number.isFinite(top) || top < 0) {
                debugLog(`measure skipped (from: ${caller})`, { reason: 'implausible-offset', top });

                return;
            }

            const previous = Number(root.dataset.mmrTableTop ?? NaN);

            // A scrollbar appearing, a font swapping in or a sub-pixel
            // rounding difference would otherwise rewrite the variable on
            // every observer callback, and each rewrite resizes the table,
            // which calls the observer again.
            if (Number.isFinite(previous) && Math.abs(previous - top) < 1) {
                return;
            }

            root.dataset.mmrTableTop = String(top);
            root.style.setProperty('--mmr-table-top', `${top}px`);

            debugLog(`measured (from: ${caller})`, {
                top,
                previous: Number.isFinite(previous) ? previous : null,
                mainHeight: Math.round(rect.height),
            });
        };

        // Filament renders no offset of its own here, so this margin only
        // ever exists as an inline style, which means Livewire's morph strips
        // it every time the server re-renders the pagination - that is what
        // makes the page buttons jump sideways mid-revalidation. Unrelated to
        // the height reservation above, but it shares the same triggers.
        const restorePaginationOffset = (caller = 'unknown') => {
            const all = document.querySelectorAll(`${WRAPPER_SELECTOR} .fi-pagination-items`);
            const outcomes = [];

            all.forEach((items) => {
                const paginationItems = Array.from(items.children);
                const previous = paginationItems.find((item) => item.matches('.fi-pagination-item[rel="prev"]'));

                if (previous) {
                    window.mmrPaginationPreviousWidth = previous.getBoundingClientRect().width;
                    outcomes.push(`has-prev:measured ${Math.round(window.mmrPaginationPreviousWidth)}px`);

                    return;
                }

                if (items.dataset.mmrPaginationPreviousSpace === 'true') {
                    outcomes.push(`already-offset:${items.style.marginInlineStart || '(no inline style!)'}`);

                    return;
                }

                const next = paginationItems.find((item) => item.matches('.fi-pagination-item[rel="next"]'));

                if (!next) {
                    outcomes.push('bailed:no-next-button');

                    return;
                }

                const width = window.mmrPaginationPreviousWidth ?? next.getBoundingClientRect().width;

                if (width === 0) {
                    outcomes.push('bailed:zero-width');

                    return;
                }

                items.style.marginInlineStart = `${width}px`;
                items.dataset.mmrPaginationPreviousSpace = 'true';
                outcomes.push(`applied ${Math.round(width)}px`);
            });

            if (all.length > 0) {
                debugLog(`restorePaginationOffset (from: ${caller})`, { matched: all.length, outcomes });
            }
        };

        const refresh = (caller = 'unknown') => {
            measure(caller);
            restorePaginationOffset(caller);
        };

        let bodyObserver = null;
        let morphHookRegistered = false;

        // A morph can strip the pagination offset and can replace the markup
        // above the table in the same update, so both halves run again.
        // Livewire may or may not have booted by the time this end-of-body
        // script runs, hence both the direct attempt and the event.
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
            // collapsing, the installed-status badge appearing, the header
            // wrapping onto a second line - moves where the table starts.
            // Applying the result changes the body's height too, so this
            // observer does re-enter once; measure()'s no-change guard is
            // what stops it there.
            bodyObserver = new ResizeObserver(() => measure('resize-observer'));
            bodyObserver.observe(document.body);
        };

        const init = () => {
            registerMorphHook();
            refresh('init');
            observeBody();
        };

        window.__mmrTableLayout = { measure, restorePaginationOffset, refresh };

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
