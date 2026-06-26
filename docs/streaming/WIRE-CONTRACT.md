# Stream Wire Contract v1.0

**Status:** FROZEN (public API). Changing frozen fields, event type strings, or delivery semantics is a **breaking change** requiring a major version bump.

**Source of truth:** `Shahzad\AgenticGraph\Streaming\StreamEventEnvelope` (`src/Streaming/StreamEventEnvelope.php`).

This document is the canonical type spec for NPM streaming clients and AgenticPulse. Field names are **verbatim** — mirror them exactly in TypeScript.

---

## Critical: `type` is external to the JSON body

The event `type` is **not** a key in the JSON `data` payload. It is carried separately:

| Transport | Where `type` lives |
|-----------|-------------------|
| **SSE** | `event: {type}` line |
| **Broadcast** | Laravel broadcaster event name (second argument to `broadcast()`) |

**Client model:**

```typescript
type StreamWireBody = {
  stream_id: string;
  execution_id: string;
  node_name: string | null;
  span_id: string | null;
  parent_span_id: string | null;
  seq: number;
  node_seq: number | null;
  occurred_at: string; // ISO-8601
  payload: unknown;
};

// Consumer receives: { eventType: string } & StreamWireBody
```

Getting this wrong (expecting `type` inside `data`) is the most common client integration mistake.

---

## Wire body (9 frozen fields)

Emitted by `StreamEventEnvelope::toWireArray()` as snake_case JSON. Keys are frozen in `StreamEventEnvelope::FROZEN_WIRE_BODY_KEYS`.

| Field | Type | Always present | Meaning |
|-------|------|----------------|---------|
| `stream_id` | `string` | yes | Stream session ID; stable across resume on the same execution |
| `execution_id` | `string` | yes | Graph execution ID |
| `node_name` | `string \| null` | key always present | Emitting node; `null` for graph-level events |
| `span_id` | `string \| null` | key always present | Trace span (observability; optional for basic UI) |
| `parent_span_id` | `string \| null` | key always present | Parent span for trace tree (observability) |
| `seq` | `integer` | yes | Monotonic per-stream global sequence (starts at 1 per session) |
| `node_seq` | `integer \| null` | key always present | Per-node sub-sequence within a single node execution; see semantics below |
| `occurred_at` | `string` | yes | ISO-8601 timestamp (`DateTimeImmutable::format('c')`) |
| `payload` | `mixed` | yes | Event-specific body; shape depends on `type` |

### `node_seq` semantics (frozen)

- **Populated** for events within a node execution: `node_start`, content events (`text_delta`, author `streamEvent()`, Prism adapter events), `node_complete`, `node_error`.
- Forms one **coherent, gapless, monotonic** per-node sequence (e.g. `node_start=1`, `text_delta=2,3,4`, `node_complete=5`).
- **Resets** at each new node execution; independent per node name.
- **`null`** for graph-level events: `graph_start`, `graph_complete`, `graph_failed`, `stream_resume`, `node_interrupt`, `execution_terminal`.

---

## Event type taxonomy

Clients should model a **discriminated union** on external `eventType` + `payload`.

### Closed framework / lifecycle set

Enumeratable constants in `StreamLifecycleEventTypes` and `StreamEventEnvelope`. Wire `type` string is frozen.

