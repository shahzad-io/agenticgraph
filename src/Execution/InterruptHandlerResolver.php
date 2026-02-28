<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface;

interface InterruptHandlerResolver
{
    public function __invoke(): ?InterruptHandlerInterface;
}
