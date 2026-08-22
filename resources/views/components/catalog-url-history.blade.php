<script data-navigate-once>
    (() => {
        'use strict';

        // Livewire tracks source and page as separate URL properties. A tab
        // switch can therefore change both in one commit, which would make
        // Livewire push the intermediate URL once for each property. Keep
        // Livewire's standard URL hydration, pop handling and canonical query
        // cleanup, but collapse those same-commit pushes to one entry.
        const install = () => {
            if (window.__mmrCatalogUrlHistory || typeof window.Livewire?.hook !== 'function') {
                return;
            }

            const activeCommits = new Set();
            const pushState = window.history.pushState.bind(window.history);
            const replaceState = window.history.replaceState.bind(window.history);

            const isCatalogComponent = (component) => {
                const canonical = component?.canonical;

                return canonical
                    && Object.prototype.hasOwnProperty.call(canonical, 'source')
                    && Object.prototype.hasOwnProperty.call(canonical, 'catalogPage');
            };

            window.history.pushState = (state, title, url) => {
                const commit = [...activeCommits].at(-1);

                if (!commit) {
                    return pushState(state, title, url);
                }

                commit.pushes++;

                // The second URL property belongs to the same Livewire
                // commit. Replacing the first push preserves the final URL
                // while preventing an unreachable intermediate history entry.
                if (commit.pushes > 1) {
                    return replaceState(state, title, url);
                }

                return pushState(state, title, url);
            };

            window.Livewire.hook('commit', ({ component, succeed }) => {
                if (!isCatalogComponent(component)) {
                    return;
                }

                const commit = { pushes: 0 };
                activeCommits.add(commit);

                succeed(() => {
                    // URL effects are registered as commit success callbacks.
                    // Defer cleanup until all callbacks for this commit have
                    // run, so every same-commit push is coalesced.
                    queueMicrotask(() => activeCommits.delete(commit));
                });
            });

            window.__mmrCatalogUrlHistory = true;
        };

        if (window.Livewire) {
            install();
        } else {
            document.addEventListener('livewire:init', install, { once: true });
        }
    })();
</script>
