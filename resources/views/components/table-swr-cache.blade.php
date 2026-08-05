<script data-navigate-once>
    (() => {
        'use strict';

        if (window.__mmrTableSwrCacheV6) {
            window.__mmrTableSwrCacheV6.scan();

            return;
        }

        // V1 stored sanitized copies of whole Filament table fragments. V6
        // deliberately stores only display values and keeps Filament's actual
        // table/pagination DOM in place while a cached view revalidates.
        const SCHEMA_VERSION = 6;
        const STORAGE_PREFIX = `mmr-table-swr:v${SCHEMA_VERSION}:`;
        const INDEX_KEY = `${STORAGE_PREFIX}index`;
        const DEBUG_STORAGE_KEY = 'mmrSwrDebug';
        // This exact URI is the server-side ImageColumn fallback. It is safe to
        // retain in sessionStorage; no arbitrary data URI is ever accepted.
        const EMPTY_ICON_DATA_URI = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        const TTL_MS = 10 * 60 * 1000;
        const MAX_ENTRIES = 20;
        const WRAPPER_SELECTOR = '.mmr-table-scroll-ctn[data-mmr-swr-scope]';
        const CELL_SELECTOR = 'td[data-mmr-swr-cell]';
        const ROW_ACTION_SELECTOR = '[data-mmr-swr-row-action]';
        const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
        const ROW_ACTION_DEFINITIONS = Object.freeze({
            versions: {
                paths: ['M9 6l11 0', 'M9 12l11 0', 'M9 18l11 0', 'M5 6l0 .01', 'M5 12l0 .01', 'M5 18l0 .01'],
            },
            install_latest: {
                paths: ['M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2', 'M7 11l5 5l5 -5', 'M12 4l0 12'],
            },
            update: {
                paths: ['M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4', 'M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4'],
            },
            installed: {
                disabled: true,
                paths: ['M5 12l5 5l10 -10'],
            },
            uninstall: {
                paths: ['M4 7l16 0', 'M10 11l0 6', 'M14 11l0 6', 'M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12', 'M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3'],
            },
        });
        const ROW_ACTION_COLORS = new Set(['info', 'success', 'warning', 'danger']);
        const ROW_ACTION_TEXT_COLOR_SHADES = new Set(['0', '50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950']);
        const controllers = new WeakMap();
        let documentObserver = null;
        let scanQueued = false;
        let debugSequence = 0;

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
                    // Disabled/private storage must never affect table usage.
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

        // Two independent 32-bit hashes avoid persisting search/filter input
        // verbatim while keeping accidental key collisions negligible.
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

        // Temporary, opt-in browser diagnostics. This deliberately logs only
        // state hashes and DOM structure, never search/filter text or cell data.
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

            console.info(`[mmr-swr +${Math.round(window.performance?.now?.() ?? 0)}ms] ${event}`, detail);
        };

        const valueDigest = (value) => digest(stableStringify(value) ?? '');

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
            const entries = readIndex()
                .filter((entry) => entry?.key !== exceptKey)
                .sort((left, right) => Number(left.lastAccessedAt) - Number(right.lastAccessedAt));
            const oldest = entries.shift();

            if (!oldest?.key) {
                return false;
            }

            storage.remove(oldest.key);
            writeIndex(readIndex().filter((entry) => entry?.key !== oldest.key));

            return true;
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

        const inspectCache = (key) => {
            const now = Date.now();
            const indexEntry = readIndex().find((entry) => entry?.key === key);

            if (!indexEntry) {
                return { available: false, reason: 'cache-miss' };
            }

            if (Number(indexEntry.expiresAt) <= now) {
                return {
                    available: false,
                    reason: 'expired',
                    expiresAgoMs: now - Number(indexEntry.expiresAt),
                };
            }

            if (storage.get(key) === null) {
                return { available: false, reason: 'cache-storage-missing' };
            }

            return { available: true };
        };

        const loadEntry = (key) => {
            const now = Date.now();
            const entry = parseJson(storage.get(key));

            if (
                !entry
                || entry.schema !== SCHEMA_VERSION
                || entry.digest !== key.slice(STORAGE_PREFIX.length)
                || Number(entry.expiresAt) <= now
                || !entry.projection
                || !Array.isArray(entry.projection.rows)
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

        const describeViewState = (wire) => ({
            activeTab: String(getWireValue(wire, 'activeTab', '') ?? ''),
            paginators: normalize(getWireValue(wire, 'paginators', {})) ?? {},
            catalogSort: String(getWireValue(wire, 'catalogSort', 'downloads') ?? 'downloads'),
            perPage: getWireValue(wire, 'tableRecordsPerPage', null),
            tableSearchDigest: valueDigest(getWireValue(wire, 'tableSearch', '')),
            tableFiltersDigest: valueDigest(getWireValue(wire, 'tableFilters', {})),
            tableColumnsDigest: valueDigest(getWireValue(wire, 'tableColumns', {})),
            tableColumnSearchesDigest: valueDigest(getWireValue(wire, 'tableColumnSearches', {})),
        });

        const equalStateValue = (left, right) => stableStringify(left) === stableStringify(right);

        const describeTransition = (previous, target) => {
            if (!previous || !target) {
                return {
                    type: 'unknown',
                    changed: ['unknown'],
                    from: previous ?? null,
                    to: target ?? null,
                };
            }

            const changed = Object.keys(target).filter((key) => !equalStateValue(previous[key], target[key]));
            const type = changed.includes('activeTab')
                ? 'active-tab'
                : changed.includes('paginators')
                    ? 'pagination'
                    : changed.includes('tableSearchDigest')
                        ? 'search'
                        : changed.includes('tableFiltersDigest')
                            ? 'filters'
                            : changed.includes('catalogSort')
                                ? 'sort'
                                : changed.includes('tableColumnsDigest')
                                    ? 'columns'
                                    : 'other';

            return {
                type,
                changed,
                from: {
                    activeTab: previous.activeTab,
                    paginators: previous.paginators,
                },
                to: {
                    activeTab: target.activeTab,
                    paginators: target.paginators,
                },
            };
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

            return `${STORAGE_PREFIX}${digest(state)}`;
        };

        const cacheKeyDigest = (key) => key?.slice(STORAGE_PREFIX.length) ?? null;

        const getContent = (wrapper) => wrapper.querySelector('.fi-ta-content-ctn');
        const getPagination = (wrapper) => wrapper.querySelector('.fi-pagination');
        const getRows = (content) => Array.from(content?.querySelectorAll('tbody > tr.fi-ta-row') ?? []);
        const getRowActionElements = (row) => Array.from(row.querySelectorAll(ROW_ACTION_SELECTOR));
        const getRowActionContainer = (row) => row.querySelector('.fi-ta-actions');

        const isRowActionClass = (className) => {
            if (['fi-icon-btn', 'fi-color', 'fi-disabled', 'mx-0.5'].includes(className)) {
                return true;
            }

            if (className.startsWith('fi-color-')) {
                return ROW_ACTION_COLORS.has(className.slice('fi-color-'.length));
            }

            const textColorMatch = className.match(/^(?:(?:dark:)?(?:hover:)?|hover:)?fi-text-color-(.+)$/);

            return textColorMatch !== null && ROW_ACTION_TEXT_COLOR_SHADES.has(textColorMatch[1]);
        };

        const rowActionClasses = (action) => Array.from(action.classList)
            .filter(isRowActionClass)
            .sort();

        const isRowActionDescriptor = (descriptor) => {
            if (
                !descriptor
                || typeof descriptor.type !== 'string'
                || typeof descriptor.color !== 'string'
                || !Object.hasOwn(ROW_ACTION_DEFINITIONS, descriptor.type)
                || !ROW_ACTION_COLORS.has(descriptor.color)
                || !Array.isArray(descriptor.classes)
                || !descriptor.classes.every((className) => typeof className === 'string' && isRowActionClass(className))
            ) {
                return false;
            }

            const classes = new Set(descriptor.classes);
            const isDisabled = ROW_ACTION_DEFINITIONS[descriptor.type].disabled === true;

            return classes.has('fi-icon-btn')
                && classes.has('fi-color')
                && classes.has(`fi-color-${descriptor.color}`)
                && classes.has('mx-0.5')
                && (isDisabled ? classes.has('fi-disabled') : !classes.has('fi-disabled'));
        };

        const getRowActionDescriptors = (row) => {
            const descriptors = getRowActionElements(row).map((action) => ({
                type: action.dataset.mmrSwrRowAction,
                color: action.dataset.mmrSwrRowActionColor,
                classes: rowActionClasses(action),
            }));

            return descriptors.every(isRowActionDescriptor) ? descriptors : null;
        };

        const actionDescriptorsMatch = (current, cached) => Array.isArray(current)
            && Array.isArray(cached)
            && current.length === cached.length
            && current.every((action, index) => action.type === cached[index]?.type
                && action.color === cached[index]?.color
                && action.classes.length === cached[index]?.classes?.length
                && action.classes.every((className, classIndex) => className === cached[index].classes[classIndex]));

        const createRowActionIcon = (paths) => {
            const icon = document.createElementNS(SVG_NAMESPACE, 'svg');
            icon.setAttribute('class', 'fi-icon fi-size-md');
            icon.setAttribute('viewBox', '0 0 24 24');
            icon.setAttribute('fill', 'none');
            icon.setAttribute('stroke', 'currentColor');
            icon.setAttribute('stroke-width', '2');
            icon.setAttribute('stroke-linecap', 'round');
            icon.setAttribute('stroke-linejoin', 'round');
            icon.setAttribute('aria-hidden', 'true');

            paths.forEach((pathData) => {
                const path = document.createElementNS(SVG_NAMESPACE, 'path');
                path.setAttribute('d', pathData);
                icon.append(path);
            });

            return icon;
        };

        const createProjectedRowAction = (descriptor) => {
            const definition = ROW_ACTION_DEFINITIONS[descriptor.type];
            const action = document.createElement('button');
            action.type = 'button';
            action.classList.add(...descriptor.classes);
            action.dataset.mmrSwrRowAction = descriptor.type;
            action.dataset.mmrSwrRowActionColor = descriptor.color;
            action.dataset.mmrSwrActionProjection = descriptor.type;
            action.setAttribute('aria-hidden', 'true');
            action.tabIndex = -1;
            action.append(createRowActionIcon(definition.paths));

            return action;
        };

        const projectRowActions = (content, projection) => {
            const rows = getRows(content);

            for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                const row = rows[rowIndex];
                const cachedActions = projection.rows[rowIndex].actions;
                const container = getRowActionContainer(row);

                if (cachedActions.length === 0) {
                    if (container && !actionDescriptorsMatch(getRowActionDescriptors(row), cachedActions)) {
                        container.replaceChildren();
                    }

                    continue;
                }

                if (!container) {
                    return false;
                }

                if (actionDescriptorsMatch(getRowActionDescriptors(row), cachedActions)) {
                    continue;
                }

                const projectedActions = document.createDocumentFragment();
                cachedActions.forEach((descriptor) => projectedActions.append(createProjectedRowAction(descriptor)));
                container.replaceChildren(projectedActions);
            }

            return true;
        };

        const getController = (wrapper) => {
            let controller = controllers.get(wrapper);

            if (!controller) {
                controller = {
                    holding: false,
                    holdKey: null,
                    heldContent: null,
                    heldPagination: null,
                    heldTable: null,
                    clearAfterMorph: false,
                    scrollTimer: null,
                    lastRenderedView: null,
                    pendingLoad: null,
                };
                controllers.set(wrapper, controller);
                bindScrollTracking(wrapper, controller);
            }

            return controller;
        };

        // Color classes are state, not cell structure. Ignoring them lets an
        // Installed source badge change color without incorrectly rejecting a
        // structurally identical cached row.
        const structuralClasses = (element) => Array.from(element.classList)
            .filter((className) => className !== 'fi-color' && !className.startsWith('fi-color-'))
            .sort();

        const shapeSignature = (root) => {
            const parts = [];

            const visit = (element) => {
                // Record URLs and the Modrinth author URL wrap otherwise
                // identical text in <a> elements. Those links are inert while
                // stale, so they are not part of the display-value structure.
                const isTransparentWrapper = element.matches('a, .fi-ta-col');

                if (!isTransparentWrapper) {
                    parts.push(`<${element.tagName.toLowerCase()}.${structuralClasses(element).join('.')}>`);
                }

                Array.from(element.children).forEach(visit);

                if (!isTransparentWrapper) {
                    parts.push('</>');
                }
            };

            visit(root);

            return parts.join('');
        };

        const textNodes = (root) => {
            const nodes = [];
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            let node = walker.nextNode();

            while (node) {
                const parent = node.parentElement;

                if (
                    parent
                    && !parent.closest('svg, script, style')
                    && node.nodeValue.trim() !== ''
                ) {
                    nodes.push(node);
                }

                node = walker.nextNode();
            }

            return nodes;
        };

        const safeImage = (image) => {
            const source = image.getAttribute('src');

            if (!source) {
                return { src: null, alt: image.getAttribute('alt') ?? '' };
            }

            if (source === EMPTY_ICON_DATA_URI) {
                return { src: source, alt: image.getAttribute('alt') ?? '' };
            }

            try {
                const url = new URL(source, window.location.origin);

                // Do not write signed or credential-bearing image URLs into
                // sessionStorage. Omitting a stale icon is safe; retaining a
                // credential is not.
                if (
                    !['http:', 'https:'].includes(url.protocol)
                    || url.username
                    || url.password
                    || url.search
                    || url.hash
                ) {
                    return null;
                }

                return { src: url.href, alt: image.getAttribute('alt') ?? '' };
            } catch (_error) {
                return null;
            }
        };

        const badgeColors = (badge) => Array.from(badge.classList)
            .filter((className) => className === 'fi-color' || className.startsWith('fi-color-'))
            .sort();

        const captureCell = (cell) => {
            const images = Array.from(cell.querySelectorAll('img')).map(safeImage);

            if (images.some((image) => image === null)) {
                return null;
            }

            return {
                name: cell.dataset.mmrSwrCell,
                signature: shapeSignature(cell),
                text: textNodes(cell).map((node) => node.nodeValue),
                images,
                badges: Array.from(cell.querySelectorAll('.fi-badge')).map(badgeColors),
            };
        };

        const captureProjection = (content) => {
            const rows = getRows(content);

            if (rows.length === 0) {
                return null;
            }

            const projectedRows = rows.map((row) => {
                const cells = Array.from(row.querySelectorAll(CELL_SELECTOR)).map(captureCell);
                const actions = getRowActionDescriptors(row);

                if (cells.length === 0 || cells.some((cell) => cell === null) || actions === null) {
                    return null;
                }

                return {
                    cells,
                    // Cache only stable action type/color descriptors. HTML is
                    // intentionally never retained or restored from storage.
                    actions,
                };
            });

            if (projectedRows.some((row) => row === null)) {
                return null;
            }

            const firstRow = rows[0];

            return {
                rowCount: rows.length,
                // This catches a column-manager mutation before per-row work
                // starts, even when it happened without changing the cache key.
                columns: Array.from(firstRow.querySelectorAll(CELL_SELECTOR))
                    .map((cell) => cell.dataset.mmrSwrCell)
                    .join('|'),
                rows: projectedRows,
            };
        };

        const applyBadgeColors = (badge, colors) => {
            Array.from(badge.classList)
                .filter((className) => className === 'fi-color' || className.startsWith('fi-color-'))
                .forEach((className) => badge.classList.remove(className));
            colors.forEach((className) => badge.classList.add(className));
        };

        const applyCell = (cell, projection) => {
            if (
                cell.dataset.mmrSwrCell !== projection.name
                || shapeSignature(cell) !== projection.signature
            ) {
                return false;
            }

            const currentTextNodes = textNodes(cell);
            const currentImages = Array.from(cell.querySelectorAll('img'));
            const currentBadges = Array.from(cell.querySelectorAll('.fi-badge'));

            if (
                currentTextNodes.length !== projection.text.length
                || currentImages.length !== projection.images.length
                || currentBadges.length !== projection.badges.length
            ) {
                return false;
            }

            currentTextNodes.forEach((node, index) => {
                node.nodeValue = projection.text[index];
            });

            currentImages.forEach((image, index) => {
                const cachedImage = projection.images[index];

                if (cachedImage.src) {
                    image.setAttribute('src', cachedImage.src);
                } else {
                    image.removeAttribute('src');
                }

                image.setAttribute('alt', cachedImage.alt);
            });

            currentBadges.forEach((badge, index) => applyBadgeColors(badge, projection.badges[index]));

            return true;
        };

        const projectionFailure = (reason, detail = {}) => ({ ok: false, reason, detail });

        const canApplyProjection = (content, projection, withDiagnostics = false) => {
            const rows = getRows(content);

            if (
                !projection
                || !Array.isArray(projection.rows)
                || rows.length !== projection.rowCount
                || rows.length !== projection.rows.length
            ) {
                return projectionFailure(
                    'row-count-mismatch',
                    withDiagnostics
                        ? {
                            currentRowCount: rows.length,
                            cachedRowCount: projection?.rowCount ?? null,
                            cachedRowsLength: Array.isArray(projection?.rows) ? projection.rows.length : null,
                        }
                        : {},
                );
            }

            const columns = Array.from(rows[0]?.querySelectorAll(CELL_SELECTOR) ?? [])
                .map((cell) => cell.dataset.mmrSwrCell)
                .join('|');

            if (columns !== projection.columns) {
                return projectionFailure(
                    'column-order-mismatch',
                    withDiagnostics
                        ? {
                            currentColumns: columns,
                            cachedColumns: projection.columns,
                        }
                        : {},
                );
            }

            for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                const row = rows[rowIndex];
                const rowProjection = projection.rows[rowIndex];
                const cells = Array.from(row.querySelectorAll(CELL_SELECTOR));

                if (!rowProjection || !Array.isArray(rowProjection.cells) || cells.length !== rowProjection.cells.length) {
                    return projectionFailure(
                        'cell-count-mismatch',
                        withDiagnostics
                            ? {
                                row: rowIndex + 1,
                                currentCellCount: cells.length,
                                cachedCellCount: Array.isArray(rowProjection?.cells) ? rowProjection.cells.length : null,
                            }
                            : {},
                    );
                }

                if (!Array.isArray(rowProjection.actions) || !rowProjection.actions.every(isRowActionDescriptor)) {
                    return projectionFailure(
                        'row-actions-invalid',
                        withDiagnostics
                            ? {
                                row: rowIndex + 1,
                                cachedActions: rowProjection?.actions ?? null,
                            }
                            : {},
                    );
                }

                if (rowProjection.actions.length > 0 && !getRowActionContainer(row)) {
                    return projectionFailure(
                        'row-actions-container-missing',
                        withDiagnostics ? { row: rowIndex + 1 } : {},
                    );
                }

                for (let cellIndex = 0; cellIndex < cells.length; cellIndex++) {
                    const cell = cells[cellIndex];
                    const cellProjection = rowProjection.cells[cellIndex];

                    if (!cellProjection) {
                        return projectionFailure(
                            'cell-projection-missing',
                            withDiagnostics
                                ? {
                                    row: rowIndex + 1,
                                    cellIndex: cellIndex + 1,
                                    currentCell: cell.dataset.mmrSwrCell ?? null,
                                }
                                : {},
                        );
                    }

                    const currentName = cell.dataset.mmrSwrCell ?? null;
                    const currentSignature = shapeSignature(cell);
                    const currentTextNodeCount = textNodes(cell).length;
                    const currentImageCount = cell.querySelectorAll('img').length;
                    const currentBadgeCount = cell.querySelectorAll('.fi-badge').length;
                    const cachedName = cellProjection.name ?? null;
                    const cachedSignature = cellProjection.signature ?? null;
                    const cachedTextNodeCount = Array.isArray(cellProjection.text) ? cellProjection.text.length : null;
                    const cachedImageCount = Array.isArray(cellProjection.images) ? cellProjection.images.length : null;
                    const cachedBadgeCount = Array.isArray(cellProjection.badges) ? cellProjection.badges.length : null;

                    if (
                        currentName !== cachedName
                        || currentSignature !== cachedSignature
                        || currentTextNodeCount !== cachedTextNodeCount
                        || currentImageCount !== cachedImageCount
                        || currentBadgeCount !== cachedBadgeCount
                    ) {
                        return projectionFailure(
                            'cell-shape-mismatch',
                            withDiagnostics
                                ? {
                                    row: rowIndex + 1,
                                    cellIndex: cellIndex + 1,
                                    cell: currentName,
                                    current: {
                                        name: currentName,
                                        signature: currentSignature,
                                        textNodeCount: currentTextNodeCount,
                                        imageCount: currentImageCount,
                                        badgeCount: currentBadgeCount,
                                    },
                                    cached: {
                                        name: cachedName,
                                        signature: cachedSignature,
                                        textNodeCount: cachedTextNodeCount,
                                        imageCount: cachedImageCount,
                                        badgeCount: cachedBadgeCount,
                                    },
                                }
                                : {},
                        );
                    }
                }
            }

            return { ok: true };
        };

        const applyProjection = (content, projection, withDiagnostics = false) => {
            const compatibility = canApplyProjection(content, projection, withDiagnostics);

            if (!compatibility.ok) {
                return compatibility;
            }

            const rows = getRows(content);

            for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                const cells = Array.from(rows[rowIndex].querySelectorAll(CELL_SELECTOR));

                for (let cellIndex = 0; cellIndex < cells.length; cellIndex++) {
                    if (!applyCell(cells[cellIndex], projection.rows[rowIndex].cells[cellIndex])) {
                        return projectionFailure('cell-apply-mismatch', {
                            row: rowIndex + 1,
                            cellIndex: cellIndex + 1,
                            cell: cells[cellIndex].dataset.mmrSwrCell ?? null,
                        });
                    }
                }
            }

            return { ok: true };
        };

        const capture = (wrapper, key) => {
            const content = getContent(wrapper);

            if (!content || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            const projection = captureProjection(content);

            if (!projection) {
                // Empty results and unsupported/mismatched layouts must never
                // replace a valid preview for a different cached condition.
                storage.remove(key);
                writeIndex(readIndex().filter((entry) => entry?.key !== key));

                return;
            }

            const now = Date.now();

            saveEntry(key, {
                schema: SCHEMA_VERSION,
                digest: key.slice(STORAGE_PREFIX.length),
                createdAt: now,
                lastAccessedAt: now,
                expiresAt: now + TTL_MS,
                projection,
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
            const entry = loadEntry(key);

            if (!entry) {
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
                    controller.holding
                    || !(content instanceof HTMLElement)
                    || !content.matches('.fi-ta-content-ctn')
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

        const setHeldState = (controller, active) => {
            [controller.heldContent, controller.heldPagination]
                .filter((element) => element?.isConnected)
                .forEach((element) => {
                    if (active) {
                        element.setAttribute('inert', '');
                        element.setAttribute('aria-busy', 'true');
                        element.dataset.mmrSwrStale = 'true';
                    } else {
                        element.removeAttribute('inert');
                        element.removeAttribute('aria-busy');
                        delete element.dataset.mmrSwrStale;
                    }
                });

            if (controller.heldTable?.isConnected) {
                if (active) {
                    // Filament adds fi-loading to its root whenever records are
                    // deferred. Its CSS pulses the entire table, which is useful
                    // for an uncached load but defeats a stable stale preview.
                    controller.heldTable.classList.remove('fi-loading');
                    controller.heldTable.dataset.mmrSwrStale = 'true';
                } else {
                    delete controller.heldTable.dataset.mmrSwrStale;
                }
            }

        };

        const stopHolding = (controller, clearImmediately = true) => {
            controller.holding = false;
            controller.holdKey = null;

            if (clearImmediately) {
                setHeldState(controller, false);
                controller.heldContent = null;
                controller.heldPagination = null;
                controller.heldTable = null;
                controller.clearAfterMorph = false;
            } else {
                controller.clearAfterMorph = true;
            }
        };

        const holdCachedProjection = (wrapper) => {
            const controller = getController(wrapper);
            const content = getContent(wrapper);
            const wire = findWire(wrapper);
            const key = wire ? buildKey(wrapper, wire) : null;
            const debugActive = debugEnabled();
            const targetView = debugActive && wire ? describeViewState(wire) : null;
            const debugContext = debugActive
                ? {
                    id: ++debugSequence,
                    startedAt: window.performance?.now?.() ?? 0,
                    targetView,
                    transition: describeTransition(controller.lastRenderedView, targetView),
                    cacheKey: cacheKeyDigest(key),
                }
                : null;

            if (debugContext) {
                controller.pendingLoad = debugContext;
                debugLog('deferred-load-started', {
                    request: debugContext.id,
                    transition: debugContext.transition,
                    target: debugContext.targetView,
                    cacheKey: debugContext.cacheKey,
                });
            }

            const reject = (reason, detail = {}) => {
                stopHolding(controller);
                debugLog('cached-projection-rejected', {
                    request: debugContext?.id ?? null,
                    reason,
                    detail,
                    transition: debugContext?.transition ?? null,
                    target: debugContext?.targetView ?? targetView,
                    cacheKey: debugContext?.cacheKey ?? cacheKeyDigest(key),
                });

                return false;
            };

            if (!content) {
                return reject('content-missing');
            }

            if (!wire) {
                return reject('wire-missing');
            }

            if (!key) {
                return reject('cache-key-missing');
            }

            if (content.querySelector('.fi-ta-table-loading-ctn')) {
                return reject('current-content-loading');
            }

            if (controller.holding && controller.holdKey === key) {
                if (debugContext) {
                    debugContext.held = true;
                }

                debugLog('cached-projection-already-held', {
                    request: debugContext?.id ?? null,
                    transition: debugContext?.transition ?? null,
                    cacheKey: cacheKeyDigest(key),
                });

                return true;
            }

            stopHolding(controller);

            const cache = inspectCache(key);

            if (!cache.available) {
                return reject(cache.reason, cache);
            }

            const entry = loadEntry(key);

            if (!entry) {
                return reject('cache-entry-invalid');
            }

            const applied = applyProjection(content, entry.projection, debugActive);

            if (!applied.ok) {
                return reject(applied.reason, applied.detail);
            }

            // Unlike the prior blank fallback, render target action descriptors
            // directly into the existing action region. The cache holds only
            // type/color values; the fresh Filament response still replaces this
            // non-interactive projection with the authoritative action DOM.
            if (!projectRowActions(content, entry.projection)) {
                return reject('row-actions-projection-failed');
            }

            content.scrollTop = Number(entry.scrollTop || 0);
            content.scrollLeft = Number(entry.scrollLeft || 0);
            controller.holding = true;
            controller.holdKey = key;
            controller.heldContent = content;
            controller.heldPagination = getPagination(wrapper);
            controller.heldTable = content.closest('.fi-ta');
            setHeldState(controller, true);

            // Cached values can have different wrapping than the table that was
            // just present. Reuse the existing viewport calculation rather than
            // introducing another layout approximation here.
            requestAnimationFrame(() => window.mmrResizeTables?.());

            if (debugContext) {
                debugContext.held = true;
            }

            debugLog('cached-projection-held', {
                request: debugContext?.id ?? null,
                transition: debugContext?.transition ?? null,
                target: debugContext?.targetView ?? targetView,
                cacheKey: cacheKeyDigest(key),
                rowCount: entry.projection.rowCount,
            });

            return true;
        };

        const wrappersIn = (root) => {
            if (!(root instanceof Element)) {
                return [];
            }

            return [
                ...(root.matches(WRAPPER_SELECTOR) ? [root] : []),
                ...root.querySelectorAll(WRAPPER_SELECTOR),
            ];
        };

        const isIncomingLoading = (toEl) => toEl?.querySelector?.('.fi-ta-table-loading-ctn') !== null;

        const prepareMorph = (root, toEl) => {
            const loading = isIncomingLoading(toEl);

            wrappersIn(root).forEach((wrapper) => {
                const controller = getController(wrapper);

                if (loading) {
                    holdCachedProjection(wrapper);
                } else if (controller.holding) {
                    // Do not skip the fresh response. Keeping the inert marker
                    // through this synchronous morph avoids re-enabling stale
                    // row actions for even one paint.
                    stopHolding(controller, false);
                }
            });
        };

        const skipHeldContentUpdate = (element) => {
            for (const wrapper of document.querySelectorAll(WRAPPER_SELECTOR)) {
                const controller = controllers.get(wrapper);

                if (controller?.holding && controller.heldContent === element) {
                    return true;
                }
            }

            return false;
        };

        const skipHeldPaginationRemoval = (element) => {
            for (const wrapper of document.querySelectorAll(WRAPPER_SELECTOR)) {
                const controller = controllers.get(wrapper);

                if (controller?.holding && controller.heldPagination === element) {
                    return true;
                }
            }

            return false;
        };

        const finishMorph = (root) => {
            wrappersIn(root).forEach((wrapper) => {
                const controller = getController(wrapper);

                if (controller.clearAfterMorph) {
                    setHeldState(controller, false);
                    controller.heldContent = null;
                    controller.heldPagination = null;
                    controller.heldTable = null;
                    controller.clearAfterMorph = false;
                } else if (controller.holding) {
                    // The intermediate response may have restored fi-loading on
                    // the table root. Remove it before the browser can paint.
                    setHeldState(controller, true);
                }

                processWrapper(wrapper);
            });
        };

        const processWrapper = (wrapper) => {
            if (!(wrapper instanceof HTMLElement) || !wrapper.matches(WRAPPER_SELECTOR)) {
                return;
            }

            const controller = getController(wrapper);

            if (controller.holding) {
                return;
            }

            const wire = findWire(wrapper);
            const content = getContent(wrapper);

            if (!wire || !content || content.querySelector('.fi-ta-table-loading-ctn')) {
                return;
            }

            const key = buildKey(wrapper, wire);
            const pendingLoad = controller.pendingLoad;

            if (pendingLoad) {
                debugLog('deferred-load-finished', {
                    request: pendingLoad.id,
                    durationMs: Math.round((window.performance?.now?.() ?? 0) - pendingLoad.startedAt),
                    heldCachedProjection: pendingLoad.held === true,
                    transition: pendingLoad.transition,
                    activeTab: getWireValue(wire, 'activeTab', null),
                    paginators: normalize(getWireValue(wire, 'paginators', {})) ?? {},
                    rowCount: getRows(content).length,
                    empty: content.querySelector('.fi-ta-empty-state') !== null,
                });
                controller.pendingLoad = null;
            }

            controller.lastRenderedView = debugEnabled() ? describeViewState(wire) : null;

            if (getRows(content).length > 0) {
                capture(wrapper, key);
            } else if (content.querySelector('.fi-ta-empty-state')) {
                storage.remove(key);
                writeIndex(readIndex().filter((entry) => entry?.key !== key));
            }
        };

        const scan = () => {
            if (scanQueued) {
                return;
            }

            scanQueued = true;

            requestAnimationFrame(() => {
                scanQueued = false;
                document.querySelectorAll(WRAPPER_SELECTOR).forEach(processWrapper);
            });
        };

        const registerMorphHooks = () => {
            if (window.__mmrTableSwrMorphHooksV6 || typeof window.Livewire?.hook !== 'function') {
                return;
            }

            window.__mmrTableSwrMorphHooksV6 = true;

            // Livewire merges the incoming snapshot before this hook, so
            // buildKey() reads the view being opened, not the one being left.
            window.Livewire.hook('morph', ({ el, toEl }) => prepareMorph(el, toEl));

            window.Livewire.hook('morph.updating', ({ el, skip }) => {
                if (skipHeldContentUpdate(el)) {
                    skip();
                }
            });

            // Filament omits pagination while records are deferred. Preserve the
            // existing real navigation node during that one intermediate morph;
            // it is normally morphed again when fresh records arrive.
            window.Livewire.hook('morph.removing', ({ el, skip }) => {
                if (skipHeldPaginationRemoval(el)) {
                    skip();
                }
            });

            window.Livewire.hook('morphed', ({ el }) => finishMorph(el));
        };

        const mutationTouchesWrapper = (mutation) => {
            const target = mutation.target;

            if (target instanceof Element && target.closest(WRAPPER_SELECTOR)) {
                return true;
            }

            return Array.from(mutation.addedNodes).some((node) => node instanceof Element
                && (node.matches(WRAPPER_SELECTOR) || node.querySelector(WRAPPER_SELECTOR)));
        };

        const init = () => {
            prune();
            registerMorphHooks();
            document.addEventListener('livewire:init', registerMorphHooks);
            document.addEventListener('livewire:navigated', registerMorphHooks);
            scan();

            if (documentObserver) {
                return;
            }

            documentObserver = new MutationObserver((mutations) => {
                if (mutations.some(mutationTouchesWrapper)) {
                    scan();
                }
            });
            documentObserver.observe(document.documentElement, {
                childList: true,
                subtree: true,
            });
        };

        window.__mmrTableSwrCacheV6 = { scan, init };
        init();
        document.addEventListener('livewire:navigated', scan);
    })();
</script>
