---
name: agenticgraph-streaming
description: "Stream AgenticGraph output: the stream and broadcast presets, ExecutionMode and StreamingMode, SSE and broadcast transports, stream events, the stream token and the token-guarded SSE, replay and channel auth routes, the private broadcast channel, and the frontend: @agenticgraph/stream-client, stream-react and stream-vue, createStreamTransport, BroadcastTransport, library reconnect, resolveUrl and null, refreshToken, stop reasons, and the script-tag build."
---

# AgenticGraph Streaming

docs-sha: d61ba53

Condensed from the AgenticGraph docs (`/docs/...` paths below) at that commit. When a detail here and the package source disagree, the source wins.

Other skills, use them when installed: `agenticgraph-core` for graphs, nodes, interrupts and `run()` / `dispatch()`. `agenticgraph-tracing` for the span tree after a run; streaming is live progress, not the trace. The client API in detail: the `agenticgraph-stream-client` skill in `@agenticgraph/stream-client` (`node_modules/@agenticgraph/stream-client/skills/`).

## Rules

1. Streaming does not change the graph. It adds a sink to the same loop. A graph started with `run()` or `dispatch()` emits nothing.
2. Both deliveries are gap-free (core 0.3.0, stream client 0.2.0). SSE reads the store from a cursor. Broadcast (`BroadcastTransport`) heals a drop or a late join from the replay route. SSE holds one web worker per open stream; broadcast needs Reverb or Pusher and a real queue.
3. The client library reconnects SSE by itself. Do not write a retry loop around it. Reconnect by hand only after the library reports `failed`.
4. `resolveUrl` returns a fresh URL, or `null` to end the stream. Return `null` on purpose; throw on a network error.
5. Every stream route needs a valid stream token (`token`). Pass `$handle->stream()->streamUrl` or `$handle->stream()->toArray()` to the browser; both carry it. A signed stream URL must validate while ignoring `cursor` and `token`, because the client sets them.
6. The stream is progress, not the record. The final state arrives on `execution_terminal`, over SSE and broadcast. After a reconnect or a reload, the HTTP status route (`getExecution`) is the authority.
7. In a Laravel app with Vite (the default), import the package from `resources/js/app.js` and load it with `@vite()`. Use the script-tag build only when there is no bundler. Never copy `dist/index.global.js` into `public/`.

## Task: choose a delivery

