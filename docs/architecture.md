# Architecture

Internal design notes for this plugin. [`README.md`](../README.md) covers installation and
configuration; this document covers how the pieces underneath actually work, for anyone
maintaining the plugin or adding a new source.

## Metadata index

Each server's installed mods/plugins/datapacks are tracked in a JSON document written next to the
managed folder: `.pelican-mod-manager.json`. Reading it (`InstalledMetadataRepository::read()`)
falls back to the older `.modrinth-metadata.json` filename only when the current file is missing,
unreadable, or invalid - a valid current document with an empty `installed_mods` array is
authoritative and is never overridden by a stale legacy file. Writes always replace the whole
document under a per-server lock; there is no partial/streaming write path.

Each entry records the installed file's name, the resolved upstream project/version identifiers,
which source it came from, and a `file_signature` (see below) used to skip re-hashing unchanged
files. Files present on disk but not yet in the document surface as "Not tracked" rows until a
scan resolves them.

## Incremental hash scanning

A full re-hash of every installed file on every scan does not scale once a server has hundreds of
mods. `InstalledProjectService`'s scan instead compares each file's current `{size, modified_at}`
against the `file_signature` stored from its last successful hash, and only re-hashes when that
signature has changed (or is absent). A case-insensitive filename collision on disk (two files
that would map to the same tracked entry) deliberately discards any reusable signature and forces
a fresh hash, since the persisted index cannot safely disambiguate them.

## Background jobs & status badges

Scans and bulk updates run as queued jobs (`InstalledOperationManager`, statuses `queued` →
`running` → `completed`/`failed`) so a slow Wings directory listing or a batch of upstream update
checks never blocks a Livewire request. The Installed tab polls for the active operation's state
and reflects it as a badge; `supportsAsyncDispatch()` is checked before dispatching anything, and
every entry point that would otherwise dispatch synchronously on the `sync`/`null` queue drivers
shows a warning instead.

## Stale-while-revalidate cache layer

Every upstream call (search, project metadata, version lookups, hash matching) goes through
`Support\SourceCache`, keyed by a `Support\SourceFetchSpec` (source key + operation + canonicalized
arguments, hashed into `{source}_{operation}:v3:{sha256}`). A `Support\CacheProfile` decides how
long each kind of data stays fresh vs. how long a stale copy is still servable while a refresh runs
in the background:

| Profile | Fresh | Stale | Used for |
|---|---|---|---|
| `HashMatch` / `VersionFile` | 30 days | forever | Hash-to-version lookups, version files |
| `Search` | 10 min | 24 h | Catalog search pages |
| `ProjectMetadata` | 24 h | 7 days | Project detail (title, icon, downloads, ...) |
| `InstalledLatest` | 30 min | 24 h | "Is there a newer version" checks |
| `Identity` | 7 days | 30 days | Author/team display names |

Read paths, roughly fastest-to-most-thorough:

- **`peek()`** - reads the cache only; never fetches or dispatches. Returns whether the entry
  exists and whether it's still fresh.
- **`swrDeferred()`** - the non-blocking pattern used by progressive-enrichment renders (the
  Installed tab): a hit returns immediately (dispatching a background refresh if stale); a miss
  queues a background fetch and returns an empty placeholder with `pending: true` instead of
  blocking.
- **`swr()`** - the general-purpose path: a fresh hit returns immediately; a stale hit returns
  immediately and dispatches a background refresh; a miss fetches inline, bounded by the profile's
  `inlineBudgetSeconds()` (1.5s).
- **`swrRequired()`** - for authoritative workflows (an Installed scan matching a file's hash) that
  must not silently treat a transport failure as "no match" - a cold failure is rethrown rather
  than swallowed into an empty result.

A failed fetch writes a short-lived failure marker (`failureMarkerTtlSeconds()`, 30s) so a burst of
requests during an outage doesn't retry the same failing call repeatedly.

## Warm jobs & rate limiting

Two independent warming paths keep the cache populated ahead of a real visit:

- **`Jobs\WarmCatalogSearch`**, dispatched per-visit from `ModManagerPage::mount()` for every
  available source's first page (plus the active source's second page), and in bulk by the
  scheduled **`mod-manager:warm-catalog`** command (`Console\Commands\WarmCatalogCacheCommand`,
  registered on Laravel's scheduler every 10 minutes - matching `CacheProfile::Search`'s fresh
  TTL). The command discovers every `(loader, Minecraft version, project type)` combination
  actually in use in one eager-loaded query plus one direct `server_variables`/`egg_variables`
  join (not `Server::variables()`, whose join clause cannot be eager-loaded correctly across many
  servers at once), prioritizes by how many servers share each combination, and caps the number of
  combinations it acts on per run via `warm_max_targets`.
- **`Jobs\WarmProjectMetadata`**, dispatched once per source per Installed-tab render to backfill
  every project metadata cache miss on that page in a single batched upstream call, instead of one
  job per missing project.

