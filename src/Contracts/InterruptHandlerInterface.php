<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Execution\InterruptRecord;

interface InterruptHandlerInterface
{
    public function handle(InterruptRecord $interrupt): InterruptRecord;

    public function resolved(InterruptRecord $interrupt, mixed $resolution): void;

    public function failed(InterruptRecord $interrupt, mixed $context): void;
}
