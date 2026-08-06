<?php

namespace Kazaminosuke\ModManager\Support;

use InvalidArgumentException;
use JsonException;

/**
 * Serializable description of an upstream source operation.
 *
 * Argument leaves are limited to scalar/null values. Lists and associative
 * arrays composed from those leaves are allowed so batch IDs, hashes, and
 * normalized search filters can be represented without serializing models or
 * closures.
 */
final class SourceFetchSpec
{
    /** @param array<int|string, mixed> $arguments */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $operation,
        public readonly array $arguments = [],
    ) {
        if ($sourceKey === '' || preg_match('/^[a-z0-9_]+$/', $sourceKey) !== 1) {
            throw new InvalidArgumentException('The source fetch key must contain only lowercase letters, numbers, and underscores.');
        }

        if ($operation === '' || preg_match('/^[a-z0-9_]+$/', $operation) !== 1) {
            throw new InvalidArgumentException('The source fetch operation must contain only lowercase letters, numbers, and underscores.');
        }

        self::assertSerializableArguments($arguments);
    }

    /**
     * Cache keys are independent of PHP array insertion order for associative
     * arguments. List order is retained because it can be meaningful to an
     * operation; callers should sort set-like ID/hash lists before building
     * the specification.
     *
     * @throws JsonException
     */
    public function cacheKey(): string
    {
        $arguments = self::canonicalize($this->arguments);
        $encoded = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return "{$this->sourceKey}_{$this->operation}:v3:".hash('sha256', $encoded);
    }

    /** @param array<int|string, mixed> $arguments */
    private static function assertSerializableArguments(array $arguments): void
    {
        foreach ($arguments as $value) {
            if (is_array($value)) {
                self::assertSerializableArguments($value);

                continue;
            }

            if (!is_null($value) && !is_scalar($value)) {
                throw new InvalidArgumentException('Source fetch arguments may contain only scalar, null, or array values.');
            }
        }
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private static function canonicalize(array $arguments): array
    {
        if (!array_is_list($arguments)) {
            ksort($arguments);
        }

        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $arguments[$key] = self::canonicalize($value);
            }
        }

        return $arguments;
    }
}
