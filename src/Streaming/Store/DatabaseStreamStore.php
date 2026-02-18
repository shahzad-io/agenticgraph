<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Store;

use Illuminate\Database\DatabaseManager;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

final class DatabaseStreamStore implements StreamStoreInterface
{
    private DatabaseManager $db;

    private string $table;

    private ?string $connection;

    public function __construct(?DatabaseManager $db = null)
    {
        $this->db = $db ?? app(DatabaseManager::class);
        $this->table = (string) config('agenticgraph.streaming.store.database.table', 'stream_events');
        $this->connection = config('agenticgraph.streaming.store.database.connection');
    }

    public function append(StreamEvent $event): void
    {
        $payload = [
            'stream_id' => $event->streamId(),
            'execution_id' => $event->executionId(),
            'seq' => $event->seq(),
            'node_seq' => $event->nodeSeq(),
            'node_name' => $event->nodeName(),
            'span_id' => $event->spanId(),
            'parent_span_id' => $event->parentSpanId(),
            'type' => $event->type(),
            'payload' => json_encode($event->payload(), JSON_UNESCAPED_SLASHES),
            'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s.u'),
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ];
        $this->query()->insert($payload);
    }

    public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator
    {
        $rows = $this->query()
            ->where('stream_id', $streamId)
            ->where('seq', '>=', $fromSeq)
            ->orderBy('seq')
            ->limit($limit)
            ->get();

        $events = [];
        foreach ($rows as $row) {
            $events[] = new StreamEventImpl(
                $row->stream_id,
                $row->execution_id,
                $row->node_name,
                $row->span_id,
                $row->parent_span_id,
                $row->type,
                json_decode($row->payload, true) ?? [],
                (int) $row->seq,
                $row->node_seq === null ? null : (int) $row->node_seq,
                new \DateTimeImmutable((string) $row->occurred_at)
            );
        }
        yield from $events;
    }

    public function lastSeq(string $streamId): ?int
    {
        $row = $this->query()
            ->where('stream_id', $streamId)
            ->orderByDesc('seq')
            ->limit(1)
            ->first();

        return $row ? (int) $row->seq : null;
    }

    public function truncate(string $streamId, int $beforeSeq): void
    {
        $this->query()
            ->where('stream_id', $streamId)
            ->where('seq', '<', $beforeSeq)
            ->delete();
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        if ($this->connection) {
            return $this->db->connection($this->connection)->table($this->table);
        }

        return $this->db->table($this->table);
    }
}
