---
name: agenticgraph-streaming
description: "Stream AgenticGraph output: the stream and broadcast presets, ExecutionMode and StreamingMode, SSE and broadcast transports, stream events, and the @agenticgraph/stream-client, stream-react, and stream-vue clients."
---

# AgenticGraph Streaming

docs-sha: 301ce71

Condensed from the AgenticGraph docs (`/docs/...` paths below) at that commit. When a detail here and the package source disagree, the source wins.

Other skills, use them when installed: `agenticgraph-core` for graphs, nodes, interrupts and `run()` / `dispatch()`. `agenticgraph-tracing` for the span tree after a run; streaming is live progress, not the trace.

## Same loop, three deliveries

Streaming does not change the graph. It adds a sink to the same loop.

| Preset | Modes | You hold |
|---|---|---|
| `AgenticGraph::stream($name, $state)` | `ASYNC` + `SSE` | `$handle->stream()->streamUrl`. A worker writes the store; the HTTP route reads it. |
| `AgenticGraph::stream($name, $state, inline: true)` | `SYNC` + `ITERATOR` | `$handle->stream()->inline`. Consume in this process. No queue. |
| `AgenticGraph::broadcast($name, $state)` | `ASYNC` + `BROADCAST` | `$handle->broadcastChannel()`. Reverb or Echo. No replay. |
| `AgenticGraph::broadcast($name, $state, async: false)` | `SYNC` + `BROADCAST` | Same channel, loop in this process |

- Both presets also take `nodes:` and `events:` filters.
- `run()` and `dispatch()` use `StreamingMode::NONE`.
- Async presets dispatch `ExecuteGraphJob`. No worker, no events.

Docs: `/docs/streaming`, `/docs/api/streaming`

## ExecutionMode and StreamingMode

```php
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;

$handle = AgenticGraph::execute('report', $state, new ExecutionOptions(
    mode: ExecutionMode::ASYNC,
    streaming: StreamingMode::AUTO,
    estimatedDurationSeconds: 600,
    nodeFilter: ['write'],
    eventTypeFilter: ['text_delta'],
));
```

- `ExecutionMode`: `SYNC`, `ASYNC`.
- `StreamingMode`: `NONE`, `SSE`, `BROADCAST`, `ITERATOR`, `AUTO`.
- `AUTO` is resolved before the loop. Broadcast when SSE is disabled, or when `estimatedDurationSeconds` reaches `streaming.broadcast.force_above_seconds` (`AGENTICGRAPH_STREAM_BROADCAST_FORCE_ABOVE`, 300). Otherwise SSE. Only `ExecutionOptions` can choose `AUTO`; the presets cannot.
- `ITERATOR` is sync only.

Docs: `/docs/streaming`, `/docs/api/execution`

## From a controller

```php
$handle = AgenticGraph::broadcast('evaluator-optimizer', $state, nodes: $nodes, events: $events);

return response()->json([
    'execution_id' => $handle->id(),
    'stream_id' => $handle->streamId(),
    'channel' => $handle->broadcastChannel(),
    'status' => $handle->status()->value,
]);
```

- For SSE, return `$handle->stream()->streamUrl` instead of the channel.
- Status and interrupt payloads still come from HTTP (`getExecution`, `interruptDetails`). The stream is progress, not the whole handle.

Docs: `/docs/streaming#from-a-controller`

## Events

- **Lifecycle**, from the coordinator: `graph_start`, `graph_complete`, `graph_failed`, `node_start`, `node_complete`, `node_error`, `node_interrupt`, `stream_resume`.
- **Terminal**: `execution_terminal` (SSE; `status` is `completed`, `interrupted` or `failed`, plus `final_state`), `stream_close` (broadcast; `{status, meta}`), `stream_error`.
- **Prism**, from `prompt()`: `text_start`, `text_delta`, `text_complete`, `tool_call`, `tool_result`, `thinking_*`, `step_start`, `step_finish`, `provider_tool_event`, `artifact`, `error`.
- **Yours**: any string through `streamEvent($type, $payload)`.
- `stream_heartbeat` and `stream_replay_unavailable` are SSE only and may change.

```php
$this->streamEvent('progress', ['message' => 'Refining the draft', 'iteration' => 2]);

$this->whenStreaming(function () use ($items): void {
    foreach ($items as $i => $item) {
        $this->streamEvent('keywords_item', ['keyword' => $item, 'index' => $i]);
    }
});
```

- `prompt()` streams by itself when a sink is bound and still returns the full string. There is no special streaming node.
- `streamEvent()` is a no-op without a sink, for example in a `run()` or `dispatch()` graph.
- The type is the SSE `event:` line or the broadcast event name. It is not in the JSON body.
- The body is frozen: `stream_id`, `execution_id`, `node_name`, `span_id`, `parent_span_id`, `seq`, `node_seq`, `occurred_at`, `payload`. `seq` is the SSE `id:` and the replay cursor.
- `node_complete` carries `node_name` and `duration_ms`. `AGENTICGRAPH_STREAM_CAPTURE_STATE` = `diff` adds `state_diff`; `full` adds `state`.

Docs: `/docs/streaming#your-own-events`, `/docs/api/streaming`

## Filters

