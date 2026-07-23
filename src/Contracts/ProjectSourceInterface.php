<?php

namespace Boy132\MinecraftModrinth\Contracts;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Enums\ProjectSourceKey;

/**
 * Contract implemented by every mod/plugin/datapack source (Modrinth, CurseForge, Hangar, ...).
 *
 * Methods that depend on an optional capability (search, hash lookup, direct-identifier
 * resolution) must be guarded by the matching `supports*()` / `requiresApiKey()` /
 * `isConfigured()` check before being called. Unsupported calls should degrade gracefully
 * (empty array / null) rather than throw, except where noted otherwise.
 */
interface ProjectSourceInterface
{
    public function getKey(): ProjectSourceKey;

    public function getLabel(): string;

    public function requiresApiKey(): bool;

    public function isConfigured(): bool;

    public function supportsProjectType(ModrinthProjectType $type): bool;

    /**
     * Whether this source exposes a searchable catalog (browse/search UI).
     *
     * Sources without a catalog (e.g. GitHub Releases, which tracks a specific
     * "owner/repo" rather than browsing an index) return false here and instead
     * rely on `supportsDirectIdentifier()` / `resolveProjectByIdentifier()`.
     */
    public function supportsSearch(): bool;

    public function supportsHashLookup(): bool;

    /** @return string|null e.g. 'sha512', 'murmur2', or null when `supportsHashLookup()` is false */
    public function getHashAlgorithm(): ?string;

    /**
     * Whether a project can be tracked by a source-specific direct identifier
     * (e.g. "owner/repo" for GitHub Releases) instead of catalog search.
     */
    public function supportsDirectIdentifier(): bool;

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ModrinthProjectType $type, int $page, ?string $search = null, array $filters = []): array;

    /** @return array<string, mixed>|null normalized project data */
    public function getProject(string $projectId): ?array;

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed> [projectId => normalized project data]
     *
     * @throws \Exception implementations may throw on transport failure; callers performing
     *                     bulk lookups during scans are expected to catch and handle this
     */
    public function getProjectsByIds(array $projectIds): array;

    /** @return array<int, mixed> normalized versions, newest first */
    public function getVersions(string $projectId, Server $server, ModrinthProjectType $type): array;

    /**
     * @param array<string, string> $hashesByFilename [filename => hash]
     * @return array<string, mixed> [hash => normalized version data]
     */
    public function findVersionsByHash(array $hashesByFilename): array;

    /**
     * Resolve a project via a source-specific direct identifier
     * (e.g. "owner/repo" for GitHub Releases, or an ID/slug for catalog sources).
     *
     * @return array<string, mixed>|null normalized project data
     */
    public function resolveProjectByIdentifier(string $identifier): ?array;
}
