# Changelog

## 0.4.3 - 2026-11-29

A run-ending event listener that throws no longer hides an error, changes an ended run or leaves a worker in a bad state. No migration, config or public signature change. The run-ending events are `GraphExecutionCompleted`, `GraphExecutionFailed`, `GraphExecutionSkipped` and `InterruptExpired`.

### Fixed

- **Wrong results after a failure listener threw: a later run could get another run's graph or loop counts.** The run's scope bindings stayed on the worker (or in the process of a sync caller). The next run then used the old graph, or continued the old visit and iteration counts. Both coordinators now clean up in a `finally`, and all four graph jobs remove their scope bindings at the end of every job, on every path. A job that runs inside another run on the `sync` queue now also leaves that run its bindings. A successful job did not leak.
- **A `GraphExecutionStarted` listener that throws in a non-streaming sync `execute()` no longer leaves the run's scope bindings behind.** The next run in that process could get the old graph or old loop counts. The bindings are now as they were before the call, also when the run cannot be stored as started.
- **A failed sync resume resolves its interrupt as `failed`,** as a failed queued resume does, before the failure listeners run, with `resume_error` and `last_error`. The interrupt stayed `pending` on a failed run. A sync resume still does not call the interrupt handler. A sync resume that throws before the run starts still leaves the interrupt `pending`, so you can resume again.
- **A job's `failed()` that writes the end frames of a skipped run again gives them the reason `skipped`,** as the skip does. They carried the job's own error with no reason.
- **A `GraphExecutionCompleted` listener that throws is no longer lost.** In a queued job it was logged as "execution already ended" and the job succeeded.
- **A resumed run that completed has its interrupt resolved before the completion listeners run.** With a listener that threw, a queued resume left the interrupt `resolving` without calling the handler's `resolved()`, and a sync resume left it `pending` on a completed run, where `agenticgraph:interrupt:expire` could later fire `InterruptExpired`.
- **A queued resume never sets the interrupt of an ended run back to `pending`,** also when the interrupt handler's `resolved()` throws.
- **`failed()` of a job whose run completed writes no failed frames and does not resolve the interrupt as `failed`,** for example after a timeout inside a completion listener.
- **A completion frame that fails no longer drops `GraphExecutionCompleted`.** When `graph_complete` or the terminal throws (a fatal stream error), the other one is still written, the event fires, and the job fails with the frame error. Before, the event never fired and the job succeeded.
- **A stream store that is down in a job's `failed()` or in a skip no longer drops `GraphExecutionFailed` or `GraphExecutionSkipped`.** The event fires once, then the store error is thrown.
- **A parallel batch failure writes its `Batch execution failed` log line before the event,** and sends its trace segment when a listener throws.
- **Every error of a run-ending event listener is logged** as `AgenticGraph: run-ending event listener failed`, with `execution_id`, `graph_name`, `event`, `error_type` and `message`. It uses the default logger and is written also with `logging.enabled` off. Before, an error that Laravel does not report, such as `ModelNotFoundException`, left no trace in the expire commands.

### Behavior changes

- **A queued job with a completion listener that throws now fails.** Up to 0.4.2 it succeeded silently, so some apps may see new failed jobs. A completion or skip listener error fails the job at once through `$this->fail()`, so it is recorded also when the job has tries left. The run keeps its status.
- **A sync caller gets the listener error on completion,** not `ExecutionEndedException`.
- **On a run failure the caller gets the run's own error,** not the listener error, which is passed to `report()`. A failed queued resume stores that error in `last_error` and `resume_error`, and gives it to the handler's `failed()`. The interrupt is now already resolved as `failed` when the failure listeners and the handler run.
- **A job's `failed()` reports a `GraphExecutionFailed` listener error and no longer throws it.** The job keeps its own error.

### Known limitations

- Laravel stops at the first listener that throws, so the later listeners of that event do not run.
- If the process dies after a run's end commits and before its stream write or event, nothing repairs it yet (see 0.4.2). A repair is planned for 0.5.

## 0.4.2 - 2026-11-28

The end of a run is now stored before your listeners run. No migration, config or API change. Stream client 0.2.x works unchanged.

### Fixed

- **A listener that throws no longer keeps a stale run open.** `agenticgraph:executions:expire` fired `GraphExecutionFailed` inside the transaction that failed the run. A listener that threw rolled it back: the run stayed `queued` or `running`, and the next pass did the same again. A broadcast client got the end frames each time, and with the Redis stream store the stored stream ended while the row did not. Such a run also kept its `--limit` slot, and when the listener threw for every run, none expired.
- **The same for `agenticgraph:interrupt:expire`**, with a `GraphExecutionFailed` or `InterruptExpired` listener: the interrupt stayed `pending` and its run `interrupted`.
- **A failed resume job now resolves its interrupt when a `GraphExecutionFailed` listener throws.** The interrupt stayed `pending` or `resolving` next to a failed run.
- **A skipped resume stays skipped when a `GraphExecutionSkipped` listener throws.** The interrupt was resolved as `failed`, and the interrupt handler's `failed()` was called. It is now resolved as `skipped`, and the handler is not called.
- **A streaming run that fails during setup stores the failed row first.** It wrote the stream frames and fired `GraphExecutionFailed` before the row update, so a listener that threw left the run `running` with an ended stream.
- **`ExecutionRepository::failed()` cannot overwrite a run that ended after it read the row.** The update is now conditional too, for a database that ignores row locks.

### Behavior changes

- **A listener exception no longer aborts or undoes the end of a run.** In the two expire commands it is caught and passed to `report()` once, the run counts as expired, and the command goes on with the next one. In a job it is as in 0.4.1: the job fails, the run stays ended, and the event does not fire again. `report()` follows your exception handler, and Laravel does not report some exceptions by default, for example `ModelNotFoundException`.
- **The expiry events fire after the commit,** with the row stored, the stream frames written and no transaction of the command open. A listener that wrote to the database was rolled back with a failed expiry before; its writes now commit on their own. An event is not sent again by a later pass.
- **A stream store failure during an expiry no longer rolls it back.** It is reported, the run stays failed and `GraphExecutionFailed` still fires. A later pass does not write the missing frames.
- **`agenticgraph:interrupt:expire` writes no stream frames and fires no `GraphExecutionFailed` for a run that had already ended.** The interrupt expires and `InterruptExpired` fires, as before.

### Known limitations

- A `GraphExecutionCompleted` listener that throws in a queued job is not reported: the job succeeds and the run stays `completed`. During a resume the interrupt also stays `resolving`. As before; catch and report inside such a listener. Fixed in 0.4.3.
- If the process dies after an expiry commits and before its stream write, the run is `failed`, the stream has no end and `GraphExecutionFailed` is not sent. An SSE client keeps reconnecting and a broadcast client keeps replaying. No later pass repairs this yet.

## 0.4.1 - 2026-11-27

An app can now react when `skipQueuedRunsUsing()` skips a queued run. Nothing else changes. Stream client 0.2.x and the 0.4.0 upgrade steps are unchanged.

### Added

- **`GraphExecutionSkipped(executionId, graphName, reason)`.** Fires once for a run that `skipQueuedRunsUsing()` skipped, from the job call that changed the row to `failed` with `ended_by=skip`. It fires for all four graph jobs, so for execute, continue, resume and a parallel branch. It fires after the row update and the stream frames, like `GraphExecutionFailed`, and a listener that throws fails the job in the same way. The row stays skipped, and the event does not fire again on a retry. `reason` is the string your callback returned. A call that does not change the row fires nothing: a run that has already ended, a sibling branch that lost the race, or a failed run that a recovery job marks as skipped. The event is a plain Laravel event. It is not broadcast, and the reason does not reach the stream or the browser. `GraphExecutionFailed` still does not fire for a skip.

## 0.4.0 - 2026-11-27

A queued run that you delete stays deleted, an app can skip a queued run before it runs, old runs can be pruned, and a structured reply without a required key is no longer accepted. This is a breaking release. Stream client 0.2.x works with it. Client 0.2.1 adds `skipped` to the `GraphFailedReason` type; 0.2.0 already passes the value through.

### Added