| Preset | Modes | You hold |
|---|---|---|
| `AgenticGraph::stream($name, $state)` | `ASYNC` + `SSE` | `$handle->stream()->streamUrl`. A worker writes the store; the HTTP route reads it. |
| `AgenticGraph::stream($name, $state, inline: true)` | `SYNC` + `ITERATOR` | `$handle->stream()->inline`. Consume in this process. No queue. |
| `AgenticGraph::broadcast($name, $state)` | `ASYNC` + `BROADCAST` | `$handle->stream()->toArray()`: the private channel, the replay URL, the auth URL and the token. Reverb or Pusher. |
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
        'descriptor' => $handle->stream()->toArray(),
        'status' => $handle->status()->value,
    ]);
}
```

- Return `$handle->stream()->toArray()`: `transport` (`sse` or `broadcast`), `stream_id`, `stream_url`, `replay_url`, `auth_url`, `token`, `channel`. `createStreamTransport(descriptor)` in the stream client builds the right transport from it.
- A sync run with SSE or broadcast returns the full descriptor too.
- Status and interrupt payloads still come from HTTP (`getExecution`, `interruptDetails`). The stream is progress, not the whole handle.
- `streamUrl` already carries `execution_id`, so `replay_unavailable` brings `final_state` (see the SSE task). Send `execution_id` back as well: the client needs it to resume.

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
- `$this->llm()` streams by itself when a sink is bound, and `text()` still returns the full string. There is no special streaming node. Text, chat, structured with a Prism `Schema`, tool runs (every step) and MCP stream. Tools run between steps with no events, so a slow tool is a quiet gap. `run()->text` and the hooks' `finalText` are the final step; `run()->steps` and the hooks' `text` hold the narration; the app decides which to persist. A structured retry after a streamed attempt runs without streaming (its reply is not on the wire). `->withoutStreaming()` keeps one request off the wire (no model events, full reply returned); lifecycle and `streamEvent()` events still stream. Use it to hold an answer back until a check has run.

## Events

- **Lifecycle**, from the coordinator: `graph_start`, `graph_complete`, `graph_failed`, `node_start`, `node_complete`, `node_error`, `node_interrupt`, `stream_resume`.
- **Terminal**: `execution_terminal` (SSE and broadcast; `status` is `completed`, `interrupted` or `failed`, plus `final_state`; on broadcast the stored event with the same `seq`), `stream_close` (broadcast only, `{status, meta}`, after the terminal; an interrupt sends none), `stream_error`.
- **Broadcast `batch` frames**: text and thinking deltas within `AGENTICGRAPH_STREAM_BROADCAST_BATCH_MS` (50) go as one frame named `batch`, body `{ stream_id, execution_id, events: [{ type, ...nine keys }] }`. `BroadcastTransport` unpacks them; handlers see the single events.
- **Prism**, from `$this->llm()`: `text_start`, `text_delta`, `text_complete`, `tool_call`, `tool_result`, `thinking_*`, `stream_start`, `step_start`, `step_finish`, `stream_end`, `provider_tool_event`, `artifact`, `error`. One `stream_start` and one `stream_end` per model request (end: cumulative `usage` and `finish_reason`); one `step_start` and `step_finish` per step (finish: that step's `usage` and `finish_reason`). Each step has its own `message_id`. A run that a terminal tool ends (`terminalTools()`) sends `tool_result`, `step_finish`, then the tool's text as `text_start`, `text_delta`, `text_complete` (its own `message_id`), then `stream_end`; no `step_start` follows the tool result. With `holdStepText: true` a step's text events are sent when the step ends, before its `step_finish`, or not at all when the step called a terminal tool.
- **Yours**: any string through `streamEvent($type, $payload)`. Over SSE, add `?custom_events=wrap` to the stream URL (core 0.2.1) and they arrive as `event: custom_event` with your name in `event_type`, so a browser `EventSource` needs no list of names. Without the parameter the frames are unchanged. Stream client 0.1.1 sends it by itself and delivers them under your type; against core 0.2.0 the parameter is ignored and custom events are not delivered over SSE.
- `stream_heartbeat` and `stream_replay_unavailable` are SSE only and may change.
- The type is the SSE `event:` line or the broadcast event name. It is not in the JSON body.
- The body is frozen: `stream_id`, `execution_id`, `node_name`, `span_id`, `parent_span_id`, `seq`, `node_seq`, `occurred_at`, `payload`. `seq` is the SSE `id:` and the replay cursor.
- `node_complete` carries `node_name` and `duration_ms`. `AGENTICGRAPH_STREAM_CAPTURE_STATE` = `diff` adds `state_diff`; `full` adds `state`.

Docs: `/docs/streaming#your-own-events`, `/docs/api/streaming`

## Task: filter events

- `nodes:` and `events:` are AND filters on every emitted event. Lifecycle events are not exempt.
- `events: ['text_delta']` hides `node_interrupt`. Keep a status route, or include the event.
- `execution_terminal` is never filtered, so a client still learns that the run was interrupted.

Docs: `/docs/streaming#filters`

## Task: error details on the wire

- Since 0.2.1 the browser gets generic errors by default. `graph_failed`, `node_error`, the failed `execution_terminal` `meta` and broadcast `stream_close` `meta` carry `error_type: ExecutionFailed` and `message: Execution failed`. `stream_error` carries `StreamError` and `Stream error`. A failed `tool_result` carries `Tool failed`. `reason` (`interrupt_expired`, `stale_queued`, `stale_running`, and `skipped` since core 0.4.0) is always sent. For `skipped` it is also on the failed `execution_terminal` `meta` and on broadcast `stream_close` `meta`; the client type lists it from client 0.2.1.
- A node failed by a model provider exception shows a category instead: `rate_limited`, `provider_unavailable` or `provider_error`, with a fixed message. The Prism `error` event carries the same categories and `metadata: null`. The package picks the category from the exception type, never from the provider's text, which can hold keys, account ids or internal URLs.
- `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS=true` (default `false`) sends the real exception or provider type, the message (cut to `AGENTICGRAPH_CAPTURE_MAX_LENGTH`; `AGENTICGRAPH_CAPTURE_ERRORS=class` still removes it) and the metadata. Turn it on only for a trusted audience, such as an internal tool.
- The stored execution `error` and the logs always keep the real message. Look there, not in the browser, when a run fails.

Docs: `/docs/streaming`, `/docs/stream-limitations`

