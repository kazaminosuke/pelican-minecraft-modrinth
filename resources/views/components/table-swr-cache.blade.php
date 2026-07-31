<style>
    .mmr-table-scroll-ctn[data-mmr-swr-scope] {
        position: relative;
    }

    .mmr-table-swr-overlay {
        position: absolute;
        z-index: 18;
        overflow: hidden;
        border-radius: 0.75rem;
        background: #fff;
        pointer-events: none;
    }

    .dark .mmr-table-swr-overlay {
        background: var(--gray-900, rgb(17 24 39));
    }

    .mmr-table-swr-overlay > .fi-ta-content-ctn {
        width: 100%;
        overflow: auto;
    }
</style>

<script data-navigate-once>
    (() => {
        'use strict';

        if (window.__mmrTableSwrCacheV1) {
            window.__mmrTableSwrCacheV1.scan();

            return;
        }

        const SCHEMA_VERSION = 1;
        const STORAGE_PREFIX = `mmr-table-swr:v${SCHEMA_VERSION}:`;
        const INDEX_KEY = `${STORAGE_PREFIX}index`;
        const TTL_MS = 10 * 60 * 1000;
        // Failsafe only - a freeze normally ends the moment the fresh content
        // lands. This exists so a request that never completes cannot leave an
        // inert copy of the table on screen indefinitely, and is deliberately
        // far longer than any real load: a timer short enough to fire during
        // normal loading is exactly what caused a second visible jump.
        const FREEZE_FAILSAFE_MS = 15000;
        const MAX_ENTRIES = 20;
        const WRAPPER_SELECTOR = '.mmr-table-scroll-ctn[data-mmr-swr-scope]';
        const controllers = new WeakMap();
        let documentObserver = null;
        let scanQueued = false;

        // Temporary diagnostics for the "spinner flashes / UI collapses on a
        // cached revisit" report, which two rounds of code-reading have failed
        // to pin down. Opt-in so it stays silent for everyone else:
        //   localStorage.setItem('mmrSwrDebug', '1')  then reload.
        // Remove this block once the cause is confirmed.
        const DEBUG = (() => {
            try {
                return window.localStorage.getItem('mmrSwrDebug') === '1';
            } catch (_error) {
                return false;
            }
        })();

        const debugLog = (event, detail) => {
            if (!DEBUG) {
                return;
            }

            console.log(`[mmr-swr +${Math.round(performance.now())}ms] ${event}`, detail === undefined ? '' : detail);
        };

        const describeNode = (node) => {
            if (!(node instanceof Element)) {
                return node?.nodeName ?? String(node);
            }

            return `${node.tagName.toLowerCase()}.${Array.from(node.classList).join('.') || '(no-class)'}`;
        };

        const storage = {
            get(key) {
                try {
                    return window.sessionStorage.getItem(key);
                } catch (_error) {
                    return null;
                }
            },
            set(key, value) {
                try {
                    window.sessionStorage.setItem(key, value);

                    return true;
                } catch (_error) {
                    return false;
                }
            },
            remove(key) {
                try {
                    window.sessionStorage.removeItem(key);
                } catch (_error) {
                    // Storage may be disabled or full. The table still works normally.
                }
            },
        };

        const parseJson = (value, fallback = null) => {
            try {
                return JSON.parse(value);
            } catch (_error) {
                return fallback;
            }
        };

        const normalize = (value) => {
            if (value === null || ['string', 'number', 'boolean'].includes(typeof value)) {
                return value;
            }

            if (Array.isArray(value)) {
                return value.map(normalize);
            }

            if (typeof value === 'object') {
                return Object.keys(value)
                    .sort()
                    .reduce((result, key) => {
                        const normalized = normalize(value[key]);

                        if (normalized !== undefined) {
                            result[key] = normalized;
                        }

                        return result;
                    }, {});
            }

            return undefined;
        };

        const stableStringify = (value) => JSON.stringify(normalize(value));

        // Two independent 32-bit hashes keep the raw search/filter input out of
        // sessionStorage while making accidental key collisions negligible.
        const digest = (value) => {
            let fnv = 0x811c9dc5;
            let djb = 0x1505;

            for (let index = 0; index < value.length; index++) {
                const code = value.charCodeAt(index);

                fnv ^= code;
                fnv = Math.imul(fnv, 0x01000193);
                djb = Math.imul(djb, 33) ^ code;
            }

            return `${(fnv >>> 0).toString(16).padStart(8, '0')}${(djb >>> 0).toString(16).padStart(8, '0')}-${value.length}`;
        };

        const readIndex = () => {
            const index = parseJson(storage.get(INDEX_KEY), []);

            return Array.isArray(index) ? index : [];
        };

        const writeIndex = (index) => storage.set(INDEX_KEY, JSON.stringify(index));

        const prune = (now = Date.now(), keepKey = null) => {
            const entries = readIndex()
                .filter((entry) => {
                    const valid = entry
                        && typeof entry.key === 'string'
                        && Number(entry.expiresAt) > now
                        && storage.get(entry.key) !== null;

                    if (!valid && entry && typeof entry.key === 'string') {
                        storage.remove(entry.key);
                    }

                    return valid;
                })
                .sort((left, right) => Number(right.lastAccessedAt) - Number(left.lastAccessedAt));

            while (entries.length > MAX_ENTRIES) {
                const entry = entries.pop();

                if (entry?.key !== keepKey) {
                    storage.remove(entry.key);
                }
            }

            writeIndex(entries);

            return entries;
        };

        const touchIndex = (key, expiresAt, now = Date.now()) => {
            const index = prune(now, key).filter((entry) => entry.key !== key);

            index.unshift({ key, expiresAt, lastAccessedAt: now });

            while (index.length > MAX_ENTRIES) {
                const entry = index.pop();
                storage.remove(entry.key);
            }

            writeIndex(index);
        };

        const evictOldest = (exceptKey = null) => {
            const index = readIndex()
                .filter((entry) => entry?.key !== exceptKey)
                .sort((left, right) => Number(left.lastAccessedAt) - Number(right.lastAccessedAt));
            const oldest = index.shift();

            if (!oldest?.key) {
                return false;
            }

            storage.remove(oldest.key);
            writeIndex(readIndex().filter((entry) => entry?.key !== oldest.key));

            return true;
        };

        // A presence check cheap enough to run inside the morph hook: the index
        // carries the expiry, so this avoids parsing a whole stored snapshot
        // just to find out whether one exists.
        const hasFreshEntry = (key) => readIndex().some((entry) => entry
            && entry.key === key
            && Number(entry.expiresAt) > Date.now()
            && storage.get(key) !== null);

        const loadEntry = (key) => {
            const now = Date.now();
            const entry = parseJson(storage.get(key));

            if (
                !entry
                || entry.schema !== SCHEMA_VERSION
                || entry.digest !== key.slice(STORAGE_PREFIX.length)
                || Number(entry.expiresAt) <= now
                || typeof entry.contentHtml !== 'string'
            ) {
                storage.remove(key);
                writeIndex(readIndex().filter((item) => item?.key !== key));

                return null;
            }

            entry.lastAccessedAt = now;
            storage.set(key, JSON.stringify(entry));
            touchIndex(key, entry.expiresAt, now);

            return entry;
        };

        const saveEntry = (key, entry) => {
            const encoded = JSON.stringify(entry);

            for (let attempt = 0; attempt <= MAX_ENTRIES; attempt++) {
                if (storage.set(key, encoded)) {
                    touchIndex(key, entry.expiresAt, entry.lastAccessedAt);

                    return;
                }

                if (!evictOldest(key)) {
                    return;
                }
            }
        };

        const readScope = (wrapper) => {
            const raw = wrapper.dataset.mmrSwrScope ?? '';
            const parsed = parseJson(raw);

            return parsed && typeof parsed === 'object' ? parsed : raw;
        };

        const findWire = (wrapper) => {
            const componentElement = wrapper.closest('[wire\\:id]');
            const componentId = componentElement?.getAttribute('wire:id');

            if (!componentId || !window.Livewire?.find) {
                return null;
            }

            try {
                return window.Livewire.find(componentId) ?? null;
            } catch (_error) {
                return null;
            }
        };

        const getWireValue = (wire, property, fallback = null) => {
            try {
                const value = wire?.$get?.(property);

                return value === undefined ? fallback : value;
            } catch (_error) {
                return fallback;
            }
        };

        const buildKey = (wrapper, wire) => {
            const state = stableStringify({
                schema: SCHEMA_VERSION,
                scope: readScope(wrapper),
                activeTab: getWireValue(wire, 'activeTab'),
                tableSearch: getWireValue(wire, 'tableSearch', ''),
                catalogSort: getWireValue(wire, 'catalogSort', 'downloads'),
                tableFilters: getWireValue(wire, 'tableFilters', {}),
                paginators: getWireValue(wire, 'paginators', {}),
                perPage: getWireValue(wire, 'tableRecordsPerPage'),
                tableColumns: getWireValue(wire, 'tableColumns', {}),
                tableColumnSearches: getWireValue(wire, 'tableColumnSearches', {}),
                locale: document.documentElement.lang || 'en',
            });
            const keyDigest = digest(state);

            return `${STORAGE_PREFIX}${keyDigest}`;
        };

        const unwrapForms = (root) => {
            root.querySelectorAll('form').forEach((form) => {
                form.replaceWith(...Array.from(form.childNodes));
            });
        };

        const sanitizeImageSource = (image) => {
            const source = image.getAttribute('src');

            image.removeAttribute('srcset');

            if (!source) {
                return;
            }

            try {
                const url = new URL(source, window.location.origin);
                const safeProtocol = ['http:', 'https:'].includes(url.protocol);

                // Signed/query-bearing URLs may contain credentials. Do not
                // persist them; the stale preview can safely omit that image.
                if (!safeProtocol || url.username || url.password || url.search || url.hash) {
                    image.removeAttribute('src');
                }
            } catch (_error) {
                image.removeAttribute('src');
            }
        };

        const sanitizeElement = (source) => {
            const clone = source.cloneNode(true);

            unwrapForms(clone);
            clone.querySelectorAll('script, style, template, iframe, object, embed, input, textarea, select, option, audio, video, source, track, meta, link, base')
                .forEach((element) => element.remove());

            [clone, ...clone.querySelectorAll('*')].forEach((element) => {
                Array.from(element.attributes).forEach((attribute) => {
                    const name = attribute.name.toLowerCase();

                    if (
                        name === 'id'
                        // Filament's ImageColumn sizes <img> purely via an
                        // inline height/width style (no size-related CSS
                        // class exists) - stripping it here made every
                        // cached thumbnail flash at its natural/full size
                        // before the real morph replaced it. That style is
                        // Filament-generated column config, not record
                        // data, so keeping it on <img> is safe.
                        || (name === 'style' && !(element instanceof HTMLImageElement))
                        || name === 'name'
                        || name === 'value'
                        || name === 'checked'
                        || name === 'selected'
                        || name === 'open'
                        || name === 'autofocus'
                        || name === 'contenteditable'
                        || name === 'href'
                        || name === 'srcdoc'
                        || name === 'xlink:href'
                        || name === 'poster'
                        || name === 'cite'
                        || name === 'background'
                        || (name === 'src' && !(element instanceof HTMLImageElement))
                        || name === 'action'
                        || name === 'formaction'
                        || name === 'form'
                        || name === 'nonce'
                        || name === 'integrity'
                        || name === 'crossorigin'
                        || name === 'download'
                        || name === 'target'
                        || name.startsWith('wire:')
                        || name.startsWith('x-')
                        || name.startsWith('@')
                        || name.startsWith(':')
                        || name.startsWith('on')
                        || name.startsWith('data-')
                    ) {
                        element.removeAttribute(attribute.name);
                    }
                });

                if (element instanceof HTMLImageElement) {
                    sanitizeImageSource(element);
                }

                if (element.matches('button, a, [role="button"], [tabindex]')) {
                    element.setAttribute('tabindex', '-1');
                    element.setAttribute('aria-disabled', 'true');

                    if ('disabled' in element) {
                        element.disabled = true;
                    }
                }
            });

            clone.setAttribute('aria-hidden', 'true');

            return clone;
        };
        const sanitizeSnapshot = (source) => sanitizeElement(source).outerHTML;

        const parseCachedElement = (html) => {
            if (typeof html !== 'string') {
                return null;
            }

            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const element = parsed.body.firstElementChild;

            return element ? sanitizeElement(element) : null;
        };


        // The overlay lives inside the wrapper and holds a copy of the table and
        // its pagination, so a plain wrapper.querySelector() can hand back the
        // copy instead of the real element - which decides whether the table
        // reads as "still loading". Every lookup that drives a decision has to
        // go through here.
        const findReal = (wrapper, selector) => Array.from(wrapper.querySelectorAll(selector))
            .find((element) => element.closest('.mmr-table-swr-overlay') === null) ?? null;

        const getContent = (wrapper) => findReal(wrapper, '.fi-ta-content-ctn');

        const getPagination = (wrapper) => findReal(wrapper, '.fi-pagination');

        // sanitizeElement() strips every style attribute, which for the
        // pagination throws away the one thing holding the buttons in place:
        // the inline margin that reserves space for an absent "previous"
        // button (see queueTableHeightRecalculation()). Without it the copy
        // renders its buttons shifted to the left of where the real ones are,
        // so putting the overlay up looked like the pagination jumping.
        const clonePagination = (pagination) => {
            const clone = sanitizeElement(pagination);
            const sourceItems = pagination.querySelector('.fi-pagination-items');
            const cloneItems = clone.querySelector('.fi-pagination-items');
            const offset = sourceItems?.style.marginInlineStart;

            if (cloneItems && offset) {
                cloneItems.style.marginInlineStart = offset;
            }

            debugLog('clonePagination', {
                foundSourceItems: Boolean(sourceItems),
                foundCloneItems: Boolean(cloneItems),
                // Empty means the real element had no inline offset to carry
                // over at this point, which itself is worth seeing.
                offsetCarriedOver: offset || '(none)',
            });

            return clone;
        };

        const capture = (wrapper, key) => {
            // Must be the real table: snapshotting the overlay's copy would
            // write a stale tab's rows into this key's cache entry.
            const content = getContent(wrapper);

            if (!content || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            const now = Date.now();
            const pagination = getPagination(wrapper);
            const contentRect = content.getBoundingClientRect();

            saveEntry(key, {
                schema: SCHEMA_VERSION,
                digest: key.slice(STORAGE_PREFIX.length),
                createdAt: now,
                lastAccessedAt: now,
                expiresAt: now + TTL_MS,
                contentHtml: sanitizeSnapshot(content),
                paginationHtml: pagination ? sanitizeSnapshot(pagination) : null,
                contentHeight: Math.max(Math.round(contentRect.height), 1),
                paginationHeight: pagination ? Math.max(Math.round(pagination.getBoundingClientRect().height), 0) : 0,
                scrollTop: content.scrollTop,
                scrollLeft: content.scrollLeft,
            });
        };

        const rememberScrollPosition = (wrapper, content) => {
            const wire = findWire(wrapper);

            if (!wire || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            const key = buildKey(wrapper, wire);
            const entry = parseJson(storage.get(key));

            if (!entry || entry.schema !== SCHEMA_VERSION || Number(entry.expiresAt) <= Date.now()) {
                return;
            }

            entry.scrollTop = content.scrollTop;
            entry.scrollLeft = content.scrollLeft;
            entry.lastAccessedAt = Date.now();
            saveEntry(key, entry);
        };

        const bindScrollTracking = (wrapper, controller) => {
            wrapper.addEventListener('scroll', (event) => {
                const content = event.target;

                if (
                    !(content instanceof HTMLElement)
                    || !content.matches('.fi-ta-content-ctn')
                    || content.closest('.mmr-table-swr-overlay')
                ) {
                    return;
                }

                window.clearTimeout(controller.scrollTimer);
                controller.scrollTimer = window.setTimeout(
                    () => rememberScrollPosition(wrapper, content),
                    100,
                );
            }, true);
        };

        const removeOverlay = (wrapper, controller, reason = 'unspecified') => {
            if (DEBUG && controller.overlay) {
                // The key differential: if the debug MutationObserver reports
                // the overlay leaving the DOM without one of these lines just
                // before it, something other than our own code removed it.
                debugLog(`removeOverlay (reason: ${reason})`, {
                    wasConnected: controller.overlay.isConnected,
                });
            }

            window.clearTimeout(controller.freezeTimer);
            controller.freezeTimer = null;
            controller.overlay?.remove();
            controller.overlay = null;
            controller.overlayKey = null;
            controller.isFreezeOverlay = false;

            if (controller.reservedMinHeight !== null) {
                wrapper.style.minHeight = controller.originalMinHeight;
                controller.reservedMinHeight = null;
            }
        };

        // Mounts an overlay from nodes the caller has already prepared, so the
        // only work left here is positioning and a single append.
        const mountOverlay = (wrapper, controller, options) => {
            const {
                key,
                contentNode,
                paginationNode,
                anchorRect,
                contentHeight,
                paginationHeight,
                paginationBox,
                scrollTop,
                scrollLeft,
                isFreeze,
            } = options;
            const wrapperRect = wrapper.getBoundingClientRect();
            const overlay = document.createElement('div');

            overlay.className = 'mmr-table-swr-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('inert', '');
            overlay.inert = true;
            // See guardOverlayFromMorph() for why this element needs
            // protecting from Livewire's morph at all. A wire:ignore
            // attribute does NOT do that job: Livewire's directive only
            // sets __livewire_ignore, which morph consults in its
            // "updating" hook - the "removing" hook that decides whether
            // to delete an unmatched child never looks at it.
            overlay.style.top = `${Math.max(anchorRect.top - wrapperRect.top, 0)}px`;
            overlay.style.left = `${Math.max(anchorRect.left - wrapperRect.left, 0)}px`;
            overlay.style.width = `${Math.max(anchorRect.width, 1)}px`;

            contentNode.style.height = `${contentHeight}px`;
            contentNode.style.maxHeight = `${contentHeight}px`;
            contentNode.style.minHeight = '0';
            overlay.append(contentNode);

            if (paginationNode) {
                // A freeze knows exactly where the real pagination sits, so pin
                // the copy on top of it instead of letting it land wherever it
                // happens to stack. Both axes have to come from the measured
                // box: the pagination is a sibling of the content container,
                // not a child, so it has its own left edge and width, and
                // stretching the copy across the content's box instead (which
                // is what left/right: 0 did) puts the buttons at a different x
                // than the real ones sitting underneath.
                if (paginationBox) {
                    paginationNode.style.position = 'absolute';
                    paginationNode.style.top = `${paginationBox.top}px`;
                    paginationNode.style.left = `${paginationBox.left}px`;
                    paginationNode.style.width = `${paginationBox.width}px`;
                    overlay.style.height = `${paginationBox.top + paginationBox.height}px`;
                    overlay.style.width = `${Math.max(anchorRect.width, paginationBox.left + paginationBox.width, 1)}px`;
                }

                overlay.append(paginationNode);
            }

            controller.originalMinHeight = wrapper.style.minHeight;
            const neededHeight = Math.ceil(
                Math.max(anchorRect.top - wrapperRect.top, 0)
                + Number(contentHeight || 0)
                + Number(paginationHeight || 0),
            );

            if (neededHeight > wrapperRect.height) {
                controller.reservedMinHeight = neededHeight;
                wrapper.style.minHeight = `${neededHeight}px`;
            }

            wrapper.append(overlay);
            contentNode.scrollTop = Number(scrollTop || 0);
            contentNode.scrollLeft = Number(scrollLeft || 0);
            controller.overlay = overlay;
            controller.overlayKey = key;
            controller.isFreezeOverlay = Boolean(isFreeze);
        };

        const showOverlay = (wrapper, controller, key, entry, loadingContent) => {
            if (controller.overlayKey === key && controller.overlay?.isConnected) {
                return;
            }

            removeOverlay(wrapper, controller, 'showOverlay-replacing');

            const anchorRect = loadingContent.getBoundingClientRect();
            const cachedContent = parseCachedElement(entry.contentHtml);

            if (!cachedContent) {
                return;
            }

            mountOverlay(wrapper, controller, {
                key,
                contentNode: cachedContent,
                paginationNode: typeof entry.paginationHtml === 'string'
                    ? parseCachedElement(entry.paginationHtml)
                    : null,
                anchorRect,
                contentHeight: entry.contentHeight,
                paginationHeight: entry.paginationHeight,
                scrollTop: entry.scrollTop,
                scrollLeft: entry.scrollLeft,
                isFreeze: false,
            });
        };

        // Covers the table with a copy of whatever is on screen at this very
        // moment. Called from Livewire's "morph" hook, which fires before
        // Alpine.morph touches the DOM, so the cover is already in place when
        // the rendered table is swapped for the loading placeholder - the
        // ~85ms window in which that raw placeholder used to be visible.
        //
        // The source is the live, already-parsed table rather than the stored
        // snapshot on purpose: rebuilding from storage costs a DOMParser pass
        // over the whole snapshot plus a re-sanitise, which is what made the
        // cached path too slow to win that race. Cloning skips the parse, and
        // whatever it does cost now runs while the real table is still on
        // screen, so no amount of it can expose anything.
        const showFreezeOverlay = (wrapper) => {
            const controller = controllers.get(wrapper);

            if (!controller || controller.overlay?.isConnected) {
                return;
            }

            const content = getContent(wrapper);

            if (!content || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            // Only a background revalidation - a load that already has
            // something cached to fall back on - is allowed to be hidden. A
            // first load has nothing to show yet, so covering it would just
            // suppress Filament's own deferLoading spinner and leave no sign
            // that anything is happening. Livewire merges the new snapshot
            // before invoking this hook, so $wire already reports the view
            // being opened rather than the one being left.
            const wire = findWire(wrapper);
            const targetKey = wire ? buildKey(wrapper, wire) : null;

            if (!targetKey || !hasFreshEntry(targetKey)) {
                debugLog('freeze skipped: nothing cached for the incoming view');

                return;
            }

            // Drops a stale reference (and its timer) if an earlier overlay
            // left the DOM without going through removeOverlay, so the mount
            // below can never strand a timer that would later tear down the
            // overlay replacing it.
            removeOverlay(wrapper, controller, 'freeze-clearing-stale');

            const anchorRect = content.getBoundingClientRect();

            if (anchorRect.height < 1) {
                return;
            }

            const pagination = getPagination(wrapper);
            const paginationRect = pagination ? pagination.getBoundingClientRect() : null;
            const paginationBox = paginationRect ? {
                top: Math.round(paginationRect.top - anchorRect.top),
                left: Math.round(paginationRect.left - anchorRect.left),
                width: Math.round(paginationRect.width),
                height: Math.max(Math.round(paginationRect.height), 0),
            } : null;

            debugLog('freeze mounting', { targetKey: targetKey.slice(-16), paginationBox });

            mountOverlay(wrapper, controller, {
                key: null,
                contentNode: sanitizeElement(content),
                paginationNode: pagination ? clonePagination(pagination) : null,
                paginationBox,
                anchorRect,
                contentHeight: Math.max(Math.round(anchorRect.height), 1),
                paginationHeight: pagination
                    ? Math.max(Math.round(pagination.getBoundingClientRect().height), 0)
                    : 0,
                scrollTop: content.scrollTop,
                scrollLeft: content.scrollLeft,
                isFreeze: true,
            });

            // The freeze is held until the fresh content replaces it, so that
            // stays the only visible change. Handing over to the stored
            // snapshot partway through was measured doing the opposite: it
            // fired 91ms before the real table was ready and turned one change
            // into two. Nothing is gained by ending a freeze early either -
            // what it shows is the table the user was just looking at, so it
            // can never read as blank or broken while it waits.
            controller.freezeTimer = window.setTimeout(() => {
                if (controller.isFreezeOverlay) {
                    removeOverlay(wrapper, controller, 'freeze-failsafe');
                }
            }, FREEZE_FAILSAFE_MS);

            debugLog('freeze overlay mounted before morph');
        };

        // Takes the overlay down as soon as the real table has actually
        // rendered, judged purely from the DOM.
        //
        // Removal used to depend on processWrapper agreeing that the table was
        // ready, which meant trusting $wire.isTableLoaded and waiting for a
        // scan that is debounced behind two animation frames. Direct browser
        // observation caught the cost of that: the fresh CurseForge table and
        // pagination were rendered and in place while the frozen copy of the
        // previous tab sat on top of them for over a second, showing that tab's
        // page numbers over the new ones. Whatever stalls that agreement,
        // rendered rows are reason enough to stop covering them, so this runs
        // in the same task as the morph that produced them.
        const releaseOverlayIfReady = (wrapper) => {
            const controller = controllers.get(wrapper);

            if (!controller?.overlay?.isConnected) {
                return false;
            }

            const content = getContent(wrapper);

            if (!content || content.querySelector('.fi-ta-table-loading-ctn')) {
                return false;
            }

            const hasRenderedResult = content.querySelector('.fi-ta-table') !== null
                || findReal(wrapper, '.fi-ta-empty-state') !== null;

            if (!hasRenderedResult) {
                return false;
            }

            // Removal first: it is the part that must not be skipped if
            // anything below it throws. Both run in one task, so the ordering
            // is invisible either way.
            removeOverlay(wrapper, controller, 'real-content-rendered');
            debugLog('overlay released: real table is rendered');
            window.mmrRestorePaginationOffset?.('after-release');

            return true;
        };

        const processWrapper = (wrapper) => {
            if (!(wrapper instanceof HTMLElement) || !wrapper.matches(WRAPPER_SELECTOR)) {
                return;
            }

            let controller = controllers.get(wrapper);

            if (!controller) {
                controller = {
                    overlay: null,
                    overlayKey: null,
                    originalMinHeight: '',
                    reservedMinHeight: null,
                    processing: false,
                    scrollTimer: null,
                    isFreezeOverlay: false,
                    freezeTimer: null,
                };
                controllers.set(wrapper, controller);
                bindScrollTracking(wrapper, controller);
                observeWrapperForDebug(wrapper);
            }

            // Ahead of every other check, so a stale $wire.isTableLoaded can
            // never keep a copy sitting on top of a table that has rendered.
            releaseOverlayIfReady(wrapper);

            if (controller.processing) {
                return;
            }

            controller.processing = true;

            try {
                const wire = findWire(wrapper);

                if (!wire) {
                    return;
                }

                const key = buildKey(wrapper, wire);
                const content = getContent(wrapper);
                const isLoading = Boolean(content?.querySelector('.fi-ta-table-loading-ctn'));
                const isTableLoaded = Boolean(getWireValue(wire, 'isTableLoaded', !isLoading));

                if (isLoading || !isTableLoaded) {
                    // A live freeze is already showing the table exactly as the
                    // user last saw it. Trading that for the stored snapshot
                    // here buys nothing - both are stale, and the real content
                    // is about to replace whichever one is up - while costing a
                    // second visible jump, because the two differ in rows,
                    // height and scroll position. So hold the freeze and let
                    // the fresh content be the only thing that replaces it.
                    // Its own timer covers the case where that takes too long.
                    if (controller.isFreezeOverlay && controller.overlay?.isConnected) {
                        debugLog('processWrapper: table not ready, holding freeze overlay');

                        return;
                    }

                    const entry = loadEntry(key);

                    debugLog('processWrapper: table not ready', {
                        isLoading,
                        isTableLoaded,
                        hasCacheEntry: Boolean(entry),
                        hasContentCtn: Boolean(content),
                        overlayConnected: Boolean(controller.overlay?.isConnected),
                        keyMatchesOverlay: controller.overlayKey === key,
                    });

                    if (entry && content) {
                        showOverlay(wrapper, controller, key, entry, content);
                    } else {
                        removeOverlay(wrapper, controller, 'no-cache-entry-or-content');
                    }

                    return;
                }

                debugLog('processWrapper: table ready', {
                    overlayConnected: Boolean(controller.overlay?.isConnected),
                });

                // A completed morph always wins over the stale preview. Removal
                // stays ahead of everything optional, so nothing below can
                // leave a copy stranded on screen by throwing.
                removeOverlay(wrapper, controller, 'table-loaded');
                window.mmrRestorePaginationOffset?.('after-uncover');

                if (content) {
                    capture(wrapper, key);
                } else if (findReal(wrapper, '.fi-ta-empty-state')) {
                    // A current empty result must not be obscured by a stale table.
                    storage.remove(key);
                    writeIndex(readIndex().filter((entry) => entry?.key !== key));
                }
            } finally {
                controller.processing = false;
            }
        };

        const scan = () => {
            if (scanQueued) {
                return;
            }

            scanQueued = true;

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    scanQueued = false;
                    document.querySelectorAll(WRAPPER_SELECTOR).forEach(processWrapper);
                });
            });
        };

        const isOverlayNode = (node) => node instanceof Element
            && node.closest('.mmr-table-swr-overlay') !== null;

        // The overlay is appended straight into the wrapper, which sits
        // inside the Livewire component root. Every background revalidation
        // morphs that subtree against freshly rendered server HTML - HTML
        // that has no overlay in it, because the server knows nothing about
        // this purely client-side element. Morph therefore sees an
        // unmatched trailing child and deletes it (Alpine's morph pushes it
        // onto `removals` unless the "removing" hook skips it), briefly
        // uncovering the very loading placeholder the overlay exists to
        // hide - which is the spinner flash and the "UI falls apart" moment.
        //
        // Livewire re-exports its internal hook bus as Livewire.hook, and
        // both morph hooks hand back a skip() that makes Alpine leave the
        // element alone entirely. removeOverlay() still takes the overlay
        // down through plain DOM APIs, which these hooks do not affect.
        const guardOverlayFromMorph = (trigger) => {
            if (window.__mmrTableSwrMorphGuardV1) {
                debugLog(`morph guard: already registered (trigger: ${trigger})`);

                return;
            }

            if (typeof window.Livewire?.hook !== 'function') {
                debugLog(`morph guard: NOT registered - Livewire.hook unavailable (trigger: ${trigger})`, {
                    hasLivewire: Boolean(window.Livewire),
                });

                return;
            }

            window.__mmrTableSwrMorphGuardV1 = true;
            debugLog(`morph guard: registered (trigger: ${trigger})`);

            window.Livewire.hook('morph.removing', ({ el, skip }) => {
                if (isOverlayNode(el)) {
                    debugLog('morph.removing: skipping our overlay', describeNode(el));
                    skip();
                }
            });

            window.Livewire.hook('morph.updating', ({ el, skip }) => {
                if (isOverlayNode(el)) {
                    debugLog('morph.updating: skipping our overlay', describeNode(el));
                    skip();
                }
            });

            // Fires synchronously before Alpine.morph mutates anything, which
            // is the only point early enough to get a cover up ahead of the
            // table being swapped for its loading placeholder.
            window.Livewire.hook('morph', ({ el, toEl }) => {
                debugLog('morph: START', describeNode(el));

                // Only the morphs that actually swap the table out for a
                // loading placeholder need covering. The incoming tree says so
                // directly, which matters because the Installed tab polls every
                // two seconds - freezing on those morphs too would replace the
                // live table with an inert copy on a loop.
                if (!toEl?.querySelector?.('.fi-ta-table-loading-ctn')) {
                    return;
                }

                document.querySelectorAll(WRAPPER_SELECTOR).forEach(showFreezeOverlay);
            });

            window.Livewire.hook('morphed', ({ el }) => {
                debugLog('morph: END', describeNode(el));

                // Morph has just stripped the pagination's inline offset, since
                // the server never renders one. Put it back here, in the same
                // task, rather than waiting for the resize pass that runs a
                // frame or two later - that gap is when the buttons were seen
                // sitting too far left.
                if (typeof window.mmrRestorePaginationOffset === 'function') {
                    window.mmrRestorePaginationOffset('morphed');
                } else {
                    debugLog('morphed: mmrRestorePaginationOffset NOT available');
                }

                // The morph that renders the fresh table is the earliest point
                // its copy can come down, and doing it here keeps the two from
                // ever being on screen together.
                document.querySelectorAll(WRAPPER_SELECTOR).forEach(releaseOverlayIfReady);
            });
        };

        // Debug-only: record every element added to or removed from a wrapper,
        // so the moment of the "collapse" can be read off the console even if
        // the overlay itself turns out to be innocent.
        const observeWrapperForDebug = (wrapper) => {
            if (!DEBUG || wrapper.__mmrSwrDebugObserved) {
                return;
            }

            wrapper.__mmrSwrDebugObserved = true;

            new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    Array.from(mutation.removedNodes)
                        .filter((node) => node instanceof Element)
                        .forEach((node) => debugLog('DOM removed', {
                            node: describeNode(node),
                            from: describeNode(mutation.target),
                            isOurOverlay: node.classList.contains('mmr-table-swr-overlay'),
                        }));

                    Array.from(mutation.addedNodes)
                        .filter((node) => node instanceof Element)
                        .forEach((node) => debugLog('DOM added', {
                            node: describeNode(node),
                            to: describeNode(mutation.target),
                            hasSpinner: node.querySelector?.('.fi-ta-table-loading-ctn') !== null
                                || node.classList.contains('fi-ta-table-loading-ctn'),
                        }));
                });
            }).observe(wrapper, { childList: true, subtree: true });

            debugLog('debug observer attached to wrapper');
        };

        const mutationTouchesWrapper = (mutation) => {
            const target = mutation.target;

            if (target instanceof Element && target.closest('.mmr-table-swr-overlay')) {
                return false;
            }

            const changedNodes = [
                ...mutation.addedNodes,
                ...mutation.removedNodes,
            ];

            if (changedNodes.length > 0 && changedNodes.every(isOverlayNode)) {
                return false;
            }

            if (target instanceof Element && target.closest(WRAPPER_SELECTOR)) {
                return true;
            }

            return changedNodes.some((node) => node instanceof Element
                && (
                    node.matches(WRAPPER_SELECTOR)
                    || node.querySelector(WRAPPER_SELECTOR)
                ));
        };

        const init = () => {
            prune();
            // Registered before the first scan can put an overlay up, and
            // again on livewire:init in case this script parsed first (an
            // inline script at BODY_END runs before a deferred livewire.js).
            debugLog('init', { livewireAvailable: typeof window.Livewire?.hook === 'function' });
            guardOverlayFromMorph('init');
            document.addEventListener('livewire:init', () => guardOverlayFromMorph('livewire:init'));
            document.addEventListener('livewire:navigated', () => guardOverlayFromMorph('livewire:navigated'));
            scan();

            if (documentObserver) {
                return;
            }

            documentObserver = new MutationObserver((mutations) => {
                if (!mutations.some(mutationTouchesWrapper)) {
                    return;
                }

                // Uncovering is checked right away rather than through scan(),
                // whose two-frame debounce is time the fresh table would spend
                // sitting underneath a stale copy of itself. This catches any
                // render that arrives outside a morph hook.
                document.querySelectorAll(WRAPPER_SELECTOR).forEach(releaseOverlayIfReady);

                scan();
            });
            documentObserver.observe(document.documentElement, {
                childList: true,
                subtree: true,
            });
        };

        window.__mmrTableSwrCacheV1 = { scan, init };
        init();
        document.addEventListener('livewire:navigated', scan);
    })();
</script>
