<?php

namespace Kazaminosuke\ModManager\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;

final class RevalidateSourceCache implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly SourceFetchSpec $spec,
        public readonly CacheProfile $profile,
    ) {}

    public function uniqueId(): string
    {
        return $this->spec->cacheKey();
    }

    public function handle(SourceCache $cache): void
    {
        $cache->revalidate($this->spec, $this->profile);
    }
}