## Task: expire stale executions

- An async execution no worker picks up would leave the visitor's stream silent. `php artisan agenticgraph:executions:expire` fails it: status `failed`, `graph_failed` with `reason` `stale_queued` or `stale_running`, and a failed `execution_terminal`, so the client ends in `error`.
- Since 0.2.8 the end events (`graph_complete` or `graph_failed`, then `execution_terminal`) are written before the `GraphExecutionCompleted` and `GraphExecutionFailed` listeners run, so a slow listener or trace export does not delay the client, and the end events are always written.
- A `queued` execution older than `AGENTICGRAPH_EXECUTION_QUEUED_TIMEOUT` (300 s) is failed. A `running` one with no progress for longer than `max(AGENTICGRAPH_EXECUTION_TIMEOUT, AGENTICGRAPH_RESUME_TIMEOUT)` plus `AGENTICGRAPH_EXECUTION_STALE_GRACE` (60 s) is failed; with `AGENTICGRAPH_EXECUTION_TIMEOUT` at 0 the limit is `AGENTICGRAPH_EXECUTION_STALE_RUNNING_AFTER` (900 s). Each finished node counts as progress. Interrupted and sync executions are never touched.
- Schedule it every minute, next to `agenticgraph:interrupt:expire`:

```php
// routes/console.php
Schedule::command('agenticgraph:executions:expire')->everyMinute();
Schedule::command('agenticgraph:interrupt:expire')->everyMinute();
```

- The scheduler must run (`php artisan schedule:work`, or the `schedule:run` cron entry). Without it nothing expires.
- Expiry ends runs; it deletes nothing. `agenticgraph:executions:prune` (core 0.4.0, off by default) deletes old runs with their stored stream events; see the `agenticgraph-core` skill.

Docs: `/docs/streaming`

## Task: serve SSE

- The route is `GET /agentic-graph/streams/{streamId}`, named `agenticgraph.streams.show`. It needs `AGENTICGRAPH_STREAMING_ROUTES` on (default). Its middleware is `config('agenticgraph.streaming.routes.middleware')`, default `['api']`.
- The controller sends `retry: 2000` (`agenticgraph.streaming.sse.retry_ms`), so a client waits 2 s before it reconnects. It polls the store until `execution_terminal` or `AGENTICGRAPH_STREAM_SSE_MAX_DURATION` (120 s). At the limit it sends control `reconnect` with `after_ms` and closes.
- Reconnect from a point with `?cursor=` or `Last-Event-ID`. Control events (`heartbeat`, `reconnect`, `replay_unavailable`) carry no `id` and are never stored.
- `replay_unavailable` is sent when the cursor is older than the store keeps. It carries `cursor`. It carries `final_state` only when the request has `execution_id` (the query string) and that execution owns the stream. `streamUrl` includes it.
- `cursor=0` is the same as no cursor: it reads from the start.
- `$handle->replayFrom($cursor)` reads the store again from PHP.

**Stream token.** The route needs `token` in the query (`EventSource` cannot send headers). `streamUrl` carries it. With no token or a bad one: 403 `{"error":"stream_token_invalid"}`; expired: 403 `stream_token_expired`. `$handle->streamToken()` issues a fresh one, for a refresh endpoint. TTL `AGENTICGRAPH_STREAM_TOKEN_TTL` (3600 s). `AGENTICGRAPH_STREAM_TOKEN_REQUIRED=false` lets any request with the stream id in, as in 0.2. The key is derived from the app key; rotating the app key makes every open token invalid.

**App authorizer.** An extra check after the token, never instead of it:

```php
use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Facades\AgenticGraph;

// AppServiceProvider::boot()
AgenticGraph::authorizeStreamsUsing(function (Request $request, string $streamId, ?string $executionId): bool {
    return $executionId !== null && $request->user()?->can('view-run', $executionId) === true;
});
```

- It runs on the SSE, replay and channel auth routes. `$executionId` is the one in the valid token, else null. Return exactly `true` to pass; anything else is 403 `stream_forbidden`.

**Signed URLs.** The token replaces them. To keep a signature check, add Laravel's `signed` middleware and ignore `cursor` and `token`, because the client sets them:

```php
// config/agenticgraph.php
'streaming' => [
    'routes' => [
        'middleware' => ['api', 'signed:cursor,token'],
    ],
],
```

A signature that covers `cursor` or `token` fails on the first reconnect or refresh.

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

