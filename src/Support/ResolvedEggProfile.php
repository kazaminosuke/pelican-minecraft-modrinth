<?php

namespace Kazaminosuke\ModManager\Support;

use Kazaminosuke\ModManager\Models\ModManagerEggProfile;

/**
 * The outcome of EggProfileResolver's cascade for one server's egg.
 * Immutable; carries enough to both drive the four wired methods
 * (ProjectType::fromServer()/supportsDatapacks(), MinecraftLoader::
 * fromServer(), MinecraftVersionResolver::resolve()) and to explain, for the
 * diagnostic display, what actually decided the result.
 */
final class ResolvedEggProfile
{
    /**
     * @param 'mod'|'plugin'|'datapack'|null $projectType
     * @param array<int, string> $minecraftVersionVariables env_variable names to check, in priority order (empty when the egg family has none reliable - e.g. Bungeecord)
     * @param 'resolved'|'manual_required'|'none' $status
     * @param 'uuid'|'update_url'|'name_signature'|'name'|'signature'|'heuristic'|'manual'|'none' $source
     */
    private function __construct(
        public readonly ?string $projectType,
        public readonly ?string $loader,
        public readonly bool $supportsDatapacks,
        public readonly array $minecraftVersionVariables,
        public readonly ?string $minecraftVersionOverride,
        public readonly string $status,
        public readonly string $source,
    ) {}

    public static function fromProfile(EggProfile $profile, string $source): self
    {
        return new self(
            projectType: $profile->projectType,
            loader: $profile->loader,
            // Datapack support only auto-enables for profiles the JSON
            // database itself resolved (never for proxies, and never for
            // manual_required/none, which fall through to false here and
            // are handled by fromManual()/manualRequired() instead) - see
            // Stage 8 design 5 章.
            supportsDatapacks: $profile->status === 'resolved' && !$profile->isProxy,
            minecraftVersionVariables: $profile->minecraftVersionVariables,
            minecraftVersionOverride: null,
            status: $profile->status,
            source: $source,
        );
    }

    public static function fromManual(ModManagerEggProfile $manual): self
    {
        return new self(
            projectType: $manual->project_type,
            loader: $manual->loader,
            supportsDatapacks: (bool) $manual->supports_datapacks,
            minecraftVersionVariables: [],
            minecraftVersionOverride: $manual->minecraft_version,
            status: 'resolved',
            source: 'manual',
        );
    }

    public static function manualRequired(?string $heuristicProjectType): self
    {
        return new self(
            projectType: $heuristicProjectType,
            loader: null,
            supportsDatapacks: false,
            minecraftVersionVariables: [],
            minecraftVersionOverride: null,
            status: 'manual_required',
            source: $heuristicProjectType !== null ? 'heuristic' : 'none',
        );
    }

    public static function none(): self
    {
        return new self(
            projectType: null,
            loader: null,
            supportsDatapacks: false,
            minecraftVersionVariables: [],
            minecraftVersionOverride: null,
            status: 'none',
            source: 'none',
        );
    }

    public function needsManualSetup(): bool
    {
        return $this->status === 'manual_required';
    }
}
