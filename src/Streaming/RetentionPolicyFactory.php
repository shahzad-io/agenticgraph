<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Store\RetentionPolicy;

final class RetentionPolicyFactory
{
    public function create(): RetentionPolicy
    {
        return new RetentionPolicy;
    }
}
