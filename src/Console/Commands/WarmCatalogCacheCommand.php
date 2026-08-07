<?php

namespace Kazaminosuke\ModManager\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;

/**
 * Discovers the (loader, Minecraft version, project type) combinations
 * actually in use across every server and dispatches a WarmCatalogSearch
 * for each one's page 1, across every source that egg has enabled. This is
 * what actually prevents a cold first visit to the catalog tab - the
 * per-visit dispatch in ModManagerPage::mount() only ever helps a later
 * visit, since it can't land before the request that triggered it finishes.
 *
 * Registered on the Laravel scheduler (see ModManagerServiceProvider::
 * boot()) rather than run ad hoc; can also be run manually
 * (php artisan mod-manager:warm-catalog), which is the quickest way to
 * warm the cache before a manual "clear cache, then load the page" check.
 */
final class WarmCatalogCacheCommand extends Command
{
    protected $signature = 'mod-manager:warm-catalog';

    protected $description = 'Warm the mod-manager catalog cache for every (loader, Minecraft version, project type) combination actually in use.';

    public function handle(InstalledOperationManager $operations, ProjectSourceRegistry $registry): int
    {
        if (!(bool) config('pelican-minecraft-modrinth.warm_catalog_enabled', true)) {
            $this->comment('Catalog warming is disabled (pelican-minecraft-modrinth.warm_catalog_enabled).');

            return self::SUCCESS;
        }

        if (!$operations->supportsAsyncDispatch()) {
            $this->comment('Skipping: no async queue driver is configured. Dispatching warm jobs here would run them inline on the scheduler instead of in the background.');

            return self::SUCCESS;
        }

        $combos = $this->discoverCombos();

        if ($combos === []) {
            $this->info('No server currently has a supported mod/plugin manager egg configured; nothing to warm.');

            return self::SUCCESS;
        }

        // Prioritize combinations used by more servers: each warm job
        // dispatched benefits more visitors that way. Mirrors GDLauncher-
        // Carbon's "priority = currently viewed instance" idea, adapted for
        // a scheduled, cross-server job with no single "currently viewed"
        // server to prioritize.
        usort($combos, static fn (array $left, array $right): int => $right['server_count'] <=> $left['server_count']);

        $maxTargets = max(0, (int) config('pelican-minecraft-modrinth.warm_max_targets', 50));
        $selected = array_slice($combos, 0, $maxTargets);
        $skipped = count($combos) - count($selected);

        $dispatched = 0;

        foreach ($selected as $combo) {
            /** @var Server|null $server */
            $server = Server::query()->find($combo['server_id']);

            if (!$server) {
                continue;
            }

            $type = ProjectType::from($combo['project_type']);

            foreach ($registry->availableFor($server, $type) as $source) {
                if (!$source->isConfigured() || !$source->supportsSearch()) {
                    continue;
                }

                WarmCatalogSearch::dispatch(
                    $server->id,
                    $source->getKey()->value,
                    $type->value,
                    1,
                    $combo['loader'],
                    $combo['mc_version'],
                );
                $dispatched++;
            }
        }

        if ($skipped > 0) {
            $this->comment("Skipped {$skipped} lower-priority combination(s) past warm_max_targets ({$maxTargets}).");
        }

        $this->info(sprintf(
            'Dispatched %d catalog warm job(s) across %d combination(s).',
            $dispatched,
            count($selected),
        ));

        return self::SUCCESS;
    }

    /**
     * loader and project type are pure functions of a server's egg (see
     * ProjectType::fromServer() / MinecraftLoader::fromServer(), which
     * only ever inspect $server->egg->features/tags) - so grouping only
     * needs to happen in PHP; there is nothing per-server to look up for
     * those two. Minecraft version is the one genuinely per-server value.
     *
     * It's read here via a direct query against server_variables joined to
     * egg_variables, rather than through Server::variables() (as
     * MinecraftVersionResolver::resolve() does): that relationship's join
     * closure captures $this->id from whichever single Server instance
     * first built it, so it cannot be eager-loaded correctly across many
     * servers at once - Server::with('variables') would silently scope
     * every row to one server's id instead of each row's own.
     *
     * Two total queries (servers+egg via one eager-loaded query, then one
     * direct join for the Minecraft version variable) rather than one
     * query per server.
     *
     * @return array<int, array{loader: string, mc_version: string, project_type: string, server_id: int, server_count: int}>
     */
    protected function discoverCombos(): array
    {
        $servers = Server::query()
            ->with('egg:id,features,tags')
            ->get(['id', 'egg_id']);

        if ($servers->isEmpty()) {
            return [];
        }

        /** @var Collection<int, string> $mcVersionsByServerId */
        $mcVersionsByServerId = DB::table('server_variables')
            ->join('egg_variables', 'egg_variables.id', '=', 'server_variables.variable_id')
            ->whereIn('egg_variables.env_variable', ['MINECRAFT_VERSION', 'MC_VERSION'])
            ->whereIn('server_variables.server_id', $servers->pluck('id'))
            ->pluck('server_variables.variable_value', 'server_variables.server_id');

        $defaultMcVersion = config('pelican-minecraft-modrinth.latest_minecraft_version');
        $combos = [];

        foreach ($servers as $server) {
            $type = ProjectType::fromServer($server);

            if ($type === null) {
                continue;
            }

            $loader = $type->getLoaderSlug($server);

            if ($loader === null) {
                continue;
            }

            $mcVersion = $mcVersionsByServerId->get($server->id);

            if (!is_string($mcVersion) || $mcVersion === '' || $mcVersion === 'latest') {
                $mcVersion = $defaultMcVersion;
            }

            if (!is_string($mcVersion) || $mcVersion === '') {
                continue;
            }

            $key = "$loader:$mcVersion:{$type->value}";

            $combos[$key] ??= [
                'loader' => $loader,
                'mc_version' => $mcVersion,
                'project_type' => $type->value,
                'server_id' => $server->id,
                'server_count' => 0,
            ];
            $combos[$key]['server_count']++;
        }

        return array_values($combos);
    }
}
