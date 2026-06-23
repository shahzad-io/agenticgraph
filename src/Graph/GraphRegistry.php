<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Graph;

use Illuminate\Support\Facades\Log;

/**
 * In-memory graph registry for Octane-safe graph storage.
 *
 * Graphs are stored on the registry singleton instance (per-worker), never
 * serialized to Laravel cache. Built graphs hold live container-wired nodes
 * and routers that cannot round-trip through serialize/unserialize.
 */
class GraphRegistry
{
    /** @var array<string, Graph> */
    private array $graphs = [];

    public function register(string $name, Graph $graph): void
    {
        $this->graphs[$name] = $graph;
    }

    public function get(string $name): ?Graph
    {
        $graph = $this->graphs[$name] ?? null;

        return $graph instanceof Graph ? $graph : null;
    }

    /**
     * @return array<string, Graph>
     */
    public function all(): array
    {
        return $this->graphs;
    }

    public function unregister(string $name): void
    {
        unset($this->graphs[$name]);
    }

    public function clear(): void
    {
        $this->graphs = [];
    }

    public function count(): int
    {
        return count($this->graphs);
    }

    public function has(string $name): bool
    {
        return isset($this->graphs[$name]);
    }

    /**
     * Get graph from the in-memory registry, or auto-build from config if missing.
     *
     * @param  string  $name  The graph name
     * @return Graph|null The graph instance, or null if not found in registry or config
     */
    public function getOrBuild(string $name): ?Graph
    {
        $graph = $this->get($name);
        if ($graph instanceof Graph) {
            return $graph;
        }

        $graphClass = config("agenticgraph.graphs.{$name}");
        if ($graphClass === null || ! class_exists($graphClass)) {
            return null;
        }

        if (! method_exists($graphClass, 'build')) {
            return null;
        }

        try {
            $graph = $graphClass::build();

            if (! $graph instanceof Graph) {
                return null;
            }

            $this->register($name, $graph);

            return $graph;
        } catch (\Throwable $e) {
            if (config('agenticgraph.logging.enabled', true)) {
                Log::error('Failed to auto-build graph from config', [
                    'graph_name' => $name,
                    'graph_class' => $graphClass,
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        }
    }
}