- The channel is private: `private-agenticgraph.stream.{streamId}` (`AGENTICGRAPH_STREAM_BROADCAST_CHANNEL_PREFIX` after `private-`). `$handle->broadcastChannel()` returns the name in use. `AGENTICGRAPH_STREAM_BROADCAST_PRIVATE=false` sends to the public name, as in 0.2.
- Channel auth: `POST /agentic-graph/streams/{streamId}/auth` (`agenticgraph.streams.auth`), form body `socket_id`, `channel_name`, `token`. It does not use `Broadcast::auth()`, so guests with a valid token work. Errors: 403 `stream_token_invalid`, `stream_token_expired`, `stream_forbidden`, `stream_channel_mismatch`; 422 `invalid_socket_id`; 501 `broadcaster_unsupported` (not Pusher or Reverb).
- Replay: `GET /agentic-graph/streams/{streamId}/events?after=N&token=T` (`agenticgraph.streams.events`). JSON `{ events, last_seq, more, gone, final_state? }`, pages of 200. No `after` (or `after=0`) reads from the start.
- Needs Reverb (or Pusher), Laravel Echo and `pusher-js`, with `BROADCAST_CONNECTION=reverb`, and a real queue: on the `sync` connection the whole run and every frame run in the web request.
- Every frame body is at most `AGENTICGRAPH_STREAM_BROADCAST_MAX_BYTES` (10000) minus 1024. A bigger frame, or one Reverb refuses with a 4xx, is skipped (`agenticgraph.broadcast.frame_skipped`); the client heals it from the replay route. A network error or a 5xx stops broadcasting for the run (`agenticgraph.broadcast.stopped`); the store has it all.
- `AGENTICGRAPH_STREAM_BROADCAST_BATCH_MS=0` sends one frame per event.
- `php artisan serve` drops process environment variables: a `BROADCAST_CONNECTION` set only in the shell does not reach the server, and channel auth answers 501. Set it in `.env`.

Docs: `/docs/stream-transports`, `/docs/stream-upgrading-to-0-3`

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
| `@agenticgraph/stream-client` | `AgenticStream`, `createStreamTransport`, `SSETransport`, `BroadcastTransport`, `streamChannelAuthorizer`, `MockTransport`, selectors. `EchoTransport` is deprecated. `laravel-echo` is an optional peer, for broadcast only. |
| `@agenticgraph/stream-react` | `useAgenticStream`, `useStreamSelector`. React 18+. |
| `@agenticgraph/stream-vue` | Same API with refs. Vue 3.4+. |

- Build the transport with `createStreamTransport(descriptor, { echo, refreshToken })` from the descriptor your start endpoint returned. It returns `SSETransport` or `BroadcastTransport`. `echo` is needed for broadcast only.
- Broadcast: set `channelAuthorization: { customHandler: streamChannelAuthorizer({ fallback }) }` on your Echo instance. Pass your own handler as `fallback` when you have other private channels.
- `refreshToken: () => Promise<string | null>` runs once on 403 `stream_token_expired`. Return a fresh token from an endpoint that calls `$handle->streamToken()`, or null to stop with `unauthorized`.
- `AgenticStream.connect()` is the only constructor. It resolves once the transport is connected, or when the transport is stopped by hand first. It rejects if the transport reports `failed`, or stops for another reason (for example `unauthorized`), first.
- Rule 3: `SSETransport` reconnects by itself. A transient drop keeps `status` where it was. After `maxAttempts` (5) failed retries it reports `failed`: `status` is `error` with `error.kind` `connection` and `error.canReconnect` `true` (a lost connection only; `false` for the other kinds and when the first connect fails). Call `stream.reconnect()`, or `reconnect()` from `useAgenticStream` (client 0.1.1): it resumes from the last event, `status` goes back to `streaming`, `error` clears and the text is kept. Or make a new transport.
- Client 0.1.1 with core 0.2.1: custom events arrive over SSE (the client sends `?custom_events=wrap`), and `execution_terminal` handlers run before `status` changes. `error.kind` `terminal_error` shows `meta.message` and `error_type`, which is generic or a provider category (`rate_limited`, `provider_unavailable`, `provider_error`) unless the server sets `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS`. Do not show `error_type` to a visitor as a diagnosis.
- Rule 4: `resolveUrl(lastSeq)` runs before each reconnect. Return a fresh URL, or `null` to end the stream. Return `null` on purpose: the transport stops with reason `resolver`, and that is not an error. A throw counts as one failed attempt.
- `transport.getStopReason()`: `terminal` (execution completed or failed), `replay_unavailable` (cursor too old; `error.kind` is `replay_unavailable`), `resolver` (`null` from `resolveUrl` or `resolveReplayUrl`), `manual` (`disconnect()`), `failed` (gave up), `unauthorized` (a 403 that `refreshToken` did not fix, or channel auth failed). `null` while running. An exhaustive TypeScript `switch` must handle `unauthorized`.
- `status`: `idle`, `streaming`, `complete`, `interrupted`, `error`. `error.kind`: `connection`, `stream_error`, `terminal_error`, `replay_unavailable`.
- `interrupt` has `checkpointId`, `reason`, `node` and `payload`. Your `getInterruptPayload()` is at `payload.interrupt_payload`.
- `BroadcastTransport` waits for the confirmed subscription, replays from the start, merges by `seq` and drops duplicates. A hole replays once (debounced 250 ms); no frame for `idleMs` (5000) replays with backoff, and every 30 s while interrupted. `resolveReplayUrl(cursor)` and `maxAttempts` work as on SSE.
- `EchoTransport` (deprecated) works only with `AGENTICGRAPH_STREAM_BROADCAST_PRIVATE=false` and `AGENTICGRAPH_STREAM_BROADCAST_BATCH_MS=0`, and loses the gap during a drop.
- One connect per transport. With the hooks, the hook owns the connection. Never also call `AgenticStream.connect()` on the same transport. `disconnect()` stops the transport only for the stream that connected last on it.
- The hook's `onResume` posts to your Laravel resume route. Send the execution id from your start response; `AgenticGraph::resume()` takes the execution id, not the checkpoint id.
- Not a chat framework: no message history or threads.
- Full React and Vue examples: [references/clients.md](references/clients.md).

