<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * One entry from resources/egg-profiles.json (or an operator-supplied extra
 * file - see EggProfileRegistry). Immutable; parsed once per request by
 * EggProfileRegistry and never mutated.
 */
final class EggProfile
{
    /**
     * @param array<int, string> $uuids
     * @param array<int, string> $updateUrlContains
     * @param array<int, string> $nameAliases normalized (lowercase, alphanumeric-only) names
     * @param array<int, array<int, string>> $variableSignatures each inner array is a sorted, uppercased set of env_variable names
     * @param 'resolved'|'manual_required'|'none' $status
     * @param 'mod'|'plugin'|'datapack'|null $projectType
     * @param array<int, string> $minecraftVersionVariables env_variable names to check, in priority order
     */
    public function __construct(
        public readonly string $id,
        public readonly array $uuids,
        public readonly array $updateUrlContains,
        public readonly array $nameAliases,
        public readonly array $variableSignatures,
        public readonly string $status,
        public readonly ?string $projectType,
        public readonly ?string $loader,
        public readonly bool $isProxy,
        public readonly array $minecraftVersionVariables,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $match = $data['match'] ?? [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            uuids: array_values(array_map('strval', $match['uuid'] ?? [])),
            updateUrlContains: array_values(array_map('strval', $match['update_url_contains'] ?? [])),
            nameAliases: array_values(array_map('strval', $match['name_aliases'] ?? [])),
            variableSignatures: array_values(array_map(
                fn (array $sig): array => array_values(array_map('strval', $sig)),
                $match['variable_signatures'] ?? [],
            )),
            status: (string) ($data['status'] ?? 'none'),
            projectType: isset($data['project_type']) ? (string) $data['project_type'] : null,
            loader: isset($data['loader']) ? (string) $data['loader'] : null,
            isProxy: (bool) ($data['is_proxy'] ?? false),
            minecraftVersionVariables: array_values(array_map('strval', $data['minecraft_version_variables'] ?? [])),
        );
    }
}
