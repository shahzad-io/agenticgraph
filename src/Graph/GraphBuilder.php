<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Graph;

use Illuminate\Container\Container;
use Shahzad\AgenticGraph\Contracts\RouterInterface;

class GraphBuilder
{
    protected string $name;

    protected string $description = '';

    protected ?string $entryPoint = null;

    protected array $exitPoints = [];

    protected array $nodes = [];

    protected array $edges = [];

    protected array $metadata = [];

    protected array $joins = [];

    public static function create(string $name): self
    {
        $i = new self;
        $i->name = $name;

        return $i;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function addNode(string $name, string $class): self
    {
        $this->nodes[$name] = $class;

        return $this;
    }

    public function addEdge(string $source, string $target, array $metadata = []): self
    {
        $this->edges[] = new GraphEdge($source, $target, null, $metadata);

        return $this;
    }

    public function addConditionalEdge(string $source, RouterInterface|string $router, array $metadata = []): self
    {
        $instance = is_string($router) ? Container::getInstance()->make($router) : $router;
        $this->edges[] = new GraphEdge($source, [], $instance, $metadata);

        return $this;
    }

    public function setEntryPoint(string $name): self
    {
        $this->entryPoint = $name;

        return $this;
    }

    public function addExitPoint(string $name): self
    {
        $this->exitPoints[] = $name;

        return $this;
    }

    public function withMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function build(): Graph
    {
        $container = Container::getInstance();
        $instances = [];
        foreach ($this->nodes as $name => $class) {
            $node = $container->make($class);
            $instances[$name] = $node;
        }
        $graph = new Graph(
            $this->name,
            $this->description,
            $this->entryPoint ?? '',
            $this->exitPoints,
            $instances,
            $this->edges,
            $this->metadata,
            $this->joins,
        );
        $graph->validate();

        return $graph;
    }

    public function addJoin(array $sources, string $target): self
    {
        $key = implode('|', array_values(array_unique(array_map('strval', array_values(array_unique($sources))))));
        $sourcesSorted = $sources;
        sort($sourcesSorted);
        $key = implode('|', $sourcesSorted);
        $this->joins[$key] = $target;

        return $this;
    }
}