Docs: `/docs/stream-getting-started`, `/docs/stream-core`, `/docs/stream-react`, `/docs/stream-vue`, `/docs/stream-limitations`

### Script-tag build (only when there is no bundler)

With Vite, use `import { AgenticStream, createStreamTransport } from '@agenticgraph/stream-client'` in `resources/js/app.js` and `@vite('resources/js/app.js')` in the Blade view. Without a bundler, load the pinned CDN build, `https://cdn.jsdelivr.net/npm/@agenticgraph/stream-client@<version>/dist/index.global.js`. It sets `window.AgenticGraphStream`, with the same exports as the module build:

```html
<script src="https://cdn.jsdelivr.net/npm/@agenticgraph/stream-client@0.2.2/dist/index.global.js"></script>
<script>
  const { AgenticStream, createStreamTransport } = window.AgenticGraphStream;
  AgenticStream.connect({ transport: createStreamTransport(descriptor) });
</script>
```

It is one file, with no `laravel-echo` inside.

## Task: test streams

- Inline iterator: `AgenticGraph::stream(..., inline: true)` needs no Redis, Reverb or worker.
- Client tests: `MockTransport` with `emitSequence()`, `simulateDrop()` and `simulateFailure()` (a transport that gave up: the `error` state and `reconnect()`). It does not reconnect; test `SSETransport` reconnect with an injected `eventSourceFactory`.
- Assert the server side with `AgenticGraph::stream()` and the `stream_events` table in a test database.

Docs: `/docs/guide-testing`

## Mistakes

1. `stream()` without `inline` is async. Without a worker the EventSource stays quiet.
2. Broadcast on the `sync` queue connection: the whole run holds the web request. Run a real queue.
3. Filtering to `text_delta` and waiting for `node_interrupt`.
4. `foreach ($handle->stream() as $event)`. Iterate `->inline`.
5. Two `connect()` calls on one transport, or a retry loop around `SSETransport`.
6. `streamEvent()` in a graph started with `run()`; nothing is emitted.
7. A stream URL built by hand without `token`, or a signed URL that covers `cursor` or `token`: every request or reconnect gets a 403.
8. Expecting the exception message in the browser. It is generic unless `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS=true`; read the stored execution `error` or the logs.
9. `agenticgraph:executions:expire` not scheduled, so a run without a worker never ends and the stream stays quiet.
10. Custom events with a raw `EventSource` and no `?custom_events=wrap`: they arrive under your own event names, which `onmessage` never sees.

Docs: `/docs/streaming`, `/docs/stream-limitations`
