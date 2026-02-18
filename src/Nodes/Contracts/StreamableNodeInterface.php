<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Nodes\Contracts;

use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;

interface StreamableNodeInterface extends NodeInterface
{
    public function executeStreaming(StateContract $state, StreamSinkInterface $sink): StateContract;
}
