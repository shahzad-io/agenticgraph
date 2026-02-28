<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Shahzad\AgenticGraph\Tracing\TraceContext;

interface TraceContextProvider
{
    public function __invoke(): ?TraceContext;
}
