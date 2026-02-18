<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Store;

use Illuminate\Database\DatabaseManager;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;

final class RetentionPolicy
{
    private DatabaseManager $db;

    private string $table;

    private ?string $connection;

    private int $maxAgeHours;

    private int $maxEventsPerStream;

    public function __construct(?DatabaseManager $db = null)
    {
        $this->db = $db ?? app(DatabaseManager::class);
        $this->table = (string) config('agenticgraph.streaming.store.database.table', 'stream_events');
        $this->connection = config('agenticgraph.streaming.store.database.connection');
        $this->maxAgeHours = (int) config('agenticgraph.streaming.retention.max_age_hours', 24);
        $this->maxEventsPerStream = (int) config('agenticgraph.streaming.retention.max_events_per_stream', 10000);
    }

    public function enforce(StreamStoreInterface $store, StreamEvent $event): void
    {
        if ($this->maxEventsPerStream > 0) {
            $beforeSeq = $event->seq() - $this->maxEventsPerStream + 1;
            if ($beforeSeq > 1) {
                $store->truncate($event->streamId(), $beforeSeq);
            }
        }

        if ($this->maxAgeHours > 0 && $store instanceof DatabaseStreamStore) {
            $this->cleanupDatabase($event->streamId());
        }
    }

    public function cleanupDatabase(?string $streamId = null): int
    {
        if ($this->maxAgeHours <= 0) {
            return 0;
        }

        $threshold = now()->subHours($this->maxAgeHours)->format('Y-m-d H:i:s');
        $query = $this->query()->where('occurred_at', '<', $threshold);
        if ($streamId !== null) {
            $query->where('stream_id', $streamId);
        }

        return $query->delete();
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        if ($this->connection) {
            return $this->db->connection($this->connection)->table($this->table);
        }

        return $this->db->table($this->table);
    }
}
