<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Egg;
use App\Models\Server;
use Kazaminosuke\ModManager\Models\ModManagerEggProfile;

/**
 * Resolves what an egg is (project type / loader / datapack support / which
 * server variable holds the Minecraft version) when the plugin's own
 * explicit features/tags convention (mod_manager, plugin_manager,
 * datapack_manager, + a loader tag) isn't present on it - which every
 * community/official Minecraft egg is, by default (see Stage 8 design doc
 * 2 章/4 章: 0/54 of the eggs the panel's installer offers carry any of
 * those). ProjectType::fromServer()/supportsDatapacks() and
 * MinecraftLoader::fromServer() call this only as a fallback, after their
 * own unchanged explicit check has already returned null - see those
 * classes for the priority split. This class never overrides an explicit
 * result and never writes back to the Egg model (see EggImporterService::
 * fillFromParsed(), which overwrites features/tags on every egg update -
 * anything written there would be lost).
 *
 * Cascade (Stage 8 design doc 6-4), most to least certain:
 *   1. uuid match                                    exact
 *   2. update_url match                               exact
 *   3. normalized-name-alias AND variable-signature    exact
 *   4. normalized-name-alias alone, uniquely           high
 *   5. variable-signature alone, only if non-colliding  medium
 *   6. heuristic (project type only, never loader)      low
 *   7. a manually saved profile (Models\ModManagerEggProfile)
 *   8. MANUAL_REQUIRED - egg looks plausibly Minecraft-related but nothing
 *      above resolved it; the GUI fallback should prompt for configuration
 *   9. none - nothing suggests this egg is Minecraft-related at all
 *
 * Resolution touches only the Egg row and (for tiers 3-6) its variable
 * *names*, and the local mod_manager_egg_profiles table - never an upstream
 * API call, never Wings. Results are memoized per (server, egg) for the
 * life of the request/queue-job, mirroring MinecraftVersionResolver, since
 * ProjectType::fromServer() alone is called 30+ times in a single render.
 */
final class EggProfileResolver
{
    /**
     * Project-type-only heuristic markers: an env_variable name unique
     * enough to a mod loader that seeing it is a reasonably strong (but
     * still not certain) signal, without ever implying a specific loader.
     * Deliberately does not attempt to recognize plugin-server markers -
     * BUILD_NUMBER/SERVER_JARFILE-style names are too generic across both
     * project types to be useful signals on their own.
     *
     * @var array<int, string>
     */
    private const MOD_LOADER_VARIABLE_MARKERS = [
        'FABRIC_VERSION',
        'FORGE_VERSION',
        'NEOFORGE_VERSION',
        'QUILT_VERSION',
        'LOADER_VERSION',
    ];

    /** @var array<string, ResolvedEggProfile> */
    private static array $resolved = [];

    public static function resolve(Server $server): ResolvedEggProfile
    {
        $server->loadMissing('egg');
        $egg = $server->egg;

        $key = self::memoKey($server, $egg);

        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        return self::$resolved[$key] = self::resolveForEgg($egg);
    }

    public static function clear(): void
    {
        self::$resolved = [];
    }

    private static function memoKey(Server $server, ?Egg $egg): string
    {
        $serverKey = $server->getKey();
        $serverPart = is_int($serverKey) || is_string($serverKey)
            ? (string) $serverKey
            : 'object:'.spl_object_id($server);

        $eggPart = $egg?->getKey() !== null ? (string) $egg->getKey() : 'none:'.spl_object_id($egg ?? $server);

        return "$serverPart:$eggPart";
    }

