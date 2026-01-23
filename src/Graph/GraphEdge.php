<?php

namespace Shahzad\AgenticGraph\Graph;

use Shahzad\AgenticGraph\Contracts\EdgeInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class GraphEdge implements EdgeInterface
{
    protected string $source;

    protected string|array $target;

    protected ?RouterInterface $condition;

    protected array $metadata;

    public function __construct(string $source, string|array $target, ?RouterInterface $condition = null, array $metadata = [])
    {
        $this->source = $source;
        $this->target = $target;
        $this->condition = $condition;
        $this->metadata = $metadata;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getTarget(): string|array
    {
        return $this->target;
    }

    public function getCondition(): ?RouterInterface
    {
        return $this->condition;
    }

    public function shouldTraverse(StateContract $state): bool
    {
        return true;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
