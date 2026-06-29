<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Classifies database queries by SQL verb for tracing (read vs write).
 *
 * This is intentionally separate from Laravel's QueryExecuted::$readWriteType,
 * which indicates which PDO connection pool was used (read replica vs write master),
 * not whether the statement is a read or mutating query.
 */
final class DbQueryType
{
    /**
     * @return 'read'|'write'|null
     */
    public static function fromSql(string $sql): ?string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE|DESC|PRAGMA)\b/i', $normalized, $matches) === 1) {
            return 'read';
        }

        if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|MERGE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $normalized, $matches) === 1) {
            return 'write';
        }

        return null;
    }
}