- **`AgenticGraph::skipQueuedRunsUsing(fn (StateContract $state, string $graphName, string $executionId): ?string)`.** All four graph jobs (`ExecuteGraphJob`, `ContinueExecutionJob`, `ResumeExecutionJob`, `ExecuteNodeJob`) ask it before they run, after they know the run exists and has not ended. Null runs the job; a string is the reason to skip it. Any other value throws `UnexpectedValueException`. If the callback throws, the job fails as usual. It runs on the worker, with no request or tenant bound. A resume job carries only the interrupt id, so the callback gets the state of the interrupt checkpoint. Synchronous runs do not ask it. Null removes it.
- **`agenticgraph:executions:prune {--days=} {--limit=500}`.** Deletes `completed` and `failed` runs older than the retention limit, with their stream events, interrupts, batch results, checkpoints (through `StorageInterface`, so Redis and file storage too) and, last, the run row. A run's age is `completed_at`, else `updated_at`. Each run is deleted under its row lock, and only if it still qualifies. A table the app does not have is skipped. `--limit` is the most runs one pass deletes. A run that fails to delete stays, is logged once in the pass with its id and the error, does not count toward `--limit`, and the command exits with a failure.
- **Config key `execution.retention_days`** (`AGENTICGRAPH_EXECUTION_RETENTION_DAYS`). Null or 0, the default, is off. `--days` wins over it. A value that is not a whole number of 1 or more fails the command and deletes nothing.
- **Required-key check for structured replies.** The core checks that every key the schema requires is in the reply, also in nested objects and array items. Prism does not check a reply against its schema. A cached reply gets the same check; one that fails it counts as a cache miss, and the new reply replaces it.

### Behavior changes

- **A deleted run stays deleted and runs nothing.** A queued job whose run row is gone logs and returns: no node runs, and no row, stream event, checkpoint or batch result is written. A job's `failed()`, batch completion and `agenticgraph:interrupt:expire` write no stream events for it, and fire no `GraphExecutionFailed` or `InterruptExpired`. Up to 0.3.0 the job could run the node and create the row again. A run deleted while a worker runs it stops the same way: the worker checks the row, on the write connection, before each node and again when the node returns or throws. No further node, retry, checkpoint, batch result, stream event, `NodeExecutionFailed` or `GraphExecutionFailed` follows, and the job does not fail. A queued run makes these extra reads of its row. Synchronous runs are not checked and still create their rows.
- **The first dispatch waits for the commit.** The first job of an async run is dispatched with `afterCommit()`. Inside a database transaction it reaches the queue after the commit, and never after a rollback.
- **A skip ends a run as `failed`, with `ended_by=skip`.** The row's error is `Skipped: {reason}`. A streamed run gets the usual `graph_failed`, failed `execution_terminal` and, on broadcast, `stream_close`, each with `reason` `skipped`. Your reason text is not sent to the browser. There is no `GraphExecutionFailed` and no failed job, only a log line. A worker or recovery job cannot reopen the run. A skipped resume resolves the interrupt with outcome `skipped` and does not call the interrupt handler. `getStats()` and `isFailed()` count a skip as a failure.
- **A structured reply that misses a required key is retried, then throws.** The retry sends the failed reply and the key's path (`items.2.price`) back to the model, up to `prism.structured.retries` (default 1), then throws `StructuredHydrationException`. A call that succeeded in 0.3.0 can now cost more or throw. `PrismRouter` is unchanged: it still checks `route` itself.

### Removed

- **`ExecutionRepository::cleanup()`.** `agenticgraph:executions:prune` is the only way to delete runs. The package never called `cleanup()`, and it filtered on `completed_at`, which a normally failed run does not have.

### Fixed

- **A queued job no longer brings a deleted run back**, streamed or not.
- **A run deleted while a worker runs it stops.** The job threw on the foreign key with database checkpoints. With other checkpoint storage the run went on to the next nodes.
- **No frames for a deleted run.** A failed job wrote stream events for a run that no longer existed.
- **A parallel branch job of an ended run does nothing.** It checked only whether its batch was cancelled, so after something outside the batch ended the run (for example `agenticgraph:executions:expire`), the branches still in the queue ran their nodes.
- **A batch that finishes after its run ended returns quietly**, in place of reporting its missing results as an error.

### Upgrade

