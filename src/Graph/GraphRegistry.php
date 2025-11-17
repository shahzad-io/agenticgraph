<?php

namespace Shahzad\AgenticGraph\Graph;

class GraphRegistry
{
    protected static array $graphs = [];
    protected static int $maxGraphs = 100; // Prevent unbounded growth

    public static function register(string $name, Graph $graph): void
    {
        // Prevent memory leaks by limiting registry size
        if (count(self::$graphs) >= self::$maxGraphs) {
            \Illuminate\Support\Facades\Log::warning('GraphRegistry is full, clearing old entries', [
                'count' => count(self::$graphs),
                'max' => self::$maxGraphs,
            ]);
            self::clear();
        }

        self::$graphs[$name] = $graph;
    }

    public static function get(string $name): ?Graph
    {
        return self::$graphs[$name] ?? null;
    }

    public static function all(): array
    {
        return self::$graphs;
    }

    public static function unregister(string $name): void
    {
        unset(self::$graphs[$name]);
    }

    public static function clear(): void
    {
        self::$graphs = [];
    }

    public static function count(): int
    {
        return count(self::$graphs);
    }

    public static function has(string $name): bool
    {
        return isset(self::$graphs[$name]);
    }
}
