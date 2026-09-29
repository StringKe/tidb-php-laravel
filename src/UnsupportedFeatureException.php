<?php

namespace StringKe\TidbPhp\Laravel;

use RuntimeException;

/**
 * Thrown while compiling SQL that TiDB rejects, or accepts and silently ignores.
 */
final class UnsupportedFeatureException extends RuntimeException
{
    public static function because(string $feature, string $reason): self
    {
        return new self("TiDB does not support {$feature}: {$reason}");
    }
}
