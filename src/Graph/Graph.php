<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Graph;

use Shahzad\AgenticGraph\Contracts\EdgeInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;

class Graph
{
    public readonly string $name;

    public readonly string $description;

    public readonly string $entryPoint;

    /** @var array<int, string> */
    public readonly array $exitPoints;

    /** @var array<string, NodeInterface> */
    public readonly array $nodes;

    /** @var array<int, EdgeInterface> */
    public readonly array $edges;

    /** @var array<string, mixed> */
    public readonly array $metadata;

    /** @var array<string, string> Key is "node1|node2", value is join target */
    public readonly array $joins;

    /**
     * @param  array<int, string>  $exitPoints
     * @param  array<string, NodeInterface>  $nodes
     * @param  array<int, EdgeInterface>  $edges
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>  $joins
     */
    public function __construct(string $name, string $description, string $entryPoint, array $exitPoints, array $nodes, array $edges, array $metadata = [], array $joins = [])
    {
        $this->name = $name;
        $this->description = $description;
        $this->entryPoint = $entryPoint;
        $this->exitPoints = $exitPoints;
        $this->nodes = $nodes;
        $this->edges = $edges;
        $this->metadata = $metadata;
        $this->joins = $joins;
    }

    public function getNode(string $name): NodeInterface
    {
        return $this->nodes[$name];
    }

    /**
     * @return array<int, EdgeInterface>
     */
    public function getEdgesFrom(string $nodeName): array
    {
        return array_values(array_filter($this->edges, fn (EdgeInterface $e) => $e->getSource() === $nodeName));
    }

    public function hasNode(string $name): bool
    {
        return array_key_exists($name, $this->nodes);
    }

    public function validate(): bool
    {
        // 1. Check all edge sources and targets exist
        foreach ($this->edges as $edge) {
            $source = $edge->getSource();
            if (! array_key_exists($source, $this->nodes)) {
                throw new InvalidGraphException("Edge source node does not exist: {$source}");
            }

            $target = $edge->getTarget();
            $targets = is_array($target) ? $target : [$target];
            foreach ($targets as $t) {
                if (! array_key_exists($t, $this->nodes)) {
                    throw new InvalidGraphException("Edge target node does not exist: {$t}");
                }
            }
        }

        // 2. Check entry point exists
        if (! array_key_exists($this->entryPoint, $this->nodes)) {
            throw new InvalidGraphException("Entry point does not exist: {$this->entryPoint}");
        }

        // 3. Check exit points exist
        if (empty($this->exitPoints)) {
            throw new InvalidGraphException('Graph must have at least one exit point');
        }
        foreach ($this->exitPoints as $exit) {
            if (! array_key_exists($exit, $this->nodes)) {
                throw new InvalidGraphException("Exit point does not exist: {$exit}");
            }
        }

        // 4. Check for orphaned nodes (unreachable from entry point)
        $reachable = $this->findReachableNodes($this->entryPoint);
        $orphaned = array_diff(array_keys($this->nodes), $reachable);
        if (! empty($orphaned)) {
            throw new InvalidGraphException(
                'Orphaned nodes (unreachable from entry point): '.implode(', ', $orphaned)
            );
        }

        // 5. Check that at least one exit point is reachable
        $reachableExits = array_intersect($this->exitPoints, $reachable);
        if (empty($reachableExits)) {
            throw new InvalidGraphException(
                'No exit points are reachable from entry point. '.
                "Entry: {$this->entryPoint}, Exits: ".implode(', ', $this->exitPoints)
            );
        }

        // 6. Warn about nodes with no outgoing edges (dead ends) that aren't exit points
        foreach ($this->nodes as $nodeName => $node) {
            $outgoing = $this->getEdgesFrom($nodeName);
            if (empty($outgoing) && ! in_array($nodeName, $this->exitPoints, true)) {
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    \Illuminate\Support\Facades\Log::warning("Node '{$nodeName}' has no outgoing edges and is not an exit point");
                }
            }
        }

        // 7. Optional cycle detection
        $cycleMode = config('agenticgraph.execution.loop.cycle_detection', 'silent');

        if ($cycleMode !== 'silent') {
            $cycles = $this->detectCycles();

            if (! empty($cycles)) {
                $cycleList = array_map(fn ($c) => $c['hash'], $cycles);
                $message = 'Cycles detected in graph: '.implode(', ', $cycleList);

                if ($cycleMode === 'error') {
                    throw new InvalidGraphException($message);
                } elseif ($cycleMode === 'warn' && (bool) config('agenticgraph.logging.enabled', true)) {
                    \Illuminate\Support\Facades\Log::warning($message, [
                        'graph' => $this->name,
                        'cycles' => $cycles,
                    ]);
                }
            }
        }

