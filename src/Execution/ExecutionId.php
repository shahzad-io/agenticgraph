<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Exceptions\InvalidExecutionIdException;

/**
 * The one source of execution ids. The execution id is also the trace id.
 */
final class ExecutionId
{
    public const MAX_LENGTH = 200;

    /**
     * exec_{unix time with microseconds}_{16 hex}: 64 random bits from random_bytes, at most 40 characters.
     */
    public static function generate(): string
    {
        return 'exec_'.microtime(true).'_'.bin2hex(random_bytes(8));
    }

    /**
     * A caller-supplied id as it is, a generated one for null.
     *
     * @throws InvalidExecutionIdException when the id is longer than MAX_LENGTH
     */
    public static function resolve(?string $given): string
    {
        if ($given === null) {
            return self::generate();
        }
        if (strlen($given) > self::MAX_LENGTH) {
            throw InvalidExecutionIdException::tooLong(strlen($given), self::MAX_LENGTH);
        }

        return $given;
    }
}
