<?php

namespace Kazaminosuke\ModManager\Contracts;

use Kazaminosuke\ModManager\Support\SourceFetchSpec;

interface SourceFetchExecutorInterface
{
    public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed;

    public function emptyResult(SourceFetchSpec $spec): mixed;
}
