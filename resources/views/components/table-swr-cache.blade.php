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
        const MAX_ENTRIES = 20;
        const WRAPPER_SELECTOR = '.mmr-table-scroll-ctn[data-mmr-swr-scope]';
        const controllers = new WeakMap();
        let documentObserver = null;
        let scanQueued = false;

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


        const getPagination = (wrapper) => wrapper.querySelector('.fi-pagination');

        const capture = (wrapper, key) => {
            const content = wrapper.querySelector('.fi-ta-content-ctn');

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

        const removeOverlay = (wrapper, controller) => {
            controller.overlay?.remove();
            controller.overlay = null;
            controller.overlayKey = null;

            if (controller.reservedMinHeight !== null) {
                wrapper.style.minHeight = controller.originalMinHeight;
                controller.reservedMinHeight = null;
            }
        };

        const showOverlay = (wrapper, controller, key, entry, loadingContent) => {
            if (controller.overlayKey === key && controller.overlay?.isConnected) {
                return;
            }

            removeOverlay(wrapper, controller);

            const wrapperRect = wrapper.getBoundingClientRect();
            const anchorRect = loadingContent.getBoundingClientRect();
            const overlay = document.createElement('div');
            const cachedContent = parseCachedElement(entry.contentHtml);

            if (!cachedContent) {
                return;
            }

            overlay.className = 'mmr-table-swr-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('inert', '');
            overlay.inert = true;
            // wrapper is the same element Livewire re-renders on every
            // background revalidation - without this, Livewire's morph
            // (js/morph.js) walks into this raw-DOM-appended overlay as an
            // unexpected extra child, and reconciling/removing it away from
            // the rest of its diff is what produced the "loading spinner
            // flashes, UI looks like it briefly falls apart" glitch: the
            // overlay itself (and whatever real content it was still
            // covering underneath) got caught up in that diff instead of
            // being left alone until removeOverlay() takes it out directly.
            // wire:ignore (see Livewire's directive('ignore', ...) in
            // js/directives/wire-ignore.js) is picked up by Alpine's
            // mutation observer for elements added after the fact, well
            // before the next morph (a network round trip away) can run.
            overlay.setAttribute('wire:ignore', '');
            overlay.style.top = `${Math.max(anchorRect.top - wrapperRect.top, 0)}px`;
            overlay.style.left = `${Math.max(anchorRect.left - wrapperRect.left, 0)}px`;
            overlay.style.width = `${Math.max(anchorRect.width, 1)}px`;

            cachedContent.style.height = `${entry.contentHeight}px`;
            cachedContent.style.maxHeight = `${entry.contentHeight}px`;
            cachedContent.style.minHeight = '0';
            overlay.append(cachedContent);

            if (typeof entry.paginationHtml === 'string') {
                const cachedPagination = parseCachedElement(entry.paginationHtml);

                if (cachedPagination) {
                    overlay.append(cachedPagination);
                }
            }

            controller.originalMinHeight = wrapper.style.minHeight;
            const neededHeight = Math.ceil(
                Math.max(anchorRect.top - wrapperRect.top, 0)
                + Number(entry.contentHeight || 0)
                + Number(entry.paginationHeight || 0),
            );

            if (neededHeight > wrapperRect.height) {
                controller.reservedMinHeight = neededHeight;
                wrapper.style.minHeight = `${neededHeight}px`;
            }

            wrapper.append(overlay);
            cachedContent.scrollTop = Number(entry.scrollTop || 0);
            cachedContent.scrollLeft = Number(entry.scrollLeft || 0);
            controller.overlay = overlay;
            controller.overlayKey = key;
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
                };
                controllers.set(wrapper, controller);
                bindScrollTracking(wrapper, controller);
            }

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
                const content = wrapper.querySelector('.fi-ta-content-ctn');
                const isLoading = Boolean(content?.querySelector('.fi-ta-table-loading-ctn'));
                const isTableLoaded = Boolean(getWireValue(wire, 'isTableLoaded', !isLoading));

                if (isLoading || !isTableLoaded) {
                    const entry = loadEntry(key);

                    if (entry && content) {
                        showOverlay(wrapper, controller, key, entry, content);
                    } else {
                        removeOverlay(wrapper, controller);
                    }

                    return;
                }

                // A completed morph always wins over the stale preview.
                removeOverlay(wrapper, controller);

                if (content) {
                    capture(wrapper, key);
                } else if (wrapper.querySelector('.fi-ta-empty-state')) {
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
            scan();

            if (documentObserver) {
                return;
            }

            documentObserver = new MutationObserver((mutations) => {
                if (!mutations.some(mutationTouchesWrapper)) {
                    return;
                }

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
