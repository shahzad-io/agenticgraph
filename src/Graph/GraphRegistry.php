<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Graph;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache-based graph registry for Octane-safe graph storage.
 * Uses Laravel cache to store graphs instead of static properties.
 */
class GraphRegistry
{
    private const CACHE_PREFIX = 'agenticgraph:graphs:';

    private const INDEX_KEY = 'agenticgraph:graphs:index';

    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly CacheRepository $cache
    ) {}

    public function register(string $name, Graph $graph): void
    {
        $key = self::CACHE_PREFIX.$name;
        $this->cache->put($key, $graph, self::CACHE_TTL);

        // Update index
        $index = $this->cache->get(self::INDEX_KEY, []);
        if (! in_array($name, $index, true)) {
            $index[] = $name;
            $this->cache->put(self::INDEX_KEY, $index, self::CACHE_TTL);
        }
    }

    public function get(string $name): ?Graph
    {
        $key = self::CACHE_PREFIX.$name;

        return $this->cache->get($key);
    }

    /**
     * @return array<string, Graph>
     */
    public function all(): array
    {
        $index = $this->cache->get(self::INDEX_KEY, []);
        $graphs = [];

        foreach ($index as $name) {
            $graph = $this->get($name);
            if ($graph !== null) {
                $graphs[$name] = $graph;
            }
        }

        return $graphs;
    }

    public function unregister(string $name): void
    {
        $key = self::CACHE_PREFIX.$name;
        $this->cache->forget($key);

        // Update index
        $index = $this->cache->get(self::INDEX_KEY, []);
        $index = array_filter($index, fn ($n) => $n !== $name);
        $this->cache->put(self::INDEX_KEY, array_values($index), self::CACHE_TTL);
    }

    public function clear(): void
    {
        $index = $this->cache->get(self::INDEX_KEY, []);

        foreach ($index as $name) {
            $key = self::CACHE_PREFIX.$name;
            $this->cache->forget($key);
        }

        $this->cache->forget(self::INDEX_KEY);
    }

    public function count(): int
    {
        $index = $this->cache->get(self::INDEX_KEY, []);

        return count($index);
    }

    public function has(string $name): bool
    {
        return $this->cache->has(self::CACHE_PREFIX.$name);
    }
}
