<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Contracts\Container\Container;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use LogicException;

/**
 * Resolves the registry lazily to avoid a Source -> SourceCache -> Registry ->
 * Source constructor cycle when source implementations adopt SourceCache.
 */
final class SourceFetchExecutor implements SourceFetchExecutorInterface
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
    {
        if ($spec->operation !== 'search' || !RequestPerformanceProfiler::isCapturing()) {
            return $this->handler($spec)->fetchSourceData($spec, $timeoutSeconds);
        }

        $startedAt = microtime(true);

        try {
            return $this->handler($spec)->fetchSourceData($spec, $timeoutSeconds);
        } finally {
            RequestPerformanceProfiler::addSearchApi(
                $spec->sourceKey,
                (int) round((microtime(true) - $startedAt) * 1000),
            );
        }
    }

    public function emptyResult(SourceFetchSpec $spec): mixed
    {
        return $this->handler($spec)->emptySourceData($spec);
    }

    private function handler(SourceFetchSpec $spec): SourceFetchHandlerInterface
    {
        /** @var ProjectSourceRegistry $registry */
        $registry = $this->container->make(ProjectSourceRegistry::class);
        $source = $registry->getByValue($spec->sourceKey);

        if (!$source instanceof SourceFetchHandlerInterface) {
            throw new LogicException("Source [{$spec->sourceKey}] cannot execute cached fetch operations.");
        }

        return $source;
    }
}