`Support\WarmRequestThrottle` (a `RateLimiter`-backed per-minute ceiling, configurable per source
under `warm_rate_limit`) applies only to the two `WarmCatalogSearch`/`WarmCatalogCacheCommand`
paths above - never to search/install/tab-switch traffic a user actually triggered, and not to
`WarmProjectMetadata` either, since that only ever runs because a user is looking at their own
Installed tab. `warm_catalog_enabled` is the overall kill switch; setting a source's own
`warm_rate_limit` entry to `0` disables warming for just that source.

## Conditional deferred table loading

Filament's table `deferLoading()` accepts a closure. `ModManagerPage::hasWarmRecordsCache()` peeks
(no fetch, no dispatch) whether the current view's data is already cached, and the table only defers
when it isn't - a cached view renders synchronously instead of paying for the extra
`wire:init="loadTable"` round trip every deferred table costs. The Installed tab is deliberately
excluded: an Installed-tab render can still trigger a synchronous Wings scan when no scan result is
cached yet and no queue is configured, and the metadata cache `hasWarmRecordsCache()` can see is not
the same, shorter-lived cache that guards that call - so a "warm" verdict there would not reliably
guarantee a fast response.

## Client-side SWR table preview

`resources/views/components/table-swr-cache.blade.php` keeps the table feeling instant across a
stale-while-revalidate refresh, purely in the browser. It hooks Livewire's `morph`/`morphed`
lifecycle: while an incoming response is still the deferred-loading placeholder, it holds the
*previous* render's cell values and row-action state in place (marked `inert`/stale) instead of
letting Filament's own loading skeleton flash in; once the real response morphs in, the hold is
released and the fresh render is captured into `sessionStorage` for next time.

It **only ever stores display values** (icon, title, badges, numbers, row-action visibility) it
reads back out of already-rendered DOM cells - never raw API responses, and never anything besides
what was already visible to this browser. Nothing is captured, and no stale preview is ever held,
while the current content already contains real data (i.e., whenever `hasWarmRecordsCache()`
skipped the defer for that request) - there is no placeholder for it to react to, so it reduces to
the same "capture the real render" step every deferred load already ends with.

## Fixed-height layout

`resources/views/components/table-layout.blade.php` measures three numbers a pure-CSS layout
cannot derive on its own (available viewport height, trailing page chrome, and the table's own
`--mmr-table-top` offset) and feeds them into a fixed-height flex column: the row viewport takes
the slack, and the paginator is pinned as the last item in that column regardless of how many rows
the current page has. This is what keeps the paginator from jumping around between a loading
placeholder, a short results page, and a full one.

## Automatic egg detection

None of the Minecraft eggs the panel's own installer offers carry this plugin's `mod_manager`/
`plugin_manager`/`datapack_manager` feature or a loader tag out of the box - every one of them used
to need manual egg editing before this plugin would do anything with it. `Support\
EggProfileResolver` recognizes them (and their Pterodactyl-ecosystem equivalents) automatically,
falling back through a strictly-ordered cascade, most to least certain:

1. **Explicit** - the egg's own `features`/`tags` (unchanged pre-auto-detection behavior; always
   checked first by `ProjectType::fromServer()`/`supportsDatapacks()` and `MinecraftLoader::
   fromServer()` themselves, so an egg already tagged this way never even reaches the resolver).
2. **uuid match** against `resources/egg-profiles.json`'s bundled profile database.
3. **update_url match** (covers a uuid that has since changed - both Paper's and Forge Minecraft's
   have, historically).
4. **Normalized name + variable signature match, both together.** This is what safely resolves a
   Pterodactyl-ecosystem egg (no uuid, no update_url at all): Pterodactyl's Paper and Folia eggs
   happen to share an identical variable-name signature, so signature alone would misidentify one
   as the other - the name is what disambiguates them.
5. **Normalized name alone**, only when it uniquely identifies one profile.
6. **Variable signature alone**, only when that exact signature isn't shared by more than one
   profile (a "renamed egg" rescue path - collisions, computed once across the whole profile set at
   load time, are never treated as safe to guess from, no matter how the lookup got there).
7. **Heuristic** - project type only (never a loader guess), and only when the egg's `tags` include
   `minecraft` or a mod-loader-specific variable name (`FABRIC_VERSION`, `FORGE_VERSION`, ...) is
   present.
8. **A manually saved profile** (`Models\ModManagerEggProfile`, one row per egg - not per server,
   since loader/project type/datapack support are properties of the egg itself).
9. **Manual setup prompt.** An egg nothing above could place, but that still looks plausibly
   Minecraft-related (a `minecraft` tag, or a matched-but-unresolvable profile - mostly modpack
   eggs, which only ever expose a `MODPACK_VERSION`-style variable with no loader/game-version
   information at all), shows a short in-page notice instead of disappearing. Configuring it there
   saves a row via step 8, and every server sharing that egg resolves from it afterwards.
