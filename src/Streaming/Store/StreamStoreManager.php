<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Store;

use Illuminate\Contracts\Container\Container;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;

final class StreamStoreManager
{
    public function __construct(private readonly Container $container) {}

    public function driver(): string
    {
        return (string) config('agenticgraph.streaming.store.driver', 'redis');
    }

    public function store(): StreamStoreInterface
    {
        return match ($this->driver()) {
            'database' => $this->container->make(DatabaseStreamStore::class),
            default => $this->container->make(RedisStreamStore::class),
        };
    }
}