        return true;
    }

    /**
     * Find all nodes reachable from a starting node using BFS
     *
     * @return array<int, string>
     */
    private function findReachableNodes(string $start): array
    {
        $reachable = [];
        $queue = [$start];
        $visited = [];

        while (! empty($queue)) {
            $current = array_shift($queue);

            if (in_array($current, $visited, true)) {
                continue;
            }

            $visited[] = $current;
            $reachable[] = $current;

            $edges = $this->getEdgesFrom($current);
            foreach ($edges as $edge) {
                // Check if edge has a condition (router)
                $condition = $this->resolveRouter($edge->getCondition());
                if ($condition) {
                    // For conditional edges, add all available routes
                    foreach ($condition->getAvailableRoutes() as $route) {
                        if (! in_array($route, $visited, true) && ! in_array($route, $queue, true)) {
                            $queue[] = $route;
                        }
                    }
                } else {
                    // For simple edges, add the target(s)
                    $target = $edge->getTarget();
                    $targets = is_array($target) ? $target : [$target];

                    foreach ($targets as $t) {
                        if (! in_array($t, $visited, true) && ! in_array($t, $queue, true)) {
                            $queue[] = $t;
                        }
                    }
                }
            }

            // Also check joins - if current node is part of a join source, add the join target
            foreach ($this->joins as $sources => $target) {
                $sourceNodes = explode('|', $sources);
                if (in_array($current, $sourceNodes, true)) {
                    if (! in_array($target, $visited, true) && ! in_array($target, $queue, true)) {
                        $queue[] = $target;
                    }
                }
            }
        }

        return $reachable;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'entry' => $this->entryPoint,
            'exits' => $this->exitPoints,
            'nodes' => array_keys($this->nodes),
            'edges' => array_map(fn (EdgeInterface $e) => [
                'source' => $e->getSource(),
                'target' => $e->getTarget(),
                'metadata' => $e->getMetadata(),
            ], $this->edges),
            'metadata' => $this->metadata,
            'joins' => $this->joins,
        ];
    }

    public function visualize(): string
    {
        $lines = ['graph TD'];
        foreach ($this->edges as $edge) {
            $source = $edge->getSource();
            $target = $edge->getTarget();
            $targets = is_array($target) ? $target : [$target];
            foreach ($targets as $t) {
                $lines[] = "    {$source} --> {$t}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Detect cycles in the graph using DFS with recursion stack.
     *
     * @return array<int, array{path: array<int, string>, hash: string}>
     */
    public function detectCycles(): array
    {
        $cycles = [];
        $visited = [];
        $recursionStack = [];
        $pathStack = [];

        foreach (array_keys($this->nodes) as $node) {
            if (! in_array($node, $visited, true)) {
                $this->dfsDetectCycles(
                    $node,
                    $visited,
                    $recursionStack,
                    $pathStack,
                    $cycles
                );
            }
        }

        return $cycles;
    }

    /**
     * DFS helper for cycle detection.
     *
     * @param  array<int, string>  $visited
     * @param  array<int, string>  $recursionStack
     * @param  array<int, string>  $pathStack
     * @param  array<int, array{path: array<int, string>, hash: string}>  $cycles
     */
    private function dfsDetectCycles(
        string $node,
        array &$visited,
        array &$recursionStack,
        array &$pathStack,
        array &$cycles
    ): void {
        $visited[] = $node;
        $recursionStack[] = $node;
        $pathStack[] = $node;

        $edges = $this->getEdgesFrom($node);
        foreach ($edges as $edge) {
            $condition = $this->resolveRouter($edge->getCondition());
            $targets = [];

            if ($condition) {
                $targets = $condition->getAvailableRoutes();
            } else {
                $target = $edge->getTarget();
                $targets = is_array($target) ? $target : [$target];
            }

            foreach ($targets as $neighbor) {
                if (! in_array($neighbor, $visited, true)) {
                    $this->dfsDetectCycles(
                        $neighbor,
                        $visited,
                        $recursionStack,
                        $pathStack,
                        $cycles
                    );
                } elseif (in_array($neighbor, $recursionStack, true)) {
                    // Cycle detected
                    $cycleStartIndex = array_search($neighbor, $pathStack, true);
                    $cyclePath = array_slice($pathStack, $cycleStartIndex);
                    $cyclePath[] = $neighbor; // Close the cycle
                    $cycleHash = implode('→', $cyclePath);

                    $cycles[] = [
                        'path' => $cyclePath,
                        'hash' => $cycleHash,
                    ];
                }
            }
        }

        // Backtrack
        array_pop($recursionStack);
        array_pop($pathStack);
    }

    private function resolveRouter(RouterInterface|string|null $condition): ?RouterInterface
    {
        if ($condition === null) {
            return null;
        }

        if ($condition instanceof RouterInterface) {
            return $condition;
        }

        if (! class_exists($condition)) {
            throw new InvalidGraphException("Router class does not exist: {$condition}");
        }

        $router = app()->make($condition);
        if (! $router instanceof RouterInterface) {
            throw new InvalidGraphException("Router class must implement RouterInterface: {$condition}");
        }

        return $router;
    }
}
