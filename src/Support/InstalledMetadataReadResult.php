<?php

namespace Boy132\MinecraftModrinth\Support;

class InstalledMetadataReadResult
{
    public function __construct(
        public readonly InstalledMetadataDocument $document,
        public readonly InstalledMetadataReadStatus $status,
    ) {}

    public function isAuthoritative(): bool
    {
        return $this->status->isAuthoritative();
    }
}
