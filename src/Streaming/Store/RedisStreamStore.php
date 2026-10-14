<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming\Store;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

final class RedisStreamStore implements StreamStoreInterface
{
    private string $prefix = 'agenticgraph:stream:';

    private Connection $connection;

    private int $ttl;

    private int $maxLen;

    public function __construct()
    {
        $connectionName = (string) config('agenticgraph.streaming.store.redis.connection', 'default');
        $this->connection = Redis::connection($connectionName);
        $defaultTtl = (int) config('agenticgraph.streaming.store.default_ttl_seconds', 0);
        $this->ttl = (int) config('agenticgraph.streaming.store.redis.ttl', $defaultTtl > 0 ? $defaultTtl : 86400);
        $this->maxLen = (int) config('agenticgraph.streaming.store.redis.max_len', 10000);
    }

    public function append(StreamEvent $event): void
    {
        $key = $this->prefix.$event->streamId();
        $id = $event->seq().'-0';
        $fields = [
            'stream_id' => $event->streamId(),
            'execution_id' => $event->executionId(),
            'node_name' => $event->nodeName(),
            'span_id' => $event->spanId(),
            'parent_span_id' => $event->parentSpanId(),
            'type' => $event->type(),
            'seq' => $event->seq(),
            'node_seq' => $event->nodeSeq(),
            'occurred_at' => $event->occurredAt()->format('c'),
            'payload' => json_encode($event->payload(), JSON_UNESCAPED_SLASHES),
        ];
        // XADD would store null as ""; leave null fields out so they read back as null.
        $fields = array_filter($fields, fn ($value) => $value !== null);
        if ($this->maxLen > 0) {
            $this->connection->command('xadd', [$key, $id, $fields, $this->maxLen]);
        } else {
            $this->connection->command('xadd', [$key, $id, $fields]);
        }
        if ($this->ttl > 0) {
            $this->connection->expire($key, $this->ttl);
        }
    }

    public function readFrom(string $streamId, int $fromSeq, int $limit = 200): \Generator
    {
        $key = $this->prefix.$streamId;
        $startId = ($fromSeq <= 0 ? '0-0' : $fromSeq.'-0');
        $useBlocking = (bool) config('agenticgraph.streaming.sse.use_blocking_reads', false);
        if ($useBlocking) {
            $blockMs = (int) config('agenticgraph.streaming.sse.poll_interval_ms', 250);
            $xreadStart = $fromSeq <= 1 ? '0-0' : ($fromSeq - 1).'-0';
            $entries = $this->connection->xread([$key => $xreadStart], $limit, $blockMs);
            yield from $this->hydrateEntries($streamId, $entries, true);

            return;
        }

        $entries = $this->connection->command('xrange', [$key, $startId, '+', $limit]);
        yield from $this->hydrateEntries($streamId, $entries, false);
    }

    public function lastSeq(string $streamId): ?int
    {
        $key = $this->prefix.$streamId;
        $entries = $this->connection->command('xrevrange', [$key, '+', '-', 1]);
        if (! $entries) {
            return null;
        }
        $firstKey = array_key_first($entries);
        if (is_string($firstKey)) {
            $parts = explode('-', $firstKey);

            return (int) $parts[0];
        }
        $firstEntry = $entries[0] ?? null;
        if (! is_array($firstEntry) || ! isset($firstEntry[0])) {
            return null;
        }
        $parts = explode('-', (string) $firstEntry[0]);

        return (int) $parts[0];
    }

    public function truncate(string $streamId, int $beforeSeq): void
    {
        if ($beforeSeq <= 0) {
            return;
        }
        $key = $this->prefix.$streamId;
        $minId = $beforeSeq.'-0';
        $removed = $this->connection->command('xtrim', [$key, 'MINID', $minId]);
        if ($removed === 0 || $removed === false) {
            $maxId = ($beforeSeq - 1).'-0';
            $entries = $this->connection->command('xrange', [$key, '0-0', $maxId]);
            if ($entries) {
                $ids = [];
                foreach ($entries as $entryId => $entryData) {
                    if (is_string($entryId)) {
                        $ids[] = $entryId;

                        continue;
                    }
                    if (is_array($entryData) && isset($entryData[0]) && is_string($entryData[0])) {
                        $ids[] = $entryData[0];
                    }
                }
                if ($ids !== []) {
                    $this->connection->command('xdel', [$key, $ids]);
                }
            }
        }
    }

    private function hydrate(string $streamId, string $id, mixed $fields): StreamEventImpl
    {
        $data = $this->normalizeFields($fields);
        $seq = isset($data['seq']) ? (int) $data['seq'] : (int) explode('-', $id)[0];
        // Absent means null. "" is how entries written before null-skipping stored null.
        $nullable = fn (string $field): ?string => isset($data[$field]) && $data[$field] !== '' ? (string) $data[$field] : null;
        $nodeSeq = $nullable('node_seq');
        $nodeSeq = $nodeSeq === null ? null : (int) $nodeSeq;
        $payload = json_decode((string) ($data['payload'] ?? '[]'), true) ?? [];
        $occurredAt = new \DateTimeImmutable((string) ($data['occurred_at'] ?? 'now'));

        return new StreamEventImpl(
            $streamId,
            (string) ($data['execution_id'] ?? ''),
            $nullable('node_name'),
            $nullable('span_id'),
            $nullable('parent_span_id'),
            (string) ($data['type'] ?? ''),
            $payload,
            $seq,
            $nodeSeq,
            $occurredAt
        );
    }

    private function hydrateEntries(string $streamId, mixed $entries, bool $isXread): \Generator
    {
        if (! $entries) {
            return;
        }
        if ($isXread) {
            foreach ($entries as $streamKey => $records) {
                if (! is_array($records)) {
                    continue;
                }
                foreach ($records as $recordId => $record) {
                    if (is_string($recordId) && is_array($record)) {
                        yield $this->hydrate($streamId, $recordId, $record);

                        continue;
                    }
                    if (! is_array($record) || count($record) < 2) {
                        continue;
                    }
                    $id = $record[0];
                    $fields = $record[1];
                    if (! is_string($id)) {
                        continue;
                    }
                    yield $this->hydrate($streamId, $id, $fields);
                }
            }

            return;
        }

        foreach ($entries as $entryId => $entryData) {
            if (is_string($entryId) && is_array($entryData)) {
                yield $this->hydrate($streamId, $entryId, $entryData);

                continue;
            }
            if (! is_array($entryData) || count($entryData) < 2) {
                continue;
            }
            $id = $entryData[0];
            $fields = $entryData[1];
            if (! is_string($id)) {
                continue;
            }
            yield $this->hydrate($streamId, $id, $fields);
        }
    }

    private function normalizeFields(mixed $fields): mixed
    {
        if (is_array($fields)) {
            $isAssoc = array_keys($fields) !== range(0, count($fields) - 1);
            if ($isAssoc) {
                return $fields;
            }
            $normalized = [];
            for ($i = 0; $i < count($fields); $i += 2) {
                if (! isset($fields[$i + 1])) {
                    continue;
                }
                $normalized[(string) $fields[$i]] = $fields[$i + 1];
            }

            return $normalized;
        }

        return [];
    }
}
