---
name: agenticgraph-streaming
description: "Stream AgenticGraph output: the stream and broadcast presets, ExecutionMode and StreamingMode, SSE and broadcast transports, stream events, the signed and resumable SSE route, and the frontend: @agenticgraph/stream-client, stream-react and stream-vue, library reconnect, resolveUrl and null, stop reasons, and the script-tag build."
---

# AgenticGraph Streaming

docs-sha: febf56c

Condensed from the AgenticGraph docs (`/docs/...` paths below) at that commit. When a detail here and the package source disagree, the source wins.

Other skills, use them when installed: `agenticgraph-core` for graphs, nodes, interrupts and `run()` / `dispatch()`. `agenticgraph-tracing` for the span tree after a run; streaming is live progress, not the trace. The client API in detail: the `agenticgraph-stream-client` skill in `@agenticgraph/stream-client` (`node_modules/@agenticgraph/stream-client/skills/`).

## Rules

1. Streaming does not change the graph. It adds a sink to the same loop. A graph started with `run()` or `dispatch()` emits nothing.
2. Use SSE when the client must not lose events. It is gap-free from a cursor. Broadcast has no replay.
3. The client library reconnects SSE by itself. Do not write a retry loop around it. Reconnect by hand only after the library reports `failed`.
4. `resolveUrl` returns a fresh URL, or `null` to end the stream. Return `null` on purpose; throw on a network error.
5. A signed stream URL must validate while ignoring `cursor`, because the client appends it on every reconnect.
6. The stream is progress, not the record. The final state arrives on `execution_terminal` over SSE. After a reconnect or a reload, the HTTP status route (`getExecution`) is the authority.

## Task: choose a delivery

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

### ExecutionMode and StreamingMode

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

## Task: start a stream from a controller

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\State\State;

public function start(Request $request): JsonResponse
{
    $state = new State(['topic' => $request->string('topic')]);
    $sse = $request->input('delivery') === 'sse';

    $handle = $sse
        ? AgenticGraph::stream('draft-hitl', $state)
        : AgenticGraph::broadcast('draft-hitl', $state);

    return response()->json([
        'execution_id' => $handle->id(),
        'stream_id' => $handle->streamId(),
        'stream_url' => $sse ? $handle->stream()->streamUrl : null,
        'channel' => $handle->broadcastChannel(),
        'status' => $handle->status()->value,
    ]);
}
```

- For SSE, return `$handle->stream()->streamUrl`. For broadcast, return the channel.
- Status and interrupt payloads still come from HTTP (`getExecution`, `interruptDetails`). The stream is progress, not the whole handle.
- Send `execution_id` back with the stream URL: the client needs it to resume, and it is what makes `final_state` arrive on `replay_unavailable` (see the SSE task).

Docs: `/docs/streaming#from-a-controller`

## Task: emit your own events

```php
$this->streamEvent('progress', ['message' => 'Refining the draft', 'iteration' => 2]);

$this->whenStreaming(function () use ($items): void {
    foreach ($items as $i => $item) {
        $this->streamEvent('keywords_item', ['keyword' => $item, 'index' => $i]);
    }
});
```

- `streamEvent()` is a no-op without a sink, for example in a `run()` or `dispatch()` graph.
- `$this->llm()` streams by itself when a sink is bound, and `text()` still returns the full string. There is no special streaming node. Text, chat, structured with a Prism `Schema`, tool runs (every step) and MCP stream. Tools run between steps with no events, so a slow tool is a quiet gap. `run()->text` and the hooks' `finalText` are the final step; `run()->steps` and the hooks' `text` hold the narration; the app decides which to persist. A structured retry after a streamed attempt runs without streaming (its reply is not on the wire).

## Events

