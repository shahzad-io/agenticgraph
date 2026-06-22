<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;

final class InterruptPersistenceDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Async resume via AgenticGraph::resume() requires interrupt persistence. '.
            'Enable agenticgraph.interrupts.persistence_enabled, or resume synchronously '.
            'with AgenticGraph::resumeInterrupt($checkpointId, $input) or a SYNC ExecutionHandle::resume().'
        );
    }
}
