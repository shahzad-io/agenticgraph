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

    /** @var array<int, string> */
    protected array $exitPoints = [];

    /** @var array<string, string> */
    protected array $nodes = [];

    /** @var array<int, GraphEdge> */
    protected array $edges = [];

    /** @var array<string, mixed> */
    protected array $metadata = [];

    /** @var array<string, string> */
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
        $this->validateNodeName($name);
        $this->validateNodeClass($class);

        if (isset($this->nodes[$name])) {
            throw new InvalidGraphException("Node '{$name}' already exists in graph");
        }

        $this->nodes[$name] = $class;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function addEdge(string $source, string $target, array $metadata = []): self
    {
        $this->validateNodeReference($source, 'source');
        $this->validateNodeReference($target, 'target');

        // Check for duplicate edges
        foreach ($this->edges as $edge) {
            if ($edge->getSource() === $source && $edge->getTarget() === $target) {
                throw new InvalidGraphException(
                    "Duplicate edge from '{$source}' to '{$target}'"
                );
            }
        }

        $this->edges[] = new GraphEdge($source, $target, null, $metadata);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function addConditionalEdge(string $source, RouterInterface|string $router, array $metadata = []): self
    {
        $this->validateNodeReference($source, 'source');

        if (is_string($router)) {
            if (! class_exists($router)) {
                throw new InvalidGraphException(
                    "Router class does not exist: {$router}"
                );
            }
            if (! in_array(RouterInterface::class, class_implements($router) ?: [], true)) {
                throw new InvalidGraphException(
                    "Router class must implement RouterInterface: {$router}"
                );
            }
        }

        $instance = is_string($router) ? Container::getInstance()->make($router) : $router;
        $this->edges[] = new GraphEdge($source, [], $instance, $metadata);

        return $this;
    }

    public function setEntryPoint(string $name): self
    {
        $this->validateNodeReference($name, 'entry point');
        $this->entryPoint = $name;

        return $this;
    }

    public function addExitPoint(string $name): self
    {
        $this->validateNodeReference($name, 'exit point');

        if (in_array($name, $this->exitPoints, true)) {
            throw new InvalidGraphException(
                "Exit point '{$name}' already added"
            );
        }

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
        $this->validateBeforeBuild();

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

    /**
     * @param  array<int, string>  $sources
     */
    public function addJoin(array $sources, string $target): self
    {
        $this->validateNodeReference($target, 'join target');

        if (count($sources) < 2) {
            throw new InvalidGraphException(
                'Join requires at least 2 source nodes'
            );
        }

        $uniqueSources = array_unique($sources);
        if (count($uniqueSources) !== count($sources)) {
            throw new InvalidGraphException(
                'Join sources must be unique'
            );
        }

        foreach ($sources as $source) {
            $this->validateNodeReference($source, 'join source');
        }

        $sourcesSorted = $sources;
        sort($sourcesSorted);
        $key = implode('|', $sourcesSorted);

        if (isset($this->joins[$key])) {
            throw new InvalidGraphException(
                "Join for sources '{$key}' already exists"
            );
        }

        $this->joins[$key] = $target;

        return $this;
    }

    /**
     * Validate node name format.
     */
    private function validateNodeName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidGraphException('Node name cannot be empty');
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new InvalidGraphException(
                "Invalid node name '{$name}'. Use only alphanumeric characters, underscores, and hyphens."
            );
        }
    }

    /**
     * Validate node class exists and implements correct interface.
     */
    private function validateNodeClass(string $class): void
    {
        if (! class_exists($class)) {
            throw new InvalidGraphException("Node class does not exist: {$class}");
        }

        $requiredInterface = \Shahzad\AgenticGraph\Contracts\NodeInterface::class;
        if (! in_array($requiredInterface, class_implements($class) ?: [], true)) {
            throw new InvalidGraphException(
                "Node class must implement NodeInterface: {$class}"
            );
        }
    }

    /**
     * Validate that a node reference exists in the builder.
     */
    private function validateNodeReference(string $name, string $context): void
    {
        if (! isset($this->nodes[$name])) {
            throw new InvalidGraphException(
                "{$context} references non-existent node: '{$name}'"
            );
        }
    }

    /**
     * Validate builder state before building the graph.
     */
    private function validateBeforeBuild(): void
    {
        // Validate name
        if (trim($this->name) === '') {
            throw new InvalidGraphException('Graph name cannot be empty');
        }

        // Validate we have nodes
        if (empty($this->nodes)) {
            throw new InvalidGraphException('Graph must have at least one node');
        }

        // Validate entry point is set
        if ($this->entryPoint === null) {
            throw new InvalidGraphException('Graph must have an entry point. Call setEntryPoint().');
        }

        // Validate entry point exists in nodes
        if (! isset($this->nodes[$this->entryPoint])) {
            throw new InvalidGraphException(
                "Entry point '{$this->entryPoint}' does not exist in nodes"
            );
        }

        // Validate exit points
        if (empty($this->exitPoints)) {
            throw new InvalidGraphException('Graph must have at least one exit point');
        }

        foreach ($this->exitPoints as $exitPoint) {
            if (! isset($this->nodes[$exitPoint])) {
                throw new InvalidGraphException(
                    "Exit point '{$exitPoint}' does not exist in nodes"
                );
            }
        }

        // Validate all edges reference existing nodes
        foreach ($this->edges as $edge) {
            $source = $edge->getSource();
            if (! isset($this->nodes[$source])) {
                throw new InvalidGraphException(
                    "Edge source '{$source}' does not exist in nodes"
                );
            }

            $target = $edge->getTarget();
            if (is_string($target) && ! isset($this->nodes[$target])) {
                throw new InvalidGraphException(
                    "Edge target '{$target}' does not exist in nodes"
                );
            }
        }
    }
}
