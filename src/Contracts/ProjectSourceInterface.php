<?php

namespace Kazaminosuke\ModManager\Contracts;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;

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

    public function supportsProjectType(ProjectType $type): bool;

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
    public function search(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): array;

    /** @return array<string, mixed>|null normalized project data */
    public function getProject(string $projectId): ?array;

    /**
     * Non-blocking counterpart to getProject(): a fresh or stale cache hit
     * returns its data immediately; a miss queues a background refresh
     * (when the queue supports it) and comes back with pending true
     * instead of performing an inline fetch. Uses a per-project cache
     * entry, so one project's revalidation never invalidates another's.
     *
     * @return array{data: array<string, mixed>|null, pending: bool}
     */
    public function peekProject(string $projectId): array;

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed> [projectId => normalized project data]
     *
     * @throws \Exception implementations may throw on transport failure; callers performing
     *                     bulk lookups during scans are expected to catch and handle this
     */
    public function getProjectsByIds(array $projectIds): array;

    /** @return array<int, mixed> normalized versions, newest first */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array;

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
