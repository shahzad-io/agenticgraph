<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Middleware;

use Illuminate\Support\Facades\Cache;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class CachingMiddleware
{
    /** @var array<string, int> Cache of class => file mtime */
    private static array $nodeVersionCache = [];

    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        $nodeClass = $node::class;

        // Cache node version lookup to avoid repeated reflection
        if (! isset(self::$nodeVersionCache[$nodeClass])) {
            $reflection = new \ReflectionClass($node);
            $nodeFile = $reflection->getFileName();
            self::$nodeVersionCache[$nodeClass] = $nodeFile ? filemtime($nodeFile) : 0;
        }

        $nodeVersion = self::$nodeVersionCache[$nodeClass];

        // Use faster hash for state (xxh3 is significantly faster than md5+json_encode)
        $stateHash = $this->hashState($state);

        $key = sprintf(
            'agenticgraph:node:%s:%d:%s',
            $node->getName(),
            $nodeVersion,
            $stateHash
        );

        $ttl = config('agenticgraph.middleware.cache_ttl', 3600);

        // Check if node should be cached (could be a property on node)
        if (method_exists($node, 'isCacheable') && ! $node->isCacheable()) {
            return $next($state);
        }

        return Cache::remember($key, $ttl, function () use ($state, $next) {
            return $next($state);
        });
    }

    /**
     * Hash state using fast xxHash algorithm.
     * Falls back to md5+serialize if xxh3 is not available.
     */
    private function hashState(StateContract $state): string
    {
        $data = $state->toArray();

        // Use xxHash3 for fast hashing (available in PHP 8.1+)
        if (function_exists('hash') && in_array('xxh3', hash_algos(), true)) {
            return hash('xxh3', serialize($data));
        }

        // Fallback to md5+serialize for older PHP versions
        return md5(serialize($data));
    }

    /**
     * Clear version cache (useful in tests or after deployment).
     */
    public static function clearVersionCache(): void
    {
        self::$nodeVersionCache = [];
    }
}
