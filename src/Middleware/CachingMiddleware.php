<?php

namespace Shahzad\AgenticGraph\Middleware;

use Illuminate\Support\Facades\Cache;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class CachingMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        // Include node class file modification time in cache key
        $reflection = new \ReflectionClass($node);
        $nodeFile = $reflection->getFileName();
        $nodeVersion = $nodeFile ? filemtime($nodeFile) : 0;

        $key = 'agenticgraph:node:' .
               $node->getName() . ':' .
               $nodeVersion . ':' .
               md5(json_encode($state->toArray()));

        $ttl = config('agenticgraph.middleware.cache_ttl', 3600);

        // Check if node should be cached (could be a property on node)
        if (method_exists($node, 'isCacheable') && !$node->isCacheable()) {
            return $next($state);
        }

        return Cache::remember($key, $ttl, function () use ($state, $next) {
            return $next($state);
        });
    }
}