Do these steps in order. The full guide is [Upgrading to 0.4](https://agenticgraph.dev/docs/upgrading-to-0-4). From 0.2.x, upgrade once, to `^0.4`: do the 0.3.0 steps below (stream token, private channel, stream client 0.2.0) in the same upgrade.

1. `composer require agenticgraph/agenticgraph:^0.4`.
2. Published config: an app that published `config/agenticgraph.php` must add `retention_days` to the `execution` block, or `AGENTICGRAPH_EXECUTION_RETENTION_DAYS` has no effect (`--days` still works).

```php
'execution' => [
    // ...
    'retention_days' => env('AGENTICGRAPH_EXECUTION_RETENTION_DAYS'),
],
```

3. Publish the migrations again and run them. 0.4.0 adds no migration; this brings an app that comes from an older version up to date.

```bash
php artisan vendor:publish --tag=agenticgraph-migrations
php artisan migrate
```

4. Restart the queue workers (`php artisan queue:restart`).
5. Jobs built by hand need a queued row. If your code or tests construct a graph job and dispatch or call it, write the row first: `app(ExecutionRepository::class)->queued($executionId, $graphName)`. Without it the job does nothing. `AgenticGraph::execute()` and `dispatch()` already write it.
6. Structured replies: a partial `Llm::fake()` reply now fails, so give every required key in your fakes. In your schemas, take out of `requiredFields` any key the model may leave out. A nullable key that stays required must be present, and may be null.
7. If you called `ExecutionRepository::cleanup()`, use the prune command.
8. To prune, set `AGENTICGRAPH_EXECUTION_RETENTION_DAYS` and schedule the command. It is off until you do.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('agenticgraph:executions:prune')->daily();
```

### Known limits

- The required-key check is for presence only. It does not check types or enum values, does not reject extra keys, and does not follow `anyOf` or `$ref`.
- A node that is already running when its run is deleted finishes its own work, including what it writes or streams itself; nothing runs after it. What the run stored before the delete stays unless the database removes it with the row. Prune does not find data whose run row is gone.
- `agenticgraph:executions:prune` leaves Redis stream events to their TTL. When every delete fails (for example the checkpoint storage is down), one pass tries and logs every due run once.
- `agenticgraph:executions:expire` does not ask the skip callback: a run no worker picks up still expires as a failure. A skipped resume or continuation can leave its trace open.

## 0.3.0 - 2026-11-25

The broadcast path keeps the SSE contract: the same events, in the same order, with no loss and no duplicates, after a drop, a reconnect and a late join. The channel is private. Use stream client 0.2.0 (`BroadcastTransport`) with it.

### Added

- **The terminal is broadcast.** The stored `execution_terminal` (the same body and the same `seq` as in the store) goes on the channel before `stream_close`, on every path that ends a run: the normal end, a failed job, `agenticgraph:interrupt:expire` and `agenticgraph:executions:expire`.
- **Replay route.** `GET {prefix}/{streamId}/events?after=N&token=T`, named `agenticgraph.streams.events`. JSON `{ events, last_seq, more, gone }`, pages of 200, and `final_state` with `gone` when the execution owns the stream. Same cursor rules as the SSE route, from the same controller code. One read, never a stream, so it holds no worker. A broadcast client heals a drop or a late join with it.
- **Channel auth route.** `POST {prefix}/{streamId}/auth`, named `agenticgraph.streams.auth`, body `socket_id`, `channel_name`, `token`. It signs the stream's private channel with the Pusher client of the app's broadcaster (Pusher or Reverb). It does not use `Broadcast::auth()`, so a guest with a valid token needs no Laravel user.
- **Stream token.** `Streaming\StreamToken` (`issue()`, `verify()`): an HMAC-SHA256 token over the stream id, the execution id and an expiry, with a key derived from the app key, never the raw key. `$handle->stream()->streamUrl` carries it, and `$handle->streamToken()` issues a fresh one (for a refresh endpoint). TTL `streaming.token.ttl`, 3600 s.
- **`AgenticGraph::authorizeStreamsUsing(fn (Request $request, string $streamId, ?string $executionId): bool)`**, an app check on the three stream routes after the token check. Null removes it.
- **`StreamDescriptor::toArray()`**: `transport` (`sse` or `broadcast`), `stream_id`, `stream_url`, `replay_url`, `auth_url`, `token`, `channel`. Pass `$handle->stream()->toArray()` to the browser; `createStreamTransport()` in the stream client builds the transport from it.
- **Delta batching on broadcast.** `text_delta` and `thinking_delta` within `streaming.broadcast.batch_ms` (50) go as one `batch` frame; each inner event keeps its own nine-key body and `seq`. Any other event, the size limit and the close flush the batch first. An event the `nodes` or `events` filter drops is never sent, but one that is not a delta still flushes the batch, so the tail of a step does not wait for the next delta. The store still gets one row per delta. `batch_ms = 0` sends one frame per event.
- Config keys `streaming.token.required`, `streaming.token.ttl`, `streaming.broadcast.private` and `streaming.broadcast.batch_ms`.

### Behavior changes

- **The three stream routes deny without a valid token.** The SSE route, the replay route and the channel auth route return 403 `stream_token_invalid` with no token or a bad one, and 403 `stream_token_expired` with an expired one. An app that passes `$handle->stream()->streamUrl` needs no change. An app that builds the URL with `route('agenticgraph.streams.show', ...)` or signs its own URL must add `token`.
- **One token source per route.** The SSE and replay routes read `token` from the query only (`EventSource` cannot send headers). The channel auth route reads it from the POST body only. A token in another place counts as no token.
- **The authorizer gets only the token's execution id**, or null without a valid token. An `execution_id` query value is used only by the ownership check for `final_state`, never passed to the authorizer. The authorizer passes only when it returns exactly `true`; any other value returns 403 `stream_forbidden`.
- **The broadcast channel is private:** `private-agenticgraph.stream.{streamId}`. `$handle->broadcastChannel()` returns the name in use. `EchoTransport` 0.1 subscribes to the public name and gets nothing.
- **Deltas arrive in `batch` frames.** `EchoTransport` 0.1 drops them.
- **The stored terminal is broadcast before `stream_close`.** A handler for `execution_terminal` now runs on broadcast too.
- **An interrupt sends no `stream_close`.** The interrupted terminal is broadcast, and the resumed segment continues on the same channel.
- **One size limit for every frame:** `max_bytes` minus 1024 bytes for the request line and headers, for a single frame and a batch. Reverb's `max_request_size` counts the whole request. In 0.2.12 a single frame could use the full `max_bytes`, so a body between the two got a 413 from Reverb and stopped broadcasting for the run.
- **A 4xx from Pusher or Reverb skips that frame, and the sink continues.** The frame is logged as `agenticgraph.broadcast.frame_skipped` with `code`, and the replay heals it. A network error (code 0), a 5xx or any other error still stops broadcasting for the run (`agenticgraph.broadcast.stopped`).
- **`after=0` and `cursor=0` read from the start**, also when an event filter left the first stored seq above 1. Before, `cursor=0` on such a stream was answered with `replay_unavailable`.
- **A sync SSE or broadcast run returns the full descriptor.** `$handle->stream()` after a sync run with SSE or broadcast now has the stream id, the URLs, the token and the channel. Before, a sync run had no stream id and no URL.
- **The way back**, one key at a time: `AGENTICGRAPH_STREAM_TOKEN_REQUIRED=false` lets any request with the stream id in, as in 0.2 (the authorizer still runs if set). `AGENTICGRAPH_STREAM_BROADCAST_PRIVATE=false` and `AGENTICGRAPH_STREAM_BROADCAST_BATCH_MS=0` keep `EchoTransport` 0.1 working. The first two open the stream to anyone who has its id.

### Fixed

- **The failed and expiry paths use the timeout connection and `max_bytes`.** A failed job, `agenticgraph:interrupt:expire` and `agenticgraph:executions:expire` built their broadcast sink with `app(BroadcastSink::class)`, so they skipped the 2 s `streaming.broadcast.timeout` connection. They now use `StreamSinkFactory::broadcastSink()`, like the normal end, with the same size limit and status policy, and a stop logs `agenticgraph.broadcast.stopped`.
- **With `streaming.token.required=false` and no app key, no exception.** `StreamToken::issue()` returns null, `$handle->stream()` builds the URLs without `token`, and the three routes work with no token. With `required` true and no app key, `MissingAppKeyException` is thrown as before.

### Upgrade

Do these steps in order. The full guide is [Upgrading to 0.3](https://agenticgraph.dev/docs/stream-upgrading-to-0-3).

1. Pass the stream URL from the handle. `$handle->stream()->streamUrl` now carries a stream token. If you build the URL yourself, add `token=$handle->streamToken()`. Without it the route returns 403. If your middleware signs the URL, ignore `token` in the signature check, or sign after you add it.
2. Broadcast users: update `@agenticgraph/stream-client` to 0.2.0. Replace `EchoTransport` with `createStreamTransport(descriptor, { echo })`. Send the descriptor to the browser with `$handle->stream()->toArray()`. Add `streamChannelAuthorizer()` to your Echo `channelAuthorization`, and pass your own handler as `fallback` if you have other private channels.
3. Long sessions: give the transport a `refreshToken` callback that asks your app for `$handle->streamToken()`.
4. Published config: an app that published `config/agenticgraph.php` must add the `token` block and the `private` and `batch_ms` keys, or the environment values have no effect; the defaults (true, 3600, true, 50) apply.

```php
'streaming' => [
    'broadcast' => [
        // ...
        'private' => env('AGENTICGRAPH_STREAM_BROADCAST_PRIVATE', true),
        'batch_ms' => env('AGENTICGRAPH_STREAM_BROADCAST_BATCH_MS', 50),
    ],
    // ...
    'token' => [
        'required' => env('AGENTICGRAPH_STREAM_TOKEN_REQUIRED', true),
        'ttl' => env('AGENTICGRAPH_STREAM_TOKEN_TTL', 3600),
    ],
],
```

5. Queue the run. `broadcast()` frees the web worker only when the queue connection is not `sync`.
6. The way back for 0.3.x: `AGENTICGRAPH_STREAM_TOKEN_REQUIRED=false` restores the 0.2 SSE route. `AGENTICGRAPH_STREAM_BROADCAST_PRIVATE=false` and `AGENTICGRAPH_STREAM_BROADCAST_BATCH_MS=0` keep `EchoTransport` 0.1 working. The first two open the stream to anyone who has its id.

### Measured

- Measured on local Reverb, 500 deltas, interleaved, 10 runs each (medians): Reverb calls 517 to 43 and 512 to 39; time inside Reverb calls 310 to 96 ms and 372 to 23 ms; whole run 1345 to 402 ms (workflow-agents); /ping p95 with 50 broadcast viewers 19 ms on Octane and 34 ms on FPM, against 19 s on SSE. Local Reverb has no network distance, so real per-call cost is higher.

### Known limits

- During a model pause the open batch waits: PHP has no timer in the token loop, so the window closes at the next delta. A tool call, the end of a step, any other event and the close flush it.
- The token is in the query string on the SSE and replay routes, so it can appear in access logs. It is scoped to one stream and expires.
- Rotating the app key makes every open token invalid. Clients recover through `refreshToken`.

## 0.2.12 - 2026-11-18

### Fixed

- **SSE works on Octane with Swoole.** The stream controller ended every output buffer before the read loop, Octane's own buffer too (the handler that writes to the Swoole response). Every event then went to the worker's stdout, and Octane's `ob_end_clean()` threw, so every stream request returned 500. The controller now ends only PHP's own buffers (`default output handler`, from `output_buffering`, and `zlib output compression`) and keeps a handler that the server or the app owns. Tested live on Octane 2.25 with Swoole 6.2 (8 workers): events arrive 2 ms (p50) after the model sends them, a reconnect in the middle replays exactly (5 of 5, seq gap-free), 100 of 100 runs complete. A buffer the app started with a plain `ob_start()` has the same name as PHP's and is still ended. On Octane, set `octane.max_execution_time` at or above `streaming.sse.max_duration_seconds` (120), or Octane kills the worker at 30 s; each open stream holds one worker, as on PHP-FPM.
- **A broadcast error no longer fails the run.** `AgenticGraph::broadcast()` sent each event with a synchronous Pusher or Reverb call, and any error was a fatal transport error (`errors.transport_fatal`). One tool result over 10 KB failed the run with "Payload too large" (Reverb's `max_request_size` is 10,000 bytes), and with Reverb down the first event stalled 10 s or more and the run failed. Now a frame whose Pusher request body is over `streaming.broadcast.max_bytes` (10000) is skipped and logged (`agenticgraph.broadcast.frame_skipped`). After the first failed call the run stops broadcasting and carries on, with one warning (`agenticgraph.broadcast.stopped`; the URL query, which holds the app key, is left out of the message). A Pusher or Reverb connection used for stream frames has an HTTP timeout of `streaming.broadcast.timeout` (2 s, connect included), unless the app set one in `client_options` or `options`. The store is written as before, every event and the `execution_terminal`. Measured live with Reverb unreachable: the run completed in 2.4 s; before, it threw after 50 s.
- **Stream ids are random and have no dot.** They came from `uniqid('stream_', true)`: time-based, so guessable, and with a dot, which a Laravel channel parameter (`agenticgraph.stream.{streamId}`) never matches. They are now `stream_` and 32 hex digits from `random_bytes(16)`, minted in one place (`StreamSession::newId()`). Nothing in the core or the stream client parses a stream id; it is compared for equality only, so ids already stored keep working.

### Behavior changes

- **A broadcast failure no longer fails the run.** The run completes and the store is written in full; only the broadcast is affected.
- **Frames over `streaming.broadcast.max_bytes` (10000) are not sent.** The size is the Pusher request body. The event is still in the store.
- **Broadcasting stops for the run after the first failure.** One failed call, of any kind, ends broadcasting for that run; the next run tries again.
- **The stream frame timeout is 2 s** (`streaming.broadcast.timeout`, connect included) for a Pusher or Reverb connection, unless the app set its own in `client_options` or `options`. Before, Laravel's defaults applied: 10 s connect, 30 s total.
- **The stream id format changed** from `stream_6afcbe0aa7a187.72088730` (time-based, with a dot) to `stream_` and 32 hex digits. Code that matched the old format must change; the core and the stream client treat the id as an opaque string.
- **The way back:** `AGENTICGRAPH_STREAM_BROADCAST_FATAL=true` sends every frame with the app's own timeouts, and an error fails the run, as up to 0.2.11. The stream id format has no way back.

### Upgrade

- An app that published `config/agenticgraph.php` must add `streaming.broadcast.fatal`, `max_bytes` and `timeout` to it, or the environment values (`AGENTICGRAPH_STREAM_BROADCAST_FATAL`, `AGENTICGRAPH_STREAM_BROADCAST_MAX_BYTES`, `AGENTICGRAPH_STREAM_BROADCAST_TIMEOUT`) have no effect; the defaults (false, 10000, 2) apply.

```php
'broadcast' => [
    // ...
    'fatal' => env('AGENTICGRAPH_STREAM_BROADCAST_FATAL', false),
    'max_bytes' => env('AGENTICGRAPH_STREAM_BROADCAST_MAX_BYTES', 10000),
    'timeout' => env('AGENTICGRAPH_STREAM_BROADCAST_TIMEOUT', 2),
],
```

### Known limits

- The broadcast path does not yet keep the full SSE contract: no replay after a reconnect, no `execution_terminal` on the channel, and a public channel. A later minor version addresses it. Use SSE where a client must not miss events.

## 0.2.11 - 2026-11-17

### Added

- **`withoutStreaming()` on `LlmRequest`.** A node can keep one model call off the stream: no `text_delta` or other model events for it, and `text()` or `run()` returns the full reply. The run still streams its lifecycle events and the node's `streamEvent()` calls. Works for text, structured and tool requests; `explain()['stream']` is `false`. Before, `LlmCall::$stream` existed but `LlmRequest` never set it, so an answer could not be held back until a check had run. The name follows `withoutCache()`, the other per-call switch for a default the run decides.

### Behavior changes

- **`Llm::fake()` runs tool handlers.** A queued `TextResponseFake` with tool calls, for a request with tools, now runs each handler, sends the results back as the tool result message and takes the next queued response, until a response has no tool calls or `maxSteps` is reached, as a provider loop does. Before, the tool call was returned as the final result and no handler ran. Several calls in one step run in order; a handler that throws gives its error as the tool result. Streamed runs get `tool_call` and `tool_result` per step, and a terminal tool ends the run before the next response. A `TextResponseFake` with its own steps or tool results passes through as before. A test that queued a tool call for a request with tools now runs the handler: at the default one step no next response is taken, but the handler's side effects happen.
  - **The way back:** `Llm::fake($responses, runTools: false)` never runs handlers, as up to 0.2.10.

## 0.2.10 - 2026-11-17

### Fixed

- **The stream route no longer rolls back an open transaction.** Since 0.2.9 the SSE controller closed every database connection before the read loop (except in-memory SQLite). Closing a connection with an open transaction rolls the transaction back, and nothing reports it. An app whose tests run on Postgres inside `RefreshDatabase` found no rows in every read after a stream request (3 tests failed). A request that a middleware wraps in a transaction lost its writes the same way. Core's own suite runs on in-memory SQLite, which the release skips, so it did not see this. The controller now keeps a connection with `transactionLevel() > 0` and logs one debug line, `agenticgraph.stream.connection_kept`, with the connection name. That connection stays open during the read loop. A connection without a transaction is still closed. Tested on a SQLite file: the row written in the transaction was lost before, and is kept now. The 0.2.9 live test (20 open streams on Postgres, 0 connections during the loop) still passes.

## 0.2.9 - 2026-11-16

### Fixed

- **An open SSE stream no longer holds a database connection.** The stream request runs its connect-time checks (route middleware such as a signature or execution check, the replay lookup), then waited in the read loop for up to `streaming.sse.max_duration_seconds` (default 120) with that connection still open. In a load test on Postgres behind PHP-FPM, 50 open streams filled `max_connections` (100) and a stream request failed with "too many clients", while at most 3 connections were active. The controller now closes every open database connection before the read loop. The loop (heartbeat, `stream_reconnect` close, `redis` store reads) uses no database; a later query reconnects. Measured live on Postgres with 20 open streams: 20 connections in `pg_stat_activity` during the loop, now 0. With `STREAM_STORE_DRIVER=database` the loop reads the database and reconnects on its first read, so each stream still holds one connection. A persistent connection (`PDO::ATTR_PERSISTENT`) stays open in the PHP process. An in-memory SQLite connection is kept, because closing it erases the database.
- **The Pulse trace export no longer holds the queue worker after the end event.** Since 0.2.8 the end event goes out first, but the final segment was still posted inline (`transport.timeout` 3 s, 2 retries), so a slow trace endpoint kept the worker busy for up to about 10 s per run. With a real queue (not `sync`), `PulseExporter` now dispatches each segment as a `SendPulseSegmentJob` (no delay) on `tracing.transport.queue_connection` and `queue_name`. The job payload holds the encoded segment as posted, the trace id and the segment id; the endpoint and API key are read from config when it runs. Captured state, prompts and responses sit in the queue store until the job runs. The job keeps the 429 and `Retry-After` handling (release for the `Retry-After` value, at most `max_delay`). A failed dispatch logs `code: dispatch_failed` and the segment is posted in process. On the `sync` queue the segment is still posted in process after the end event. Measured live with an ingest that holds each request 8 s: the worker was free 7.96 s after `execution_terminal`, now at once; the segment job delivers the segment. A worker must listen on the trace queue. The `otlp`, `file` and `log` exporters are not changed.

## 0.2.8 - 2026-11-16

### Fixed

- **A streamed run that completes or fails always writes its end events.** On a queued streamed run, when the completion listeners (for example a trace export that timed out and retried) took more than 10 s after the last heartbeat, the heartbeat on `graph_complete` called `touch()`. The run was already marked `completed`, so `touch()` returned false, the sink took it as "ended elsewhere" and threw. `graph_complete` and `execution_terminal` were never written, the client kept the stream open until it timed out, and the log showed "worker stopped, execution already ended". The failed path had the same problem with `graph_failed`. The heartbeat now skips `graph_complete` and `graph_failed`: the coordinator emits them only after its own conditional status write (`completed()` or `failed()`) ended the run, and that write is the check that this worker owns the end. A `touch()` before it is not needed, and after it, it is always false.
- **The end event is no longer delayed by the completion listeners.** On a streamed run, `GraphExecutionCompleted` and `GraphExecutionFailed` are now dispatched after the end events and the close. The tracing listener sends the final segment inline (a plain HTTP post with `transport.timeout` and 2 retries), so a slow trace endpoint added its timeout and retries to every reply. Measured live with a trace endpoint that holds each request 8 s: the client got `execution_terminal` 8.1 s after the last node completed, now under 1 s. The worker still sends the trace in full after the terminal. An interrupted run (`stream_close` `cancelled`) was not affected: its terminal is written before the trace export.

## 0.2.7 - 2026-11-16

### Fixed

- **`toReplay()` of a run that a terminal tool ended now ends with the reply the user got.** With `terminalTools()`, `run()->text` is the terminal tool's text, set outside the steps. The replay ended with the tool message, so a later turn never saw that reply. It now ends with an assistant message that holds the reply, after the tool message. An empty reply adds no message. Tested live on `gpt-5-mini` (OpenAI accepts it, streamed and non-streamed) and with faked HTTP for Anthropic.
- **`toReplay()` leaves out text held with `holdStepText` that the user never saw.** With `->terminalTools([...], holdStepText: true)`, the text that the model wrote before the terminal call was not streamed and not in `run()->text`, but it stayed in the replay. The terminal step of the replay now has an empty `content`, no OpenAI `message` item and no Anthropic `text` block after its last `thinking` block. The reasoning item and the signed thinking block stay. OpenAI accepts this (live on `gpt-5-mini`, streamed and non-streamed: `reasoning` then `function_call`). Anthropic binds a signed thinking block to what comes before it, not after it, so the block stays valid; a `text` block that another `thinking` block follows is kept, because removing it makes the API reject that block (tested with faked HTTP that applies this rule). `steps[n]->text` and `providerContent` are not changed. Without `holdStepText` the text was shown, and the replay keeps it.

### Added

- `LlmResponse::$heldText`: true when a terminal tool ended the run and `holdStepText` held the text of its last step.

## 0.2.6 - 2026-11-16

### Added

- **A tool run can be replayed as history on a later turn.** `LlmResponse::toReplay()` returns plain, JSON-safe data (`version` 1, `provider`, `model`, `messages`) with each step's assistant and tool messages and what the provider needs to accept them again: OpenAI reasoning items (`output_items`), Anthropic signed thinking blocks (`content_blocks`), Gemini thought signatures (on the tool calls), OpenRouter `reasoning_details`, and the call id of each tool result. Send it back with `->replay($data)` on `llm()`, or as an item of `history()` or a `## messages:` placeholder. It is captured the same way on streamed and non-streamed runs. Before, an app that rebuilt the history from `toArray()` lost this data: OpenAI reasoning models rejected a replayed non-streamed tool run with 400 "function_call was provided without its required 'reasoning' item", and Anthropic thinking was dropped.
- A replay goes to any model of the same provider unchanged. Removing the reasoning for another model is not safe: OpenAI rejects the function calls of a reasoning model without their reasoning items on any reasoning model (live, `gpt-5-mini` to `gpt-5-nano`), and Anthropic leaves out the thinking a model cannot read. A replay to another provider throws an `InvalidArgumentException` before the request is sent.
- `ToolRunResult::$callId` (the OpenAI `call_id`). `LlmStep::$providerContent`. `LlmResponse::$provider` and `$model`, also in `toArray()`.
- OpenAI with `store: false` cannot replay reasoning: that needs `encrypted_content`, and Prism 0.102 does not send `include`. Documented.

### Fixed

- **A provider 4xx that is not about the reasoning setting is no longer reported as `ReasoningException`.** Only an error that names a reasoning or temperature setting (`reasoning.effort`, `thinking.type`, a budget, `temperature`, a model that does not support thinking) is wrapped. Any other 4xx stays the provider's `PrismException`. Before, a replay error read "The provider rejected the request with reasoning level [low] set".
- **A branch job that fails for good ends the execution as failed, also when the batch cannot record it.** Laravel records a failed batch job (the `job_batches` update) before it calls the job's `failed()` and the batch `catch` callback. When that update threw (SQLite "database is locked"), no callback ran and the execution stayed `running`. Laravel's `JobFailed` event now ends it as `failed` with `GraphExecutionFailed`. Only the call that moves the execution to `failed` dispatches the event, so the batch callback and this path cannot both fail it.

### Changed

- Docs: an async fan-out with more than one worker is not supported on SQLite. Laravel's batch repository reads and then updates `job_batches` in one transaction, and SQLite returns "database is locked" at once when two workers do this (`busy_timeout` and WAL do not help). Use MySQL or PostgreSQL for the queue and `job_batches`. Measured on 0.2.4 and 0.2.5 with three branches and three workers: the same failure rate, so 0.2.5 did not cause it.

## 0.2.5 - 2026-11-14

### Added

- **Terminal tools.** `->terminalTools(['name'])` on `llm()` ends a tool run when that tool returns without an error: no further model call is made, and the reply is the tool's text, word for word (`run()->text`, and `text_delta` in a streamed run, followed by `stream_end`). The steps keep the call and the result, and `LlmResponse::$terminalTool` names the tool. A handler can return `TerminalResult::reply($text, $data)` (`Shahzad\AgenticGraph\Tools`) to add data, read from `LlmResponse::$terminalData`; a plain string also works. Every tool the model called in that step runs and is recorded. A terminal tool that fails does not end the run. When `maxSteps` is reached first, the run ends as before. A name that is not a tool of the request throws.
- **`holdStepText`.** `->terminalTools(['name'], holdStepText: true)` holds the text of each step until the step ends. When the step called a terminal tool, the held text is not streamed (it stays in `steps[n]->text`); when it did not, the text is sent as normal `text_delta` events before the step's `step_finish`. The streamed text of a run that a terminal tool ends is then exactly the tool's text. Default `false`: model text before the call is streamed as it arrives.
- **Provider guard for terminal tools.** A run with terminal tools reads the provider's stream, also when the app does not stream, and stops reading after the tool step. This is verified, with a fixture test each, for `openai`, `anthropic`, `deepseek`, `gemini`, `groq`, `mistral`, `ollama`, `openrouter`, `xai` and `z`. Any other provider (`perplexity`, which has no tool loop) throws a `LogicException` that names it, before a request is sent.
- Tracing: the llm span of a run that a terminal tool ended has the attributes `llm.end_reason` (`terminal_tool`) and `llm.terminal_tool` (the tool's name), and `TokensUsed` has `endReason` and `terminalTool`. Both attributes are always sent, as other identifiers are. A trace consumer that validates span attributes with a schema from before this release reports them as unknown keys.
- `explain()['tools']` has `terminal` and `hold_step_text`. `LlmResponse::toArray()` has `terminal_tool` and `terminal_data`.

### Fixed

- **Branch results of an async fan-out no longer expire.** Each branch job cached its result for 300 seconds. When a batch took longer than that after its first branch finished (a slow branch, or fewer workers than branches), the result was gone at the join: the "Missing batch results" exception was only logged, and the execution stayed `running`. Results are now rows in the new `agenticgraph_batch_results` table, kept until the batch ends and then deleted. **Publish and run the new migration** (`php artisan vendor:publish --tag=agenticgraph-migrations`, then `php artisan migrate`); without the table every branch job of an async fan-out fails. A batch that was in flight during the upgrade still completes: for this release a result is also read from the old cache key.
- **A missing branch result fails the execution.** The join no longer waits 1.5 seconds per missing result and no longer throws inside the batch callback. It marks the execution `failed` and dispatches `GraphExecutionFailed`.

### Changed

- Docs and skills: sync mode (`run()`, `stream(inline: true)`) runs fan-out branches one after another, in the order the router returned them. Async mode (`dispatch()`) runs them at the same time only when the graph queue has one worker for each branch. A streamed async run (`stream()`, `broadcast()`) runs the branches one after another in one worker. Measured with three 2-second branches: 6.1 s sync, 6.2 s with one worker, 2.3 s with three workers, 6.2 s streamed with three workers. No behaviour changed.
- `ExecutionRepository::cleanup()` also deletes batch result rows older than its threshold (rows of a batch whose worker died before the batch ended).

## 0.2.4 - 2026-11-11

### Added

- **`TokensUsed::inputTokens()`.** All input tokens of the call: `promptTokens` (uncached) plus `cacheReadTokens` and `cacheWriteTokens`. Full usage is `inputTokens() + completionTokens`. `totalTokens` is unchanged: it is `promptTokens + completionTokens` and leaves out cached input, so with a repeated long prompt it can be a small part of what was sent (an eval run sent about 9,000 input tokens and recorded a total of 2,876). Cost was always priced with the cache tokens, and OTLP `gen_ai.usage.input_tokens` already includes them. The docs, the `agenticgraph-core` skill and the wire format reference now say this plainly.

### Changed

- **`streamUrl` includes `execution_id`.** `ExecutionHandle::stream()->streamUrl` is now `/agentic-graph/streams/{streamId}?execution_id={id}`, so a `stream_replay_unavailable` carries `final_state` (when exposed) with no extra work in the app. Apps that add `execution_id` to the URL by hand keep working (the same value is sent twice and read once) and can remove it. A signed URL you mint yourself should include `execution_id`.
- Docs: the trace context is bound for every run, sync and queued, with tracing on or off, so a node reads its execution id with `TraceContext::tryGet()?->getExecutionId()`. The snippet is in the node docs and the tracing docs, and no longer checks `isEnabled()`.

### Security

- **The stream route sends `final_state` only to the stream's own execution.** `stream_replay_unavailable` used to load the final state of any `execution_id` in the query string, so a client with one valid stream id and an expired cursor could read another run's exposed final state by passing that run's id. It is now attached only when the execution's stored `stream_id` is the stream being read; otherwise the event carries `cursor` only.

### Later

Planned for 0.3 (breaking): `total_tokens` (`TokensUsed::$totalTokens`, the llm span and the `llm_calls` row) should include cached input, so it equals `inputTokens() + completionTokens`.

## 0.2.3 - 2026-11-08

### Changed

- Skills and docs guidance only, no code change. The `agenticgraph-streaming` skill says to import the stream client from `resources/js/app.js` with Vite and load it with `@vite()`, and to use the script-tag build only without a bundler. The `agenticgraph-core` skill covers Ollama (`ollama:<tag>`, `OLLAMA_URL`, thinking models). The `agenticgraph-tracing` skill has an Annotations section, including the rule that a headline holds ids, counts and statuses, never free text.

## 0.2.2 - 2026-11-07

### Fixed

- **An expired execution stays expired.** A worker still running when `agenticgraph:executions:expire` failed its execution no longer marks it `completed`, fires `GraphExecutionCompleted` or `GraphExecutionFailed` again, or writes a second `graph_failed` or `execution_terminal`. `ExecutionRepository::completed()` and `started()` now return `bool` and refuse an ended row, a retried or continued job on an ended execution exits without running (a retry while the row is still running proceeds as before), and a node whose execution ended is stopped, never retried. An execution an expiry command ended (`stale_queued`, `stale_running`, `interrupt_expired`) is final: it is recorded in `execution_options` (`ended_by`, `ended_reason`, no migration) and no job reopens it. A crash-failed execution is still continued by `ContinueExecutionJob` with a resume payload, and `executeFromNode()` called directly is an explicit new run that re-runs any ended execution.
- `agenticgraph:execute` finds graphs registered in `config/agenticgraph.php`, and so do queued branch jobs, interrupt resume and `AgenticGraph::get()` on a fresh process (`GraphRegistry::get()` builds config graphs, as `getOrBuild()` did).
- `vendor:publish --tag=agenticgraph-config --force` no longer overwrites `config/model_pricing_overrides.php`. It is published once, when missing; `config/agenticgraph.php` and `config/model_pricing.php` are overwritten only with `--force`.

### Changed

- `AGENTICGRAPH_STREAM_SSE_POLL_INTERVAL` defaults to 100 ms (was 250). The SSE route reads the store once per interval, so streamed text reached the browser in flushes 250 ms apart (3 to 20 tokens at a time at normal model speed). It is now about 100 ms. Measured on the database store: events are written every 12 ms on average, and flushes came 253 ms apart before and 103 ms after. The Redis store uses the same value as its blocking-read wait.
- A node that streams keeps its execution alive: while events are written, the execution row's `updated_at` is refreshed at most once every 10 seconds, so `agenticgraph:executions:expire` does not fail a long streamed answer.

### Later

Not in this release: stored stream events for model output (`text_delta` and the rest) carry the provider's whole-second time in `occurred_at`. They will be stamped with microseconds at write time instead.

## 0.2.1 - 2026-11-07

### Added

- **`agenticgraph:executions:expire`.** Fails async executions no worker is running, so the visitor's stream gets a terminal event. A `queued` execution older than `AGENTICGRAPH_EXECUTION_QUEUED_TIMEOUT` (default 300 seconds) and a `running` execution with no progress for longer than `max(AGENTICGRAPH_EXECUTION_TIMEOUT, AGENTICGRAPH_RESUME_TIMEOUT)` plus `AGENTICGRAPH_EXECUTION_STALE_GRACE` (default 60 seconds) are failed. When `AGENTICGRAPH_EXECUTION_TIMEOUT` is 0, a running execution is failed after `AGENTICGRAPH_EXECUTION_STALE_RUNNING_AFTER` (default 900 seconds). A running execution that completes nodes counts as alive (`updated_at` is refreshed after each node, sequential, streamed or parallel). Interrupted and sync executions are never touched. It ends the execution like a failed job: status `failed`, `graph_failed` (with `reason` `stale_queued` or `stale_running`) and a failed `execution_terminal`, the `GraphExecutionFailed` event, and a final trace segment on the original root span. Each execution is ended in its own transaction, a broadcast failure is logged, and the trace is sent after the commit. Schedule it every minute, next to `agenticgraph:interrupt:expire`.
- `GraphExecutionFailed` has an optional `reason` (`interrupt_expired`, `stale_queued`, `stale_running`), and `ExecutionRepository::queued()` an optional `$rootSpanId`, kept in the execution options so a run that never finishes can end its trace on the original root. No migration.
- **`AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS`** (default `false`). When on, `graph_failed`, `node_error`, the failed `execution_terminal` and broadcast `stream_close` carry the exception class and message, the message cut to `AGENTICGRAPH_CAPTURE_MAX_LENGTH` with the truncation marker; `AGENTICGRAPH_CAPTURE_ERRORS=class` still removes the message. Same keys as before, additive within stream wire v1.1.
- **Custom events over SSE.** With `?custom_events=wrap` on the stream URL, events from `streamEvent()` arrive as `event: custom_event` with the author's name in `event_type`, so a browser `EventSource` client gets them without listing names. Without the parameter the frames are unchanged. Additive within stream wire v1.1; stream client 0.1.1 asks for it.
- **`prompt()` with `history()`.** The history messages come first, then the prompt as the latest user message; `system()` stays first and `explain()` shows the final order. History text is never interpolated. `user()` and `assistant()` with `prompt()` still throw (use `chat()`), and `chat()` is unchanged.

### Changed

- **Security fix: stream error details are no longer sent to the browser by default.** `graph_failed`, `node_error`, the failed `execution_terminal` `meta` and broadcast `stream_close` `meta` carry `error_type: ExecutionFailed` and `message: Execution failed`. 0.2.0 sent the raw exception class and message, which can hold hosts, credentials in a DSN or SQL. Set `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS=true` to send them. The stored execution `error` and the logs keep the full message; `reason` is always sent.
- **Security fix: `stream_error` and the model `error` event follow the same rule.** `stream_error` carries `error_type: StreamError` and `message: Stream error` (its `stage` and `event_*` fields are unchanged). The Prism `error` event carries a category the package picks, `rate_limited`, `provider_unavailable` or `provider_error`, a fixed message for it and `metadata: null`, never the provider's text, which can hold keys, account ids or internal URLs. `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS=true` sends the exception or provider type, message (cut to `capture.max_length`, `AGENTICGRAPH_CAPTURE_ERRORS=class` still removes it) and metadata. Logs keep the real error.
- **Security fix: a failed `tool_result` and a provider failure follow the same rule.** A failed `tool_result` carries `Tool failed` in `error` and in `result` (Prism puts the error text in both); the model still receives the real error. A node failed by a model provider exception (Prism or HTTP client, also wrapped) shows `error_type` `rate_limited`, `provider_unavailable` or `provider_error` with a fixed message in `node_error`, `graph_failed` and the terminal `meta`, chosen from the exception type, never its text. Any other failure stays `ExecutionFailed`. `AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS=true` sends the real values.
- Custom stream events and model stream events (`text_delta` and the rest) carry the node name registered in the graph (`retrieve`), like lifecycle events, and share its `node_seq`. They carried a name derived from the class (`Retrieve`).
- A first attempt of an `ExecuteGraphJob` whose execution already ended (for example expired while the job waited in the queue) exits without running. Retries are unchanged.
- A completed node now updates the execution row's `updated_at` while it is `running`, one small update per node, in every execution mode.

### Fixed

- `agenticgraph:list` no longer prints `()` for a graph without a description.
- Tests: `TraceSegmentsTest` uses its own unique temporary directory, so a stray file in the shared temp folder no longer fails it, and `TraceSegmentMergerCorpusTest` reads `tests/fixtures` with the case it is tracked under, so it also passes on a case-sensitive filesystem (CI).
- The Pulse exporter refuses a non-`https` endpoint, except `localhost`, `127.0.0.1` and `*.test`, and logs the setting to fix (`AGENTICPULSE_ENDPOINT`; use the base URL with no path). An `http://` endpoint was redirected to `https://`, which turned the POST into a GET and failed every segment with 405. Tracing to Pulse stays off until the endpoint is fixed.

## 0.2.0 - 2026-11-06

Upgrading from 0.1.0? Follow [Upgrading from 0.1.0](README.md#upgrading-from-010) in the README: it covers every step below, in the order to run them.

### Breaking

- **Platform.** Requires PHP 8.3+, Laravel 12.61.1+ or 13.12+, and `prism-php/prism` `^0.102.0`. PHP 8.2, Laravel 10 and 11, and Prism 0.98 are no longer supported. Prism now sends Anthropic structured output in native mode, which needs Claude Sonnet 4.5 or newer; for an older Anthropic model set `$providerOptions = ['use_tool_calling' => true]` on the node.
- **Migrations.** 0.2.0 adds tables and columns. Publish the migrations again and run them (`vendor:publish --tag=agenticgraph-migrations`, then `migrate`). Files you already published keep their names; the new tables and the `zzzz_*` upgrade migrations are added, and each upgrade checks the schema first.
- **One request builder: `$this->llm()`.** The `PrismNode` helpers `prompt()`, `promptTemplate()`, `chatPrompt()`, `structured()`, `tools()`, `withModel()`, `withCache()`, `withoutCache()`, `shouldCache()`, `getCacheKey()` and `interpolate()` are removed. Every model call goes through `$this->llm()`, an immutable request that returns an `LlmResponse` (see the table below). Templates (`prompt()`, `chat()`) are formatted with `vars()`, so a literal brace needs `{{` or `}}`; `system()`, `user()`, `assistant()` and `history()` are sent as written.
- **No default model.** `agenticgraph.prism.model` (`AGENTICGRAPH_PRISM_MODEL`) has no default (it was `anthropic:claude-sonnet-4`), and a model without a provider prefix (`gpt-4o`) is refused. Both throw `MissingModelException` on the first call. Set the env key or `$model` on each node.
- **LLM caching is off by default.** `agenticgraph.prism.cache_enabled` (`AGENTICGRAPH_PRISM_CACHE`) defaults to `false` (it was `true`). Turn it on per request with `->withCache()` or in config. Requests with tools or MCP are never cached. A published `config/agenticgraph.php` keeps the value written in it.
- **Flush the cache.** `CachingMiddleware` now stores state as a plain array and cannot read entries written by 0.1.0, and the LLM and `PrismRouter` cache keys changed. Run `php artisan cache:clear` (or `cache:clear <store>`) after upgrading; each cached reply then misses once.
- **`PrismRouter` without a default route throws.** When the model gives no usable decision (no route key, an unknown route, or every retry failed), it throws `RoutingException` (`router`, `reason`, `reply`) instead of returning an empty route. `RoutingException` extends `RuntimeException`. Set a default route to keep the old tolerance.
- **Any router's default route now applies.** A router that returns an empty route (`''`, `[]`) or a node the graph does not have takes its `getDefaultRoute()` (a warning, `RouteFellBack` with `empty_route` or `unknown_route`, `route.fallback` on the span), in sync, queued and streamed runs and after a parallel join. With no default route, or one that is not a node, it throws `RoutingException`; a fan-out naming an unknown node always throws. It used to fail with an undefined array key. `RoutingException` and `RouteFellBack` are no longer specific to `PrismRouter`.
- **`getTimeout()` is removed** from `NodeInterface` and `AbstractNode`. Nothing read it, so its 300 seconds never limited a node. A node that defines it keeps working; a call to `parent::getTimeout()` fails. Bound model calls with `->timeout()` on the request, and runs with `AGENTICGRAPH_EXECUTION_TIMEOUT` and `AGENTICGRAPH_RESUME_TIMEOUT`.
- **Token numbers (usage semantics v2).** `prompt_tokens` is the uncached input, cached input is reported in `cache_read_tokens` and `cache_write_tokens`, and `completion_tokens` includes reasoning tokens. Counts in `TokensUsed` change on Gemini, OpenAI streams, OpenRouter, DeepSeek, Mistral, Groq, xAI and Ollama 0.33.3+, and a multi-step stream sums every step. If you store token counts for billing or analytics, expect a step change.

| 0.1.0 | 0.2.0 |
|---|---|
| `$this->prompt('Hi {name}', ['name' => $n])` | `$this->llm()->prompt('Hi {name}')->vars(['name' => $n])->text()` |
| `$this->promptTemplate($t, $vars)` | `$this->llm()->prompt($t)->vars($vars)->text()` |
| `$this->withoutCache()->chatPrompt($t, $vars)` | `$this->llm()->withoutCache()->chat($t)->vars($vars)->text()` |
| `$this->structured($prompt, $schema)` | `$this->llm()->prompt($prompt)->schema($schema)->structured()` |
| `$this->tools($prompt, $tools)` | `$this->llm()->prompt($prompt)->tools($tools)->run()` |
| `$this->withModel('openai:gpt-4o')` | `protected ?string $model = 'openai:gpt-4o';` (or `->model()` on one request) |

### Added

- **`service.version` default.** It comes from `AGENTICGRAPH_SERVICE_VERSION`, else `config('app.version')` if set. With neither it is left out of the trace resource and the OTLP payload (it was the string `unknown`).
- **Trace status when segments merge (D8).** `TraceSegmentMerger` takes the status from the final segment, else from the latest initial or resumed segment; a continued segment never sets it.
- **Streaming.** `AgenticGraph::stream()` (SSE) and `AgenticGraph::broadcast()` (Laravel broadcasting), or `ExecutionOptions` for full control. Events follow the frozen stream wire contract v1.1: graph and node lifecycle, text deltas, tool calls, thinking, one `step_start` and `step_finish` pair per model step, `stream_start` and `stream_end`, and a final `execution_terminal`. The SSE endpoint replays from a cursor, sends heartbeats, and keeps the connection open through an approval wait. `final_state` is `null` unless you set `AGENTICGRAPH_STREAM_EXPOSE_FINAL_STATE=true` (limit it with `AGENTICGRAPH_STREAM_FINAL_STATE_KEYS`). Use `@agenticgraph/stream-client` 0.1.x on the client.
- **Human in the loop.** A node can pause the run for input (`shouldInterrupt()`, `onResume()`), and `AgenticGraph::resume($executionId, $input)` continues it, sync or queued. Interrupts are stored and expire: schedule `agenticgraph:interrupt:expire`, and an expired interrupt cannot be resumed.
- **The `$this->llm()` builder.** Text, chat, attachments, structured output (`schema()`, or `as(Dto::class)` to hydrate a class, with a retry when the reply does not fit), tools and MCP servers (`tools()`, `mcp()`, `toolChoice()`, `maxSteps()`), `context()` for retrieved documents (it accepts `agenticgraph/vector` results), per-request caching, and `explain()` to see the resolved request without calling the model. `LlmResponse` has the text, structured data, tool calls and results, each step, usage, finish reason and the context sources used.
- **Temperature.** `->temperature($value)` on a request (0 to 2, else `InvalidArgumentException`), `protected ?float $temperature` on a `PrismNode`, and `agenticgraph.prism.temperature` (`AGENTICGRAPH_PRISM_TEMPERATURE`, default `null`: none sent), resolved config, then node, then call; a resolved value outside 0 to 2 throws, naming its source. `PrismRouter` reads `agenticgraph.router.temperature` (`AGENTICGRAPH_ROUTER_TEMPERATURE`, default `null`) instead. Sent on every path (text, structured, tools, MCP, streamed), shown in `explain()` with its source and seen by `Llm::fake()->assertSettings()`. The cache key covers it. Not checked against the model: some reasoning models accept a temperature only with reasoning off, otherwise the provider rejects the call and it appears as a failed LLM call. When the call also sets a reasoning level, the `ReasoningException` names both settings.
- **Per-call timeout and structured retries.** `->timeout($seconds, $connectSeconds)` sets the provider HTTP timeouts for one request, over `agenticgraph.api.timeout` and `agenticgraph.api.connect_timeout`; each tool step and structured retry is its own request, and a streamed request must finish within it. `->structuredRetries($n)` sets the retries for one structured request, over `agenticgraph.prism.structured.retries`. Both show in `explain()` with source `call`.
- **Reasoning.** `->reasoning($level, $budget)`, or `$reasoning` and `$reasoningBudget` on a node, mapped to each provider's option (OpenAI, Gemini, Anthropic, OpenRouter, Ollama). A setting the provider cannot apply throws `ReasoningException` before the call. Thought tokens are reported where the provider gives them.
- **Prompt files.** Markdown with front matter in `resources/prompts` (`AGENTICGRAPH_PROMPTS_PATH`), loaded with `Prompts::get('support.reply')`. `php artisan agenticgraph:prompts` lists them and exits 1 when one is invalid, for CI. Each call records the file's name, version and hash.
- **Testing.** `Llm::fake($responses)` runs the real request path over Prism's fake and records every request, with `assertPrompted()`, `assertSettings()`, `assertSentCount()` and `assertNothingSent()`.
- **Tracing** (off by default, `AGENTICGRAPH_TRACING=true`). Spans for graphs, nodes, LLM calls, tools, routes, HTTP, database queries, vector operations and your own `Trace::span()` blocks, with tokens and cost (`config/model_pricing.php`, `agenticgraph:pricing:sync`), across queues, interrupts and resumes. Exporters: `auto`, `pulse` (AgenticPulse), `otlp`, `log`, `file` and `null`. By default a trace carries state key names and value types, not values (`AGENTICGRAPH_CAPTURE_STATE`); prompts, responses and state values are sent only when you turn them on.
- **Interrupt expiry on traces.** An interrupt span carries `interrupt.expires_at` and the interrupting segment `trace.interrupt_expires_at` (ISO 8601, the stored interrupt's `expires_at`); both are absent when the interrupt has no expiry or is not persisted. Additive within trace wire v1.1. `NodeInterrupted` gains `expiresAt`, and `TracerExporter::interruptTrace()` an optional `$expiresAt`.
- **Where a node failed: `error.stage`.** A failed node span's `error` carries `stage`: `middleware_before` (a middleware threw before `$next`, so the node never ran), `node` (the node body, `onBefore` or `onAfter` threw; one per attempt) or `middleware_after` (a middleware threw after `$next` returned). Kept with `capture.errors=class`; absent on every other span and on the trace `error`. The OTLP driver sends it as `agenticgraph.error.stage`. Additive within trace wire v1.1. `NodeExecutionFailed` gains `stage`.
- **Trace annotations.** Declare what a run means so a trace can be explained without guessing: a node label and description (`#[Annotate('Look up the order', description: 'Looked up order {order_lookup.order_id}')]` on the class, or `label:`, `description:` on `addNode()`, which win per field), a graph `label()`, an `outcome()` (a headline and up to 8 state paths), `humanInput:` keys read from the state on a resume, and `expectations()` / `toolExpectations()` (`read_only`, `max_retries`, `max_loops`, `max_cost_usd`). Placeholders are `{dot.path}` into the state, `{{` and `}}` literal braces; lengths, counts, syntax and expectation names are checked when the graph is built (`InvalidGraphException`). Rendering never fails a run (a missing key renders empty and is listed in `missing`; an exception becomes `annotation_error` with the class name) and never calls a model. Expectations are declarations for AgenticPulse to check; they never stop or change a run. Declared values are sent in every `capture.state` mode, keys included; `tracing.capture.annotations` (`AGENTICGRAPH_CAPTURE_ANNOTATIONS`: `all`, `static`, `none`) lets the app owner limit them, also for OTLP. Wire v1.1, additive: `trace.annotations`, `annotations` on node, tool and resume spans, `human_input` on resume spans; a graph without declarations sends the same payload as before. `NodeResumed` gains an optional `graphName`, and `ExecuteNodeJob` binds its graph while the node runs.
- **Router settings.** `PrismRouter` takes a `prompt:` template or prompt file, sends `agenticgraph.router.max_tokens` (`AGENTICGRAPH_ROUTER_MAX_TOKENS`, default 1024) and `agenticgraph.router.reasoning` (`AGENTICGRAPH_ROUTER_REASONING`), and logs a warning and dispatches `RouteFellBack` whenever it takes the default route.
- **Events.** `GraphExecutionFailed`, `NodeExecutionFailed`, `NodeInterrupted`, `NodeResumed`, `InterruptPending`, `InterruptExpired`, `CheckpointCreated`, `RouteDecided`, `RouteFellBack`, `LlmCallFailed`, `ReasoningNotDisabled` and `ToolCallCaptured`. `TokensUsed` gains cache, thought-token, streamed and prompt-file fields.
- **Execution settings.** Loops with iteration and cycle limits (`execution.loop`, opt-in), a checkpoint every N nodes (`AGENTICGRAPH_CHECKPOINT_FREQUENCY`), a maximum state size (`AGENTICGRAPH_MAX_STATE_SIZE`), queue job tries and timeouts (`AGENTICGRAPH_EXECUTION_TRIES`, `AGENTICGRAPH_EXECUTION_TIMEOUT`, `AGENTICGRAPH_RESUME_TRIES`, `AGENTICGRAPH_RESUME_TIMEOUT`), `$maxTokens` and `$providerOptions` on nodes (`AGENTICGRAPH_PRISM_MAX_TOKENS`), and image, audio and embedding helpers on `PrismNode`.
- **Pulse 429 handling.** The Pulse exporter honors `Retry-After` (seconds or an HTTP date). A wait up to `tracing.transport.retry_after_wait` (2 s) happens in process and the segment is posted once more; a longer one goes to a delayed `SendPulseSegmentJob` when the app has a real queue (not `sync`), else the exporter waits the cap, posts once more and logs. A 429 without `Retry-After`, a 413 and other 4xx stay logged losses. New `tracing.transport` keys: `retry_after_wait`, `max_delay`, `queue_connection`, `queue_name`, `job_tries`. SQS refuses messages over 256 KB, so a large segment falls back to wait-and-retry-once there.
- **Pulse requests identify the client.** Every exporter request sends `User-Agent: agenticgraph/{version}` (the installed package version) and `Accept: application/json`.

### Changed

- `PrismRouter`'s routing call allows 1024 output tokens by default (it was a fixed 100), so reasoning models no longer fall back to the default route for lack of room.
- Package logs no longer carry state values, model output or stack traces.
- Safe under Laravel Octane and long-running queue workers: no graph, trace, execution or pricing state crosses requests (verified on RoadRunner).
- Config keys that 0.1.0 never read are removed: `checkpoint.strategy` (replaced by `checkpoint.frequency`), `checkpoint.compression`, `checkpoint.encryption`, `events.enabled`, `lock.driver`, `lock.prefix`, `lock.strategy`, `lock.retry_interval`, `logging.channel`, `logging.level`, `logging.log_state`, `execution.max_retries`, `execution.retry_delay`, `execution.parallel`, `prism.timeout`, `prism.track_tokens` and `visualization`. `execution.timeout` now reads `AGENTICGRAPH_EXECUTION_TIMEOUT`.
- **Truncation marker.** A cut value now ends with ` [...truncated N]`, `N` being the original length in bytes (it was ` [...truncated]`). One helper, `CaptureDepth::cut()`, writes it for state values, prompts, responses and event text, and the marker counts toward `capture.max_length`. A receiver that matches the old literal must match the new form.
- **Payload size is measured, not estimated.** The 1 MiB and 10 MiB steps now use the real encoded bytes of the request body (`TracePayloadBuilder::encode()`), so non-ASCII or slash-heavy segments that the old estimate undercounted are shrunk when they should be. `_meta.original_size_bytes` is that encoded size.
- **Execution ids come from one generator** (`ExecutionId::generate()`: `exec_{microtime}_{16 hex}`), also for a `TraceContext` rebuilt without an id (it used `uniqid`).

### Fixed

- A trace envelope of exactly 1 MiB is sent as it is; only one above 1 MiB is shrunk (it was shrunk at 1 MiB). The 10 MiB step already used the same boundary.
- `capture.max_length` below 50 bytes (the longest truncation marker plus a margin, `CaptureDepth::MIN_MAX_LENGTH`) throws an `InvalidArgumentException` naming the key, the value and the minimum when the setting is read, instead of cutting values to a bare marker mid-run.
- A middleware that catches a node's final exception and throws a different one no longer sends a second failed span with the last attempt's id (which replaced that attempt's error with the middleware's). The node's span keeps its own exception and `stage: node`; the middleware's exception is what the run fails with, on the graph span and the trace.
- A false "no outgoing edges" warning for `addJoin()` sources.
- A queued graph job that fails (for example a graph not registered on the worker) marks the execution failed instead of leaving it running.
- `agenticgraph:interrupt:expire` continues the interrupted trace: the failed graph span reuses the stored root span id (one root span in the merged trace), the final segment carries `graph_name` and ends at or after it starts.
- `agenticgraph:interrupt:expire` keeps going when broadcasting fails (for example Reverb is down): the failed `stream_close` broadcast is logged as a warning, here and when a queued job fails. Each interrupt is expired in its own transaction, so one that fails (a throwing `InterruptExpired` listener, for example) is logged and stays pending for the next run while the others expire, and its final trace segment is sent only after the commit, so a rolled-back expiry sends none. `BufferedTraceExporter::sendAfterCommit()` opts a trace into that.
- A value cut at `capture.max_length` no longer splits a multibyte character. The cut left invalid UTF-8, `json_encode` threw, and the whole Pulse segment was lost.
- A value with an invalid UTF-8 byte no longer loses the whole Pulse segment: the encoder replaces the bad sequence with U+FFFD.
- A caller-supplied execution id (it is the trace id) longer than 200 characters throws `InvalidExecutionIdException` instead of being accepted.
- A segment refused with a 429 is no longer lost when Pulse says when to come back (see Pulse 429 handling).

The trace wire (`agenticgraph.trace.v1.1`) and the stream wire contract v1.1 are frozen; later changes are additive. To send traces to your own backend, use OTLP.

## v0.1.0
- Initial public release
- Core agent graph execution engine
- Stable API for graph definition and execution

### Added - 2026-02-01
- Configurable lock timeouts, checkpoint frequency, and max state size.
- Exponential backoff and retry logic for API calls and batch processing.
- Graph structure validation and cycle detection.

### Changed
- Refactored `TraceContext` and `GraphRegistry` to remove static state and improve concurrency.
- Optimized `FileStorage` and database queries to reduce N+1 issues.
- Upgraded PHPStan analysis to Level 6 with comprehensive type hinting.

### Fixed
- "TimeTravel" span timing bugs and race conditions in parallel execution.
- Job serialization issues for queue processing.
