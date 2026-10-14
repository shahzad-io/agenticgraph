# Stream Wire Contract v1.1

**Status:** FROZEN (public API). Versioning rule: before the first public release, amendments are recorded in the [version history](#version-history) as v1.1. After the first public release, changing frozen fields, event type strings, or delivery semantics is a **breaking change** requiring a major version bump.

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
| `node_interrupt` | node | `null` | `{ node_name: string, reason: string, checkpoint_id: string }` + optional review payload |
| `stream_resume` | resume node | `null` | `{ from_seq, resumed_at_seq, gap_events, checkpoint_id }` |
| `execution_terminal` | `null` | `null` | `{ status: 'completed'\|'interrupted'\|'failed', final_state: object \| null, meta?: object }`. See [final_state](#final_state-exposure) |
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

#### `node_interrupt` optional review payload

Present when the interrupting node implements `getInterruptPayload()` and returns non-null content (default on `PrismNode` is `null` — no key emitted). Author-defined review content for the UI (forms, drafts, structured decisions).

| Condition | Extra payload key |
|-----------|-------------------|
| `getInterruptPayload()` returns non-null | `interrupt_payload: mixed` (author-defined shape) |

The baseline trio (`node_name`, `reason`, `checkpoint_id`) is **always** present. Model `interrupt_payload` as **presence-conditional** in client types.

**Client visibility and author responsibility:** `interrupt_payload` is emitted on broadcast and SSE — it is **client-visible by design** (the human must review it). The package does not filter or redact this content. Authors control what `getInterruptPayload()` returns and must not place secrets or sensitive data in it unless they accept that exposure on the wire. Same responsibility model as tracing payloads: capability + sane default (`null` / off); the author owns the content.

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

Mapped by `PrismStreamAdapter` (`src/Streaming/PrismStreamAdapter.php::resolvePayload`). **Pinned to Prism `>=0.99.20 <0.100.0`.** A future Prism upgrade may add types — clients should handle unknown content `type` strings gracefully.

Field types below reflect **what the adapter actually emits** (JSON types after `json_encode`), not Prism's internal `toArray()` shapes.

| `type` | Payload shape |
|--------|---------------|
| `text_start` | `{ message_id: string, timestamp: integer }` |
| `text_delta` | `{ delta: string, message_id: string, timestamp: integer }` |
| `text_complete` | `{ message_id: string, timestamp: integer }` |
| `tool_call` | `{ tool_id: string, tool_name: string, arguments: object, message_id: string, reasoning_id: string \| null, timestamp: integer }` — `arguments` is `Record<string, mixed>` from `ToolCall::arguments()` |
| `tool_result` | `{ tool_id: string, tool_name: string, result: number \| string \| array \| null, message_id: string, success: boolean, error: string \| null, timestamp: integer }` — `result` matches `ToolResult::$result` (`int\|float\|string\|array\|null`) |
| `thinking_start` | `{ id: string, timestamp: integer }` |
| `thinking_delta` | `{ id: string, delta: string, summary: object \| null, timestamp: integer }` — `summary` is `array<string, mixed> \| null` (`ThinkingEvent::$summary`) |
| `thinking_complete` | `{ id: string, summary: object \| null, timestamp: integer }` — `summary` is `array<string, mixed> \| null` |
| `step_start` | `{ id: string, timestamp: integer }` plus optional legacy fields when mapped from `StreamStartEvent` (see below) |
| `step_finish` | `{ id: string, timestamp: integer }` plus optional legacy fields when mapped from `StreamEndEvent` (see below) |
| `provider_tool_event` | `{ event_key: string, tool_type: string, status: string, item_id: string, data: object, timestamp: integer }` — `data` is `array<string, mixed>` |
| `artifact` | `{ tool_call_id: null, tool_name: null, message_id: string, artifact: object, block_index: integer \| null, metadata: object \| null, timestamp: integer }` — mapped from `CitationEvent` only; adapter hardcodes `tool_call_id`/`tool_name` as `null` (lines 189–190); `artifact` is the `Citation` value object JSON-encoded (camelCase keys, see below) |
| `error` | `{ error_type: string, message: string, recoverable: boolean, metadata: object \| null, timestamp: integer }` — `metadata` is `array<string, mixed> \| null` |

#### `artifact.artifact` (Citation object on the wire)

Emitted as JSON from `Citation` public properties (`PrismStreamAdapter.php:192`):

```json
{
  "sourceType": "url",
  "source": "https://example.com",
  "sourceText": "string|null",
  "sourceTitle": "string|null",
  "sourcePositionType": "string|null",
  "sourceStartIndex": "integer|null",
  "sourceEndIndex": "integer|null",
  "additionalContent": {}
}
```

`sourceType` / `sourcePositionType` are backed enum **values** (strings). `source` is `string|integer`.

#### Legacy `step_start` fields (`StreamStartEvent` mapping only)

Present only when `step_start` is produced from Prism `StreamStartEvent` (not from `StepStartEvent`):

| Field | Type |
|-------|------|
| `model` | `string` |
| `provider` | `string` |
| `metadata` | `object \| null` (`array<string, mixed> \| null`) |

`StepStartEvent` mapping emits only `{ id, timestamp }`.

#### Legacy `step_finish` fields (`StreamEndEvent` mapping only)

Present only when `step_finish` is produced from Prism `StreamEndEvent` (not from `StepFinishEvent`):

| Field | Type |
|-------|------|
| `finish_reason` | `string` (enum name, e.g. `"Stop"`) |
| `usage` | `object \| null` — `{ prompt_tokens, completion_tokens, cache_write_input_tokens, cache_read_input_tokens, thought_tokens }` (integers; cache/thought fields nullable) |
| `citations` | `array \| null` — provider-specific citation parts; shape varies (loose; do not build logic against the inner structure) |
| `additional_content` | `object` (`array<string, mixed>`; defaults to `{}`) |

`StepFinishEvent` mapping emits only `{ id, timestamp }`.

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
| Terminal | `stream_close` with `{ status: 'success'\|'cancelled'\|'error', meta: unknown }`. When `meta.final_state` is present it follows [final_state](#final_state-exposure) (null by default); other `meta` keys are unchanged |

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
| Terminal | `execution_terminal` with full 9-field envelope; payload `{ status: 'completed'\|'interrupted'\|'failed', final_state: object \| null, meta? }`. Always emitted, even when `nodeFilter` or `eventTypeFilter` would exclude it (filters apply only to emitted events), because clients need it to end the stream |

#### Transport-only SSE events (NOT frozen graph semantics)

| `type` | Purpose |
|--------|---------|
| `stream_heartbeat` | Keep-alive `{ timestamp }` |
| `stream_replay_unavailable` | Cursor miss `{ cursor, final_state? }`. `final_state` is present only when `execution_id` is passed and the execution has a stored final state; it follows [final_state](#final_state-exposure) and is never more than the terminal event would send |

Do not build graph logic against these.

### final_state exposure

`final_state` in `execution_terminal`, broadcast `stream_close.meta` and `stream_replay_unavailable` is built by one helper, `StreamEventEnvelope::clientFinalState()`, from this config:

```php
'streaming' => [
    'final_state' => [
        'expose' => env('AGENTICGRAPH_STREAM_EXPOSE_FINAL_STATE', false),
        'keys' => null, // null = all keys when exposed; array = allowlist of top-level state keys
    ],
],
```

| Config | `final_state` on the wire |
|--------|---------------------------|
| `expose` false, or the key missing from a published config | `null` |
| `expose` true, `keys` a list | Plain state object with only the listed top-level keys |
| `expose` true, `keys` null | Plain state object with every top-level key |

The value is the plain state data (`{ "response": "..." }`), not the internal storage envelope. Clients must handle `null`.

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

**Ordering rule:** nothing follows `execution_terminal` in a run. It is the last stored event, `stream_close` is the last broadcast, and each run closes exactly once. On a failure, `graph_failed` comes before the terminal (a node error gives `node_error`, `graph_failed`, `execution_terminal`). A resumed run continues the same stream: it starts with `stream_resume` after the previous run's `interrupted` terminal and ends with its own terminal.

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

export type ExecutionTerminalPayload = {
  status: 'completed' | 'interrupted' | 'failed';
  final_state: Record<string, unknown> | null; // null unless the server exposes it
  meta?: Record<string, unknown>;
};
```

---

## Version history

| Version | Date | Notes |
|---------|------|-------|
| **1.0** | 2026-06 | Initial freeze after prefreeze fixes (coherent `node_seq`, `StreamErrorPayload` wire serialization, `NODE_INTERRUPT` constant) |
| **1.1** | 2026-10 | Pre-release amendment (allowed only because the contract was not yet publicly released). `final_state` is nullable and `null` by default in `execution_terminal`, broadcast `stream_close.meta` and `stream_replay_unavailable`; opt in with `AGENTICGRAPH_STREAM_EXPOSE_FINAL_STATE=true` and an optional `keys` allowlist. When exposed it is the plain state object, not the storage envelope. `execution_terminal` is documented as always emitted regardless of stream filters, and as the last event of a run (`graph_failed` before it, one `stream_close`). Breaking for clients that assume an object |