10. **Nothing.** An egg with no Minecraft signal at all (Bedrock eggs, non-Java implementations,
    ...) is excluded outright (`resources/egg-profiles.json`'s `status: "none"` entries) and never
    reaches step 9.

Resolution only ever reads the `Egg` row, its variables' *names* (never their values, except for
the Minecraft-version lookup below), and the local `mod_manager_egg_profiles` table - never an
upstream API call, never Wings - and is memoized per `(server, egg)` for the life of the request/
queue job, the same as `Support\MinecraftVersionResolver`, since `ProjectType::fromServer()` alone
is called 30+ times in a single render.

**Never writes back to the `Egg` model.** `EggImporterService::fillFromParsed()` overwrites
`features`/`tags` on every egg update pulled from its `update_url`, so anything written there would
be silently lost on the next sync.

### Minecraft version resolution

`MinecraftVersionResolver::resolve()` checks, in order: a manually saved profile's explicit version
override; then the resolved profile's own variable name(s) (e.g. Spigot's `DL_VERSION`, Vanilla's
`VANILLA_VERSION` - egg families that never define `MINECRAFT_VERSION`/`MC_VERSION` at all, so the
next step used to silently fall through to the global default for them); then the generic
`MINECRAFT_VERSION`/`MC_VERSION` variable names; then the plugin's own configured default. Most egg
families resolve via the generic names either way, so this is a no-op change for them.

### Datapack support default

A resolved Java-server-family profile (mod/plugin/hybrid/vanilla/modpack, but not a proxy) now
defaults `supportsDatapacks()` to `true` - a Minecraft Java world can always accept a datapack, so
requiring the separate `datapack_manager` feature mostly added friction. Add
`datapack_manager_disabled` to an egg's `features` for the old, opt-in-only behavior on that one
egg; `datapack_manager` still force-enables it explicitly, taking priority over everything.

### Manually configuring an egg

The plugin settings screen has an **Egg profiles** action (always admin-only) that saves/clears a
row in `mod_manager_egg_profiles` for any egg. A server whose egg needs one also shows a form
directly on its own page - gated by the **"Allow users to configure egg profiles"** toggle in the
same settings screen (off by default): off, only an administrator sees it there too; on, any user
holding `SubuserPermission::StartupUpdate` on that server can save one, the same permission that
already lets them edit its Minecraft-version startup variable. A save applies to every server
sharing that egg, not just the one it was made from.

`config('pelican-minecraft-modrinth.egg_autodetect_enabled')` (env `MOD_MANAGER_EGG_AUTODETECT`,
default `true`) is the overall kill switch - `false` reverts every wired method to exactly its
pre-auto-detection behavior. `egg_profiles_extra_path` (env `MOD_MANAGER_EGG_PROFILES_PATH`) merges
in an operator-supplied JSON file in the same shape as `resources/egg-profiles.json`, for a
private/community egg this plugin doesn't ship a profile for.

## Cache key reference

| Prefix | Built by | Scope |
|---|---|---|
| `{source}_{operation}:v3:{sha256}` | `SourceFetchSpec::cacheKey()` | One upstream call's arguments |
| `{source}_{operation}:v3:{sha256}:failure:v1` | `SourceCache` | Failure marker for the entry above |
| `mmr_cache_gen:hydrate:{server_id}` | `Support\CacheVersion` | Installed-tab hydration "generation" stamp, per server |
| `mmr_cache_gen:hangar_hash` | `Support\CacheVersion` | Hangar hash-match cache generation (not per-server) |
| `mod_manager_operation:v1:...` | `Services\InstalledOperationManager` | Scan/bulk-update operation state |
| installed-scan cache key | `ModManager::getHashScanCacheKey()` | Wings directory-listing throttle, per server+type |

`CacheVersion`'s generation stamps exist because most cache drivers this plugin needs to support
(file, database) have no wildcard/pattern delete - bumping a stamp baked into a key makes a whole
family of entries unreachable at once without needing to enumerate them; the orphaned entries just
expire on their own TTL later.

## Adding a new source

1. Implement `Contracts\ProjectSourceInterface` (and `SourceFetchHandlerInterface` so `SourceCache`
   can execute its fetches; add `BatchLatestVersionSourceInterface` if the upstream API can check
   many installed versions in one call, and `SourceFetchAuthoritativeInterface` if a scan needs a
   non-swallowing fetch path for it).
2. Route every upstream call through `Support\SourceCache` via a `Support\SourceFetchSpec` built
   from a private `spec()`/`buildSearchSpec()`-style helper, so `hasCachedSearch()`/`peekProject()`
   and the real fetch path can never drift onto different cache keys.
3. Add the new `Enums\ProjectSourceKey` case and register the source in
   `Support\ProjectSourceRegistry` (constructor wiring + `availableFor()`'s egg-feature gate, if the
   source should be opt-in per egg like CurseForge/Hangar/GitHub Releases).
4. Add its config keys (API key, rate limit default) to
   `config/pelican-minecraft-modrinth.php` and the settings screen if it needs one.
5. Update the source table in `README.md` (and `README.ja.md`) and this document.
