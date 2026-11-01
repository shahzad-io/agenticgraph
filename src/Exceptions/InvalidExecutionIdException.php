<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use InvalidArgumentException;

final class InvalidExecutionIdException extends InvalidArgumentException
{
    public static function tooLong(int $length, int $max): self
    {
        return new self("The execution id is {$length} characters long; the maximum is {$max}. It is also the trace id, so pass a shorter id or none to have one generated.");
    }
}
