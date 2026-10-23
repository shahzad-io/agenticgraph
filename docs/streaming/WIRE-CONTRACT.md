# Stream Wire Contract v1.1

**Status:** FROZEN (public API). Versioning rule: before the first public release, amendments are recorded in the [version history](#version-history) as v1.1. After the first public release, changing frozen fields, event type strings, or delivery semantics is a **breaking change** requiring a major version bump.

**Source of truth:** `Shahzad\AgenticGraph\Streaming\StreamEventEnvelope` (`src/Streaming/StreamEventEnvelope.php`).

This document is the canonical type spec for the npm streaming clients. Field names are **verbatim** - mirror them exactly in TypeScript.

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
| `graph_failed` | `null` | `null` | `{ error_type: string, message: string, reason?: string }`. `reason` is optional; `interrupt_expired` when `agenticgraph:interrupt:expire` ended the execution |
| `node_start` | node | `1` | `{ node_name: string }` |
| `node_complete` | node | increments | `{ node_name: string, duration_ms: number }` + optional state capture |
| `node_error` | node | increments | `{ node_name: string, error_type: string, message: string }` |
| `node_interrupt` | node | `null` | `{ node_name: string, reason: string, checkpoint_id: string }` + optional review payload |
| `stream_resume` | resume node | `null` | `{ from_seq, resumed_at_seq, gap_events, checkpoint_id }` |
| `execution_terminal` | `null` | `null` | `{ status: 'completed'\|'interrupted'\|'failed', final_state: object \| null, meta?: object }`. See [final_state](#final_state-exposure) |
| `stream_close` | - | - | **Not a 9-field body** - see [Terminals](#terminals-two-vocabularies) |
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

Present when the interrupting node implements `getInterruptPayload()` and returns non-null content (default on `PrismNode` is `null` - no key emitted). Author-defined review content for the UI (forms, drafts, structured decisions).

| Condition | Extra payload key |
|-----------|-------------------|
| `getInterruptPayload()` returns non-null | `interrupt_payload: mixed` (author-defined shape) |

The baseline trio (`node_name`, `reason`, `checkpoint_id`) is **always** present. Model `interrupt_payload` as **presence-conditional** in client types.

**Client visibility and author responsibility:** `interrupt_payload` is emitted on broadcast and SSE - it is **client-visible by design** (the human must review it). The package does not filter or redact this content. Authors control what `getInterruptPayload()` returns and must not place secrets or sensitive data in it unless they accept that exposure on the wire. Same responsibility model as tracing payloads: capability + sane default (`null` / off); the author owns the content.

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

Mapped by `PrismStreamAdapter` (`src/Streaming/PrismStreamAdapter.php::resolvePayload`). **Pinned to Prism `^0.100.1` (>=0.100.1 <0.101.0).** A future Prism upgrade may add types, so clients should handle unknown content `type` strings gracefully.

Field types below reflect **what the adapter actually emits** (JSON types after `json_encode`), not Prism's internal `toArray()` shapes.

| `type` | Payload shape |
|--------|---------------|
| `text_start` | `{ message_id: string, timestamp: integer }` |
| `text_delta` | `{ delta: string, message_id: string, timestamp: integer }` |
| `text_complete` | `{ message_id: string, timestamp: integer }` |
| `tool_call` | `{ tool_id: string, tool_name: string, arguments: object, message_id: string, reasoning_id: string \| null, timestamp: integer }` - `arguments` is `Record<string, mixed>` from `ToolCall::arguments()` |
| `tool_result` | `{ tool_id: string, tool_name: string, result: number \| string \| array \| null, message_id: string, success: boolean, error: string \| null, timestamp: integer }` - `result` matches `ToolResult::$result` (`int\|float\|string\|array\|null`) |
| `thinking_start` | `{ id: string, timestamp: integer }` |
| `thinking_delta` | `{ id: string, delta: string, summary: object \| null, timestamp: integer }` - `summary` is `array<string, mixed> \| null` (`ThinkingEvent::$summary`) |
| `thinking_complete` | `{ id: string, summary: object \| null, timestamp: integer }` - `summary` is `array<string, mixed> \| null` |
| `stream_start` | `{ id: string, timestamp: integer, model: string, provider: string, metadata: object \| null }`. From `StreamStartEvent`: once per model request, before the first step |
| `step_start` | `{ id: string, timestamp: integer }`. From `StepStartEvent`: once per step (one model call of a tool run) |
| `step_finish` | `{ id: string, timestamp: integer, finish_reason: string \| null, usage: object \| null }`. From `StepFinishEvent`: once per step, with **that step's** finish reason and usage (see below) |
| `stream_end` | `{ id: string, timestamp: integer, finish_reason: string, usage: object \| null, citations: array \| null, additional_content: object }`. From `StreamEndEvent`: once per model request, after the last step, with the request's finish reason and **cumulative** usage (see below) |
| `provider_tool_event` | `{ event_key: string, tool_type: string, status: string, item_id: string, data: object, timestamp: integer }` - `data` is `array<string, mixed>` |
| `artifact` | `{ tool_call_id: null, tool_name: null, message_id: string, artifact: object, block_index: integer \| null, metadata: object \| null, timestamp: integer }` - mapped from `CitationEvent` only; adapter hardcodes `tool_call_id`/`tool_name` as `null` (lines 189–190); `artifact` is the `Citation` value object JSON-encoded (camelCase keys, see below) |
| `error` | `{ error_type: string, message: string, recoverable: boolean, metadata: object \| null, timestamp: integer }` - `metadata` is `array<string, mixed> \| null` |

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

#### Steps

One streamed model request is `stream_start`, then per step `step_start`, its text, thinking and `tool_call` events, the `tool_result` events (tools run between `tool_call` and `tool_result` with no events, so a slow tool is a quiet gap), `step_finish`, and finally `stream_end`. A single-step request has one pair. Each step has its own `message_id`. A run that stops on `maxSteps` ends with `step_finish` (`ToolCalls`) and `stream_end`, without a final text.

| Field | On | Type |
|-------|----|------|
| `finish_reason` | `step_finish`, `stream_end` | `string` (enum name, e.g. `"Stop"`, `"ToolCalls"`); nullable on `step_finish` when the provider gives none |
| `usage` | `step_finish` (that step), `stream_end` (sum of the steps) | `object \| null`: `{ prompt_tokens, completion_tokens, cache_write_input_tokens, cache_read_input_tokens, thought_tokens }` (integers; cache/thought fields nullable) |
| `citations` | `stream_end` | `array \| null`: provider-specific citation parts; shape varies (loose; do not build logic against the inner structure) |
| `additional_content` | `stream_end` | `object` (`array<string, mixed>`; defaults to `{}`) |

Before this amendment, `StreamStartEvent` and `StreamEndEvent` were also mapped to `step_start` and `step_finish` (so one step gave two of each, told apart only by the optional keys), and `step_finish` from a step carried only `{ id, timestamp }`.

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
| Route | `GET {prefix}/{streamId}` - default `agentic-graph/streams/{streamId}` |
| Cursor | `?cursor=` query or `Last-Event-ID` header. The query wins when both are sent. The response starts at `cursor + 1`; no cursor starts at seq 1 |
| Retry | Every response starts with `retry: {streaming.sse.retry_ms}` (default 2000) |
| Frame | Stored events: `id: {seq}\nevent: {type}\ndata: {json wire body}\n\n`. Control events: no `id:` line (see below) |
| Terminal | `execution_terminal` with full 9-field envelope; payload `{ status: 'completed'\|'interrupted'\|'failed', final_state: object \| null, meta? }`. Always emitted, even when `nodeFilter` or `eventTypeFilter` would exclude it (filters apply only to emitted events), because clients need it to end the stream |

#### Connection lifecycle

- `execution_terminal` with `completed` or `failed`: the server closes the response after it.
- `execution_terminal` with `interrupted`: the response stays open (heartbeats while the run waits), so the resumed segment (`stream_resume` onward, same stream id, seq continues) arrives on the same connection.
- At `streaming.sse.max_duration_seconds` the server sends control `reconnect` and closes. This is a planned close: reconnect with the last seen seq as the cursor.
- Replay-unavailable is sent only when the cursor is really gone: `cursor + 1 < firstSeq` (retention trimmed it), or the stream is empty and `cursor > 0` (expired). It is sent once at the start of the response, then the response closes. A stream that is waiting for more events never gets it.

#### Control events

Control events are transport messages, not stream events: no `id:` line, no `seq`, never stored, never counted in the stream's sequence. The body is a JSON object with a `control` key; a client recognizes control events by that key, not by the 9 wire fields.

| `event:` | Body | Meaning |
|--------|------|---------|
| `stream_heartbeat` | `{ "control": "heartbeat", "timestamp": "<ISO 8601>" }` | Keep-alive every `streaming.sse.heartbeat_interval` seconds without events |
| `stream_reconnect` | `{ "control": "reconnect", "after_ms": <retry_ms> }` | Planned close at max duration. Reconnect after `after_ms` with the last seq as cursor |
| `stream_replay_unavailable` | `{ "control": "replay_unavailable", "cursor": <int>, "final_state"?: object \| null }` | The cursor is gone from the store; the response closes. `final_state` is present only when `execution_id` is passed and the execution has a stored final state; it follows [final_state](#final_state-exposure) and is never more than the terminal event would send |

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

Sync `StreamingMode::ITERATOR` yields `StreamEventImpl` objects with `type()` method. **Not an NPM wire path** - PHP in-process only.

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
- SSE connection lifecycle and control events (`control` key, no `id:`, no `seq`)
- Conditional `state_diff` / `state` shapes on `node_complete`

### Public observability (frozen, optional for basic clients)

- `span_id`, `parent_span_id` - trace correlation
- `node_seq` - per-node ordering within a node execution

### Serialized but NOT promised

- `payload.stream` on lifecycle events
- Raw Redis XADD / database store rows (include top-level `type` field - different from broadcast/SSE wire body)

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
  | 'stream_start' | 'step_start' | 'step_finish' | 'stream_end'
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
| **1.1** | 2026-10 | Additive: `graph_failed` has an optional `reason` string. `agenticgraph:interrupt:expire` sets `interrupt_expired`, then the failed `execution_terminal` follows as for any failure. Clients that ignore unknown payload keys need no change. |
| **1.1** | 2026-10 | Pre-release amendment (allowed only because the contract was not yet publicly released). SSE control events (`stream_heartbeat`, new `stream_reconnect`, `stream_replay_unavailable`) have no `id:` and no `seq`, and carry a `control` key instead of the 9 wire fields. `stream_replay_unavailable` used to claim the next real seq. Every SSE response starts with `retry:`. An `interrupted` terminal no longer closes the response. Max duration ends with control `reconnect`. Replay-unavailable only when the cursor is below the first stored seq or the stream is empty with `cursor > 0`, never during a wait. Breaking for SSE clients that read heartbeat or replay-unavailable as wire bodies |
| **1.1** | 2026-10 | Pre-release amendment (allowed only because the contract was not yet publicly released). New Prism content types `stream_start` and `stream_end` (Prism `StreamStartEvent`, `StreamEndEvent`; they were mapped to `step_start` and `step_finish`). `step_start` and `step_finish` are now one pair per step; `step_finish` carries that step's `finish_reason` and `usage`, `stream_end` the request's and the cumulative `usage`. Multi-step tool runs and MCP stream. Breaking for clients that counted step events or read `usage` from `step_finish` |
