<?php

namespace Boy132\MinecraftModrinth\Support;

class LatestVersionLookupResult
{
    /**
     * @param array<string, array<string, mixed>> $versionsByKey
     * @param array<int, string> $unresolvedKeys
     * @param array<string, string> $failuresByKey
     */
    public function __construct(
        protected array $versionsByKey = [],
        protected array $unresolvedKeys = [],
        protected array $failuresByKey = [],
    ) {
        $this->unresolvedKeys = array_values(array_unique($this->unresolvedKeys));
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public static function failed(array $requests, string $message): self
    {
        $failures = [];

        foreach ($requests as $request) {
            if ($request instanceof LatestVersionLookupRequest) {
                $failures[$request->key()] = $message;
            }
        }

        return new self(failuresByKey: $failures);
    }

    /** @return array<string, array<string, mixed>> */
    public function versions(): array
    {
        return $this->versionsByKey;
    }

    /** @return array<int, string> */
    public function unresolvedKeys(): array
    {
        return $this->unresolvedKeys;
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failuresByKey;
    }

    /** @return array<string, mixed>|null */
    public function version(string $key): ?array
    {
        return $this->versionsByKey[$key] ?? null;
    }

    public function merge(self $other): self
    {
        return new self(
            versionsByKey: array_replace($this->versionsByKey, $other->versionsByKey),
            unresolvedKeys: array_values(array_unique(array_merge($this->unresolvedKeys, $other->unresolvedKeys))),
            failuresByKey: array_replace($this->failuresByKey, $other->failuresByKey),
        );
    }
}