- **Lifecycle**, from the coordinator: `graph_start`, `graph_complete`, `graph_failed`, `node_start`, `node_complete`, `node_error`, `node_interrupt`, `stream_resume`.
- **Terminal**: `execution_terminal` (SSE; `status` is `completed`, `interrupted` or `failed`, plus `final_state`), `stream_close` (broadcast; `{status, meta}`), `stream_error`.
- **Prism**, from `$this->llm()`: `text_start`, `text_delta`, `text_complete`, `tool_call`, `tool_result`, `thinking_*`, `stream_start`, `step_start`, `step_finish`, `stream_end`, `provider_tool_event`, `artifact`, `error`. One `stream_start` and one `stream_end` per model request (end: cumulative `usage` and `finish_reason`); one `step_start` and `step_finish` per step (finish: that step's `usage` and `finish_reason`). Each step has its own `message_id`.
- **Yours**: any string through `streamEvent($type, $payload)`. Over SSE, add `?custom_events=wrap` to the stream URL (core 0.2.1) and they arrive as `event: custom_event` with your name in `event_type`, so a browser `EventSource` needs no list of names. Without the parameter the frames are unchanged. Stream client 0.1.1 sends it by itself and delivers them under your type; against core 0.2.0 the parameter is ignored and custom events are not delivered over SSE.
- `stream_heartbeat` and `stream_replay_unavailable` are SSE only and may change.
- The type is the SSE `event:` line or the broadcast event name. It is not in the JSON body.
- The body is frozen: `stream_id`, `execution_id`, `node_name`, `span_id`, `parent_span_id`, `seq`, `node_seq`, `occurred_at`, `payload`. `seq` is the SSE `id:` and the replay cursor.
- `node_complete` carries `node_name` and `duration_ms`. `AGENTICGRAPH_STREAM_CAPTURE_STATE` = `diff` adds `state_diff`; `full` adds `state`.

Docs: `/docs/streaming#your-own-events`, `/docs/api/streaming`

## Task: filter events

- `nodes:` and `events:` are AND filters on every emitted event. Lifecycle events are not exempt.
- `events: ['text_delta']` hides `node_interrupt`. Keep a status route, or include the event.
- `stream_close` is never filtered, so a client still learns that the run was interrupted.

Docs: `/docs/streaming#filters`

## Task: error details on the wire

- Since 0.2.1 the browser gets generic errors by default. `graph_failed`, `node_error`, the failed `execution_terminal` `meta` and broadcast `stream_close` `meta` carry `error_type: ExecutionFailed` and `message: Execution failed`. `stream_error` carries `StreamError` and `Stream error`. A failed `tool_result` carries `Tool failed`. `reason` (`interrupt_expired`, `stale_queued`, `stale_running`) is always sent.
- A node failed by a model provider exception shows a category instead: `rate_limited`, `provider_unavailable` or `provider_error`, with a fixed message. The Prism `error` event carries the same categories and `metadata: null`. The package picks the category from the exception type, never from the provider's text, which can hold keys, account ids or internal URLs.
- `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS=true` (default `false`) sends the real exception or provider type, the message (cut to `AGENTICGRAPH_CAPTURE_MAX_LENGTH`; `AGENTICGRAPH_CAPTURE_ERRORS=class` still removes it) and the metadata. Turn it on only for a trusted audience, such as an internal tool.
- The stored execution `error` and the logs always keep the real message. Look there, not in the browser, when a run fails.

Docs: `/docs/streaming`, `/docs/stream-limitations`

## Task: expire stale executions

- An async execution no worker picks up would leave the visitor's stream silent. `php artisan agenticgraph:executions:expire` fails it: status `failed`, `graph_failed` with `reason` `stale_queued` or `stale_running`, and a failed `execution_terminal`, so the client ends in `error`.
- A `queued` execution older than `AGENTICGRAPH_EXECUTION_QUEUED_TIMEOUT` (300 s) is failed. A `running` one with no progress for longer than `max(AGENTICGRAPH_EXECUTION_TIMEOUT, AGENTICGRAPH_RESUME_TIMEOUT)` plus `AGENTICGRAPH_EXECUTION_STALE_GRACE` (60 s) is failed; with `AGENTICGRAPH_EXECUTION_TIMEOUT` at 0 the limit is `AGENTICGRAPH_EXECUTION_STALE_RUNNING_AFTER` (900 s). Each finished node counts as progress. Interrupted and sync executions are never touched.
- Schedule it every minute, next to `agenticgraph:interrupt:expire`:

```php
// routes/console.php
Schedule::command('agenticgraph:executions:expire')->everyMinute();
Schedule::command('agenticgraph:interrupt:expire')->everyMinute();
```

- The scheduler must run (`php artisan schedule:work`, or the `schedule:run` cron entry). Without it nothing expires.

Docs: `/docs/streaming`

## Task: serve SSE

- The route is `GET /agentic-graph/streams/{streamId}`, named `agenticgraph.streams.show`. It needs `AGENTICGRAPH_STREAMING_ROUTES` on (default). Its middleware is `config('agenticgraph.streaming.routes.middleware')`, default `['api']`.
- The controller sends `retry: 2000` (`agenticgraph.streaming.sse.retry_ms`), so a client waits 2 s before it reconnects. It polls the store until `execution_terminal` or `AGENTICGRAPH_STREAM_SSE_MAX_DURATION` (120 s). At the limit it sends control `reconnect` with `after_ms` and closes.
- Reconnect from a point with `?cursor=` or `Last-Event-ID`. Control events (`heartbeat`, `reconnect`, `replay_unavailable`) carry no `id` and are never stored.
- `replay_unavailable` is sent when the cursor is older than the store keeps. It carries `cursor`. It carries `final_state` only when the request has `execution_id` (the query string). Put `execution_id` in the stream URL.
- `$handle->replayFrom($cursor)` reads the store again from PHP.

**Signed URLs.** The route has no signature check of its own. To require one, add Laravel's `signed` middleware and ignore `cursor`, because clients append it:

```php
// config/agenticgraph.php
'streaming' => [
    'routes' => [
        'middleware' => ['api', 'signed:cursor'],
    ],
],
```

Mint the URL with `URL::temporarySignedRoute('agenticgraph.streams.show', now()->addMinutes(10), ['streamId' => $streamId])`, from an endpoint the frontend calls. A signature that covers `cursor` fails on the first reconnect.

```dotenv
STREAM_STORE_DRIVER=redis
AGENTICGRAPH_QUEUE_CONNECTION=redis
AGENTICGRAPH_QUEUE_NAME=agenticgraph
```

- `STREAM_STORE_DRIVER` is `redis` (default) or `database` (the `stream_events` table from the package migrations).
- Store write errors are not fatal unless `AGENTICGRAPH_STREAM_STORE_FATAL=true`. Transport errors are fatal by default.
- "No events" checklist: store driver set, migrations run, `php artisan queue:work --queue=agenticgraph` on the same connection as `AGENTICGRAPH_QUEUE_CONNECTION`, one connect per transport. A run stuck in `queued` with no worker is failed by `agenticgraph:executions:expire` (scheduled).

Docs: `/docs/stream-transports`, `/docs/stream-limitations`

## Task: broadcast

- The channel is `agenticgraph.stream.{streamId}` (`AGENTICGRAPH_STREAM_BROADCAST_CHANNEL_PREFIX`).
- Needs Reverb (or Pusher), Laravel Echo and `pusher-js`, with `BROADCAST_CONNECTION=reverb`.
- Events sent before the client subscribes are lost. Subscribe before kickoff when you can, or use SSE rule 2.

Docs: `/docs/stream-transports#broadcast-echotransport`

## Task: read the stream in PHP

```php
$handle = AgenticGraph::stream('seo-metadata', $state, inline: true);

foreach ($handle->stream()->inline ?? [] as $event) {
    echo $event->type(), ' ', $event->seq(), PHP_EOL;
}
```

- `$handle->stream()` is a `StreamDescriptor`, not an iterator. Use `->inline`, `->streamUrl`, `hasStream()`.
- This is the way to test streams: no Redis, no Reverb, no worker.

Docs: `/docs/streaming#how-you-listen`, `/docs/guide-testing`

## Task: pause and resume while streaming

- A gate still pauses a streaming run. Resume is the usual `AgenticGraph::resume($executionId, $input)`.
- The stream id is reused, so the client stays on the same connection. Reconnect only when a new `stream_id` comes back.
- `stream_resume` marks the continuation. Gates are still `shouldInterrupt()` on a `PrismNode`; see the `agenticgraph-core` skill.

Docs: `/docs/streaming#pause-and-resume`, `/docs/interrupts`

## Task: connect a frontend

The client owns the connection. Create the transport after the start request, give it to one component or one connect call, and do nothing else with it.

| Package | Use |
|---|---|
| `@agenticgraph/stream-client` | `AgenticStream`, `SSETransport`, `EchoTransport`, `MockTransport`, selectors. `laravel-echo` is an optional peer, for broadcast only. |
| `@agenticgraph/stream-react` | `useAgenticStream`, `useStreamSelector`. React 18+. |
| `@agenticgraph/stream-vue` | Same API with refs. Vue 3.4+. |

- `AgenticStream.connect()` is the only constructor. It resolves once the transport is connected, and rejects if the transport reports `failed` first.
- Rule 3: `SSETransport` reconnects by itself. A transient drop keeps `status` where it was. After `maxAttempts` (5) failed retries it reports `failed`: `status` is `error` with `error.kind` `connection` and `error.canReconnect` `true` (a lost connection only; `false` for the other kinds and when the first connect fails). Call `stream.reconnect()`, or `reconnect()` from `useAgenticStream` (client 0.1.1): it resumes from the last event, `status` goes back to `streaming`, `error` clears and the text is kept. Or make a new transport.
- Client 0.1.1 with core 0.2.1: custom events arrive over SSE (the client sends `?custom_events=wrap`), and `execution_terminal` handlers run before `status` changes. `error.kind` `terminal_error` shows `meta.message` and `error_type`, which is generic or a provider category (`rate_limited`, `provider_unavailable`, `provider_error`) unless the server sets `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS`. Do not show `error_type` to a visitor as a diagnosis.
- Rule 4: `resolveUrl(lastSeq)` runs before each reconnect. Return a fresh URL, or `null` to end the stream. Return `null` on purpose: the transport stops with reason `resolver`, and that is not an error. A throw counts as one failed attempt.
- `transport.getStopReason()`: `terminal` (execution completed or failed), `replay_unavailable` (cursor too old; `error.kind` is `replay_unavailable`), `resolver` (`null` from `resolveUrl`), `manual` (`disconnect()`), `failed` (gave up). `null` while running.
- `status`: `idle`, `streaming`, `complete`, `interrupted`, `error`. `error.kind`: `connection`, `stream_error`, `terminal_error`, `replay_unavailable`.
- `interrupt` has `checkpointId`, `reason`, `node` and `payload`. Your `getInterruptPayload()` is at `payload.interrupt_payload`.
- Broadcast (`EchoTransport`) has no cursor and no `resolveUrl`. It loses the gap during a drop.
- One connect per transport. With the hooks, the hook owns the connection. Never also call `AgenticStream.connect()` on the same transport.
- The hook's `onResume` posts to your Laravel resume route. Send the execution id from your start response; `AgenticGraph::resume()` takes the execution id, not the checkpoint id.
- Not a chat framework: no message history or threads.
- Full React and Vue examples: [references/clients.md](references/clients.md).

Docs: `/docs/stream-getting-started`, `/docs/stream-core`, `/docs/stream-react`, `/docs/stream-vue`, `/docs/stream-limitations`

### Script-tag build (no bundler)

`dist/index.global.js` sets `window.AgenticGraphStream`, with the same exports as the module build:

```html
<script src="/js/agenticgraph/index.global.js"></script>
<script>
  const { AgenticStream, SSETransport } = window.AgenticGraphStream;
  AgenticStream.connect({ transport: new SSETransport({ url: streamUrl }) });
</script>
```

Copy the file from `node_modules/@agenticgraph/stream-client/dist/index.global.js` to your public assets during the build. It is one file, with no `laravel-echo` inside.

## Task: test streams

- Inline iterator: `AgenticGraph::stream(..., inline: true)` needs no Redis, Reverb or worker.
- Client tests: `MockTransport` with `emitSequence()`, `simulateDrop()` and `simulateFailure()` (a transport that gave up: the `error` state and `reconnect()`). It does not reconnect; test `SSETransport` reconnect with an injected `eventSourceFactory`.
- Assert the server side with `AgenticGraph::stream()` and the `stream_events` table in a test database.

Docs: `/docs/guide-testing`

## Mistakes

1. `stream()` without `inline` is async. Without a worker the EventSource stays quiet.
2. Expecting broadcast to replay. Use SSE with a cursor for gap-free reads.
3. Filtering to `text_delta` and waiting for `node_interrupt`.
4. `foreach ($handle->stream() as $event)`. Iterate `->inline`.
5. Two `connect()` calls on one transport, or a retry loop around `SSETransport`.
6. `streamEvent()` in a graph started with `run()`; nothing is emitted.
7. A signed URL that covers `cursor`, so every reconnect gets a 403.
8. No `execution_id` in the stream URL, so `replay_unavailable` arrives without `final_state`.
9. Expecting the exception message in the browser. It is generic unless `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS=true`; read the stored execution `error` or the logs.
10. `agenticgraph:executions:expire` not scheduled, so a run without a worker never ends and the stream stays quiet.
11. Custom events with a raw `EventSource` and no `?custom_events=wrap`: they arrive under your own event names, which `onmessage` never sees.

Docs: `/docs/streaming`, `/docs/stream-limitations`
