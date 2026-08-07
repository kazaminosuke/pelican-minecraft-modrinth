<?php

namespace Kazaminosuke\ModManager\Support;

class LatestVersionLookupResult
{
    /**
     * @param array<string, array<string, mixed>> $versionsByKey
     * @param array<int, string> $unresolvedKeys
     * @param array<string, string> $failuresByKey
     * @param array<int, string> $pendingKeys A key is "pending" when its
     *     source's cache was a miss and a background revalidation was
     *     queued instead of fetching inline - distinct from unresolvedKeys,
     *     which means the upstream source was actually asked and had no
     *     match. Callers should render a "checking" state for pending keys
     *     rather than treating them as a confirmed no-update.
     */
    public function __construct(
        protected array $versionsByKey = [],
        protected array $unresolvedKeys = [],
        protected array $failuresByKey = [],
        protected array $pendingKeys = [],
    ) {
        $this->unresolvedKeys = array_values(array_unique($this->unresolvedKeys));
        $this->pendingKeys = array_values(array_unique($this->pendingKeys));
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

    /** @return array<int, string> */
    public function pendingKeys(): array
    {
        return $this->pendingKeys;
    }

    public function isPending(string $key): bool
    {
        return in_array($key, $this->pendingKeys, true);
    }

    /** @return array<string, mixed>|null */
    public function version(string $key): ?array
    {
        return $this->versionsByKey[$key] ?? null;
    }

    public function merge(self $other): self
    {
        // A key resolved or confirmed unresolved by one merge participant is
        // no longer pending, even if another participant also queued it -
        // resolved/failed/unresolved all take precedence over pending.
        $settledKeys = array_merge(
            array_keys($other->versionsByKey),
            $other->unresolvedKeys,
            array_keys($other->failuresByKey),
            array_keys($this->versionsByKey),
            $this->unresolvedKeys,
            array_keys($this->failuresByKey),
        );

        return new self(
            versionsByKey: array_replace($this->versionsByKey, $other->versionsByKey),
            unresolvedKeys: array_values(array_unique(array_merge($this->unresolvedKeys, $other->unresolvedKeys))),
            failuresByKey: array_replace($this->failuresByKey, $other->failuresByKey),
            pendingKeys: array_values(array_diff(
                array_unique(array_merge($this->pendingKeys, $other->pendingKeys)),
                $settledKeys,
            )),
        );
    }
}