- `nodes:` and `events:` are AND filters on every emitted event. Lifecycle events are not exempt.
- `events: ['text_delta']` hides `node_interrupt`. Keep a status route, or include the event.
- `stream_close` is never filtered, so a client still learns that the run was interrupted.

Docs: `/docs/streaming#filters`

## SSE

- The route is `GET /agentic-graph/streams/{streamId}`, named `agenticgraph.streams.show`. It needs `AGENTICGRAPH_STREAMING_ROUTES` on (default) and uses the `api` middleware group.
- Reconnect from a point with `?cursor=` or `Last-Event-ID`. The controller polls the store until `execution_terminal` or `AGENTICGRAPH_STREAM_SSE_MAX_DURATION` (120 s).
- `$handle->replayFrom($cursor)` reads the store again from PHP.

```dotenv
STREAM_STORE_DRIVER=redis
AGENTICGRAPH_QUEUE_CONNECTION=redis
AGENTICGRAPH_QUEUE_NAME=agenticgraph
```

- `STREAM_STORE_DRIVER` is `redis` (default) or `database` (the `stream_events` table from the package migrations).
- Store write errors are not fatal unless `AGENTICGRAPH_STREAM_STORE_FATAL=true`. Transport errors are fatal by default.
- "No events" checklist: store driver set, migrations run, `php artisan queue:work --queue=agenticgraph` on the same connection as `AGENTICGRAPH_QUEUE_CONNECTION`, one connect per transport.

Docs: `/docs/stream-transports`, `/docs/stream-limitations`

## Broadcast

- The channel is `agenticgraph.stream.{streamId}` (`AGENTICGRAPH_STREAM_BROADCAST_CHANNEL_PREFIX`).
- Needs Reverb (or Pusher), Laravel Echo and `pusher-js`, with `BROADCAST_CONNECTION=reverb`.
- Events sent before the client subscribes are lost. Subscribe before kickoff when you can, or use SSE.

Docs: `/docs/stream-transports#broadcast-echotransport`

## Inline iterator

```php
$handle = AgenticGraph::stream('seo-metadata', $state, inline: true);

foreach ($handle->stream()->inline ?? [] as $event) {
    echo $event->type(), ' ', $event->seq(), PHP_EOL;
}
```

- `$handle->stream()` is a `StreamDescriptor`, not an iterator. Use `->inline`, `->streamUrl`, `hasStream()`.
- This is the way to test streams: no Redis, no Reverb, no worker.

Docs: `/docs/streaming#how-you-listen`, `/docs/guide-testing`

## Pause and resume while streaming

- A gate still pauses a streaming run. Resume is the usual `AgenticGraph::resume($executionId, $input)`.
- The stream id is reused, so the client stays on the same connection. Reconnect only when a new `stream_id` comes back.
- `stream_resume` marks the continuation. Gates are still `shouldInterrupt()` on a `PrismNode`; see the `agenticgraph-core` skill.

Docs: `/docs/streaming#pause-and-resume`, `/docs/interrupts`

## JS clients

| Package | Use |
|---|---|
| `@agenticgraph/stream-client` | `AgenticStream`, `SSETransport`, `EchoTransport`, `MockTransport`, selectors. `laravel-echo` is an optional peer, for broadcast only. |
| `@agenticgraph/stream-react` | `useAgenticStream`, `useStreamSelector`. React 18+. |
| `@agenticgraph/stream-vue` | Same API with refs. Vue 3.4+. |

```ts
import { AgenticStream, SSETransport } from '@agenticgraph/stream-client';

const stream = await AgenticStream.connect({ transport: new SSETransport({ url: payload.stream_url }) });

stream.on('text_delta', (event) => output.append(event.payload.delta));
stream.onStatusChange((status) => console.log(status));
```

- `AgenticStream.connect()` is the only constructor. It resolves once the transport is connected.
- `status`: `idle`, `streaming`, `complete`, `interrupted`, `error`. `stream.interrupt` has `checkpointId`, `reason`, `node` and `payload`; your `getInterruptPayload()` is at `payload.interrupt_payload`.
- `stream.error.kind`: `connection`, `stream_error`, `terminal_error`, `replay_unavailable`.
- v1 never reconnects by itself. Call `reconnect()`. SSE resumes from the last `seq`; broadcast loses the gap.
- One connect per transport. With the hooks, the hook owns the connection. Never also call `AgenticStream.connect()` on the same transport.
- The hook's `onResume` posts to your Laravel resume route. Send the execution id from your start response; `AgenticGraph::resume()` takes the execution id, not the checkpoint id.
- Not a chat framework: no message history or threads.
- Full React and Vue examples: [references/clients.md](references/clients.md).

Docs: `/docs/stream-getting-started`, `/docs/stream-core`, `/docs/stream-react`, `/docs/stream-vue`, `/docs/stream-limitations`

## Mistakes

1. `stream()` without `inline` is async. Without a worker the EventSource stays quiet.
2. Expecting broadcast to replay. Use SSE with a cursor for gap-free reads.
3. Filtering to `text_delta` and waiting for `node_interrupt`.
4. `foreach ($handle->stream() as $event)`. Iterate `->inline`.
5. Two `connect()` calls on one transport: leaks and spurious `?cursor=0` reconnects.
6. `streamEvent()` in a graph started with `run()`; nothing is emitted.

Docs: `/docs/streaming`, `/docs/stream-limitations`