| `type` | `node_name` | `node_seq` | Payload (before lifecycle `stream` injection) |
|--------|-------------|------------|-----------------------------------------------|
| `graph_start` | `null` | `null` | `{ graph_name: string }` |
| `graph_complete` | `null` | `null` | `{ duration_ms: number }` |
| `graph_failed` | `null` | `null` | `{ error_type: string, message: string }` |
| `node_start` | node | `1` | `{ node_name: string }` |
| `node_complete` | node | increments | `{ node_name: string, duration_ms: number }` + optional state capture |
| `node_error` | node | increments | `{ node_name: string, error_type: string, message: string }` |
| `node_interrupt` | node | `null` | `{ node_name: string, reason: string, checkpoint_id: string }` |
| `stream_resume` | resume node | `null` | `{ from_seq, resumed_at_seq, gap_events, checkpoint_id }` |
| `execution_terminal` | `null` | `null` | `{ status: 'completed'\|'interrupted'\|'failed', final_state: object, meta?: object }` |
| `stream_close` | — | — | **Not a 9-field body** — see [Terminals](#terminals-two-vocabularies) |
| `stream_error` | varies | varies | See [stream_error](#stream_error) |

#### Lifecycle `payload.stream` (NOT part of frozen promise)

Events emitted via `StreamLifecycleEventEmitter` inject a nested block:

```json
{
  "node_name": "alpha",
  "duration_ms": 12.5,
  "stream": {
    "seq": 5,
    "node_seq": 2,
    "event_type": "node_complete",
    "bytes": 42
  }
}
```

**Clients may ignore `payload.stream`.** It is framework telemetry and may change without a major version bump.

#### `node_complete` optional state capture

Controlled by `agenticgraph.streaming.capture.state` (default `none`).

| Config | Extra payload keys |
|--------|-------------------|
| `none` | none |
| `diff` | `state_diff: { added: Record<string,unknown>, modified: Record<string,{from,to}>, removed: Record<string,unknown> }` |
| `full` | `state: { __agenticgraph_state: { data: object, metadata: object } }` |

Model these as **presence-conditional** in client types.

#### `stream_resume.gap_events`

When store replay is enabled, each gap item includes `type` inline (exception to external-type rule):

```json
{
  "seq": 3,
  "node_seq": 2,
  "node_name": "alpha",
  "type": "text_delta",
  "payload": { "delta": "hi" },
  "occurred_at": "2024-01-01T00:00:00+00:00"
}
```

#### `stream_error` payload

```json
{
  "error_type": "RuntimeException",
  "message": "string",
  "stage": "store|transport|observer",
  "event_type": "text_delta|null",
  "event_seq": 1,
  "event_node_name": "alpha|null",
  "event_node_seq": 2
}
```

---

### Semi-closed Prism content set (version-pinned)

Mapped by `PrismStreamAdapter`. **Pinned to Prism `>=0.99.20 <0.100.0`.** A future Prism upgrade may add types — clients should handle unknown content `type` strings gracefully.

| `type` | Payload shape |
|--------|---------------|
| `text_start` | `{ message_id, timestamp }` |
| `text_delta` | `{ delta, message_id, timestamp }` |
| `text_complete` | `{ message_id, timestamp }` |
| `tool_call` | `{ tool_id, tool_name, arguments, message_id, reasoning_id, timestamp }` |
| `tool_result` | `{ tool_id, tool_name, result, message_id, success, error, timestamp }` |
| `thinking_start` | `{ id, timestamp }` |
| `thinking_delta` | `{ id, delta, summary, timestamp }` |
| `thinking_complete` | `{ id, summary, timestamp }` |
| `step_start` | `{ id, timestamp }` (+ `model`, `provider`, `metadata` for legacy stream start) |
| `step_finish` | `{ id, timestamp }` (+ `finish_reason`, `usage`, `citations`, `additional_content` for stream end) |
| `provider_tool_event` | `{ event_key, tool_type, status, item_id, data, timestamp }` |
| `artifact` | `{ tool_call_id, tool_name, message_id, artifact, block_index, metadata, timestamp }` |
| `error` | `{ error_type, message, recoverable, metadata, timestamp }` |

Prism content events: **no** `payload.stream` nesting; `node_seq` continues the per-node sequence.

---

### Open author set

`AbstractNode::streamEvent(string $type, array $payload)`:

- `$type`: **any string** (author-defined)
- `$payload`: author-defined `Record<string, unknown>`
- No `payload.stream` nesting

Client fallback: `{ eventType: string, payload: unknown }` for non-enumerated types.

---

## Delivery contract

### Broadcast (async / WebSocket)

| Item | Value |
|------|-------|
| Channel | `config('agenticgraph.streaming.broadcast.channel_prefix') + streamId` |
| Default prefix | `agenticgraph.stream.` |
| Resolver | `ExecutionHandle::broadcastChannel()` |
| Event name | The `type` string (e.g. `text_delta`, `node_complete`) |
| Payload | 9-field wire body (**no `type` inside**) |
| Terminal | `stream_close` with `{ status: 'success'\|'cancelled'\|'error', meta: unknown }` |

**Laravel Echo example** (dot-prefix is driver-dependent):

```javascript
Echo.channel(handle.broadcastChannel)
  .listen('.text_delta', (body) => { /* body has no type field */ })
  .listen('.node_complete', (body) => { })
  .listen('.stream_close', ({ status, meta }) => { });
```

Package tests assert raw event name `'text_delta'` on the broadcaster; confirm dot-prefix behavior for your driver.

### SSE (store-backed replay)

| Item | Value |
|------|-------|
| Route | `GET {prefix}/{streamId}` — default `agentic-graph/streams/{streamId}` |
| Cursor | `?cursor=` query or `Last-Event-ID` header |
| Frame | `id: {seq}\nevent: {type}\ndata: {json wire body}\n\n` |
| Terminal | `execution_terminal` with full 9-field envelope; payload `{ status: 'completed'\|'interrupted'\|'failed', final_state, meta? }` |

#### Transport-only SSE events (NOT frozen graph semantics)

| `type` | Purpose |
|--------|---------|
| `stream_heartbeat` | Keep-alive `{ timestamp }` |
| `stream_replay_unavailable` | Cursor miss `{ cursor, final_state? }` |

Do not build graph logic against these.

### Iterator (in-process only)

Sync `StreamingMode::ITERATOR` yields `StreamEventImpl` objects with `type()` method. **Not an NPM wire path** — PHP in-process only.

---

## Terminals: two vocabularies

Delivery mode determines the terminal event. This is **by design**, not a bug.

| Path | Terminal event | Status values |
|------|----------------|---------------|
| **Broadcast** | `stream_close` | `success`, `cancelled`, `error` |
| **SSE / store** | `execution_terminal` | `completed`, `interrupted`, `failed` |

**Mapping:**

| Broadcast `stream_close.status` | SSE `execution_terminal.payload.status` |
|--------------------------------|----------------------------------------|
| `success` | `completed` |
| `cancelled` | `interrupted` |
| `error` | `failed` |

`stream_close` uses a **minimal** payload (not the 9-field envelope). `execution_terminal` uses the full envelope.

---

## Public vs internal split

### Frozen public (major-version promise)

- 9-field wire body keys and types
- External `type` delivery semantics
- Closed framework type strings and base payloads above
- Prism content set (for pinned Prism version)
- Open author `type` + payload pattern
- Broadcast channel convention
- Both terminal contracts and status mapping
- Conditional `state_diff` / `state` shapes on `node_complete`

### Public observability (frozen, optional for basic clients)

- `span_id`, `parent_span_id` — trace correlation (AgenticPulse)
- `node_seq` — per-node ordering within a node execution

### Serialized but NOT promised

- `payload.stream` on lifecycle events
- `stream_heartbeat`, `stream_replay_unavailable`
- Raw Redis XADD / database store rows (include top-level `type` field — different from broadcast/SSE wire body)

---

## TypeScript starter (NPM client)

```typescript
export const FROZEN_WIRE_BODY_KEYS = [
  'stream_id', 'execution_id', 'node_name', 'span_id', 'parent_span_id',
  'seq', 'node_seq', 'occurred_at', 'payload',
] as const;

export type StreamWireBody = {
  stream_id: string;
  execution_id: string;
  node_name: string | null;
  span_id: string | null;
  parent_span_id: string | null;
  seq: number;
  node_seq: number | null;
  occurred_at: string;
  payload: unknown;
};

export type FrameworkEventType =
  | 'graph_start' | 'graph_complete' | 'graph_failed'
  | 'node_start' | 'node_complete' | 'node_error' | 'node_interrupt'
  | 'stream_resume' | 'execution_terminal' | 'stream_close' | 'stream_error';

export type PrismContentEventType =
  | 'text_start' | 'text_delta' | 'text_complete'
  | 'tool_call' | 'tool_result'
  | 'thinking_start' | 'thinking_delta' | 'thinking_complete'
  | 'step_start' | 'step_finish'
  | 'provider_tool_event' | 'artifact' | 'error';

export type StreamEvent = {
  eventType: FrameworkEventType | PrismContentEventType | (string & {});
} & StreamWireBody;
```

---

## Version history

| Version | Date | Notes |
|---------|------|-------|
| **1.0** | 2026-06 | Initial freeze after prefreeze fixes (coherent `node_seq`, `StreamErrorPayload` wire serialization, `NODE_INTERRUPT` constant) |
