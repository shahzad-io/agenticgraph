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

    /**
     * The SQL verb for db.operation.name, as written (OTel: no case normalization). Null for
     * text with more than one statement, where OTel says not to parse the operation.
     */
    public static function operation(string $sql): ?string
    {
        $normalized = rtrim(trim($sql), ';');
        if (self::fromSql($normalized) === null || str_contains($normalized, ';')) {
            return null;
        }

        return strtok($normalized, " \t\n\r(") ?: null;
    }
}