    /**
     * The same cascade as resolve(), but keyed on an Egg directly rather
     * than a Server - for the one place this makes sense: the plugin
     * settings screen's egg-profile form (Stage 8, manual profiles are
     * saved per egg, not per server - see ModManagerPlugin), which has no
     * particular server to resolve from and just wants a best-effort
     * default to prefill. Not memoized (the settings screen resolves at
     * most a handful of eggs per page load, nowhere near
     * ProjectType::fromServer()'s 30+ calls per render).
     */
    public static function resolveForEgg(?Egg $egg): ResolvedEggProfile
    {
        if ($egg === null) {
            return ResolvedEggProfile::none();
        }

        [$profile, $source] = self::matchProfile($egg);

        if ($profile !== null && $profile->status === 'none') {
            return ResolvedEggProfile::none();
        }

        if ($profile !== null && $profile->status === 'resolved') {
            return ResolvedEggProfile::fromProfile($profile, $source);
        }

        // From here, $profile is either null (no JSON match at all) or a
        // manual_required match (a known-but-unresolvable-automatically egg,
        // e.g. a modpack). Both fall through to the same manual-profile
        // check and, failing that, the same "is this worth prompting for"
        // decision.
        $heuristicType = $profile === null ? self::heuristicProjectType($egg) : null;

        $manual = ModManagerEggProfile::query()->where('egg_id', $egg->getKey())->first();
        if ($manual !== null) {
            return ResolvedEggProfile::fromManual($manual);
        }

        $plausiblyMinecraft = $profile !== null
            || $heuristicType !== null
            || self::hasMinecraftTag($egg);

        return $plausiblyMinecraft
            ? ResolvedEggProfile::manualRequired($heuristicType)
            : ResolvedEggProfile::none();
    }

    /** @return array{0: ?EggProfile, 1: string} */
    private static function matchProfile(Egg $egg): array
    {
        if (($profile = EggProfileRegistry::findByUuid($egg->uuid)) !== null) {
            return [$profile, 'uuid'];
        }

        if (($profile = EggProfileRegistry::findByUpdateUrl($egg->update_url)) !== null) {
            return [$profile, 'update_url'];
        }

        $normalizedName = EggProfileRegistry::normalizeName($egg->name ?? '');
        $signature = EggProfileRegistry::normalizeSignature(self::eggVariableNames($egg));

        if ($signature !== [] && ($profile = EggProfileRegistry::findByNameAndSignature($normalizedName, $signature)) !== null) {
            return [$profile, 'name_signature'];
        }

        if (($profile = EggProfileRegistry::findByNameUnique($normalizedName)) !== null) {
            return [$profile, 'name'];
        }

        if ($signature !== [] && ($profile = EggProfileRegistry::findByNonCollidingSignature($signature)) !== null) {
            return [$profile, 'signature'];
        }

        return [null, 'none'];
    }

    private static function heuristicProjectType(Egg $egg): ?string
    {
        if (!self::hasMinecraftTag($egg)) {
            return null;
        }

        $names = array_map('strtoupper', self::eggVariableNames($egg));

        foreach (self::MOD_LOADER_VARIABLE_MARKERS as $marker) {
            if (in_array($marker, $names, true)) {
                return 'mod';
            }
        }

        // Plausibly a Minecraft egg (the 'minecraft' tag says so) but
        // nothing here is specific enough to commit to a project type -
        // returning null here still leads to MANUAL_REQUIRED rather than
        // "none" (see resolveUncached()'s $plausiblyMinecraft check), just
        // without a pre-filled guess in the fallback form.
        return null;
    }

    private static function hasMinecraftTag(Egg $egg): bool
    {
        return in_array('minecraft', $egg->tags ?? [], true);
    }

    /**
     * Prefers an already-loaded variables relation over issuing a fresh
     * query - not an optimization that matters in production (this egg's
     * variables are never preloaded before this call is reached), but what
     * lets a test inject fixture variables via Egg::setRelation('variables',
     * ...) instead of needing a real database connection just to exercise
     * the matching cascade.
     *
     * @return array<int, string>
     */
    private static function eggVariableNames(Egg $egg): array
    {
        $variables = $egg->relationLoaded('variables') ? $egg->variables : $egg->variables()->get();

        return $variables->pluck('env_variable')->all();
    }
}
