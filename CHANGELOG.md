# Changelog

## Unreleased

`agenticgraph.trace.v1` is frozen; later changes are additive. The contract is `spec/tracing-wire-v1.md`, locked by `TraceWireContractFreezeTest`.

### BREAKING for exporter implementers
- `TracerExporter` signatures changed. `completeTrace`, `failTrace` and `interruptTrace` take an optional `?\DateTimeInterface $at`; `resumeTrace` takes `?string $graphName`, `string $kind` and `?\DateTimeInterface $at`. Third-party exporters must update.
- `PulseExporter::sendIngestPayload()` is renamed to `send()`.
- `BufferedTraceExporter::whileCapturing()` is public. Package bookkeeping runs inside it so its queries and requests are not captured. A custom exporter that implements `TracerExporter` directly, without extending `BufferedTraceExporter`, gets no such suppression.
- Events: `NodeExecutionCompleted` describes the successful attempt. `NodeExecutionFailed::$spanId` changes per attempt, and the event takes optional trailing `nodeType` and `attemptOf` arguments.

### BREAKING (wire)
- Node spans carry `attempt` (1, 2, ...) and `attempt_of` (the id of attempt 1) instead of `retry_count`. One span per attempt.
- Every envelope carries a `segment` block (`id`, `kind`, `final`, `started_at`, `ended_at`) and only that segment's spans and LLM calls. A resumed trace is no longer resent whole: receivers merge segments by trace id. Non-final segments have `trace.status = running` (or `interrupted`).
- Span attributes live in one `attributes` object with dotted names. Top level keeps only the common keys, the llm fields (`model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost_usd`, `cached`, `streamed`) and `state_diff`, `loop_context`, `error`, `state_snapshot`. Moves: route `route.*`; tool `tool_type`, `tool_provider`, `tool_request_id`, `input_size`, `output_size`, `tool_call_id` become `tool.type`, `tool.provider`, `tool.request_id`, `tool.input_size`, `tool.output_size`, `gen_ai.tool.call.id`; interrupt `interrupt_reason`, `checkpoint_id` become `interrupt.reason`, `checkpoint.id`; resume `checkpoint_id` becomes `checkpoint.id`.
- `status_code` is `OK`, `ERROR` or `PAUSED` on every span. On http spans the HTTP status moves to `attributes["http.response.status_code"]` (int).
- db spans: `db.connection` becomes `laravel.db.connection`, `db.type` becomes `laravel.db.statement_type`, `db.bindings_count` becomes `laravel.db.bindings_count`, `db.sql` becomes `laravel.db.query_summary` (summary) or `db.query.text` (full). New `db.operation.name` (the SQL verb, single statements only).
- http spans: the `http` object is gone. `http.request.method`, `laravel.http.url_summary` or `url.full`, and `laravel.http.response_size` are in `attributes`. A connection failure or fallback span with an exception carries `error`.
- `trace.resource` and the OTLP resource use `deployment.environment.name` instead of the deprecated `deployment.environment`.
- A payload over 10 MB keeps its real `trace.status`. The reason moves to `_meta.error_reason = payload_too_large`, with dropped counts in `_meta.dropped`.
- `trace.status` gains `running` for non-final segments.

### Behavior
- Every wire timestamp is UTC with microseconds (`Y-m-d\TH:i:s.u+00:00`), whatever the app timezone.
- The `auto` exporter is `null` when `app()->runningUnitTests()`, so a test suite never sends traces. Otherwise it is `pulse` when `AGENTICPULSE_API_KEY` is set, else `log`. An explicit driver still applies.
- Sampling is deterministic per trace id instead of a random draw per process, so every segment of a trace gets the same decision.
- `executions.duration` holds the measured segment duration for resumed and continued rows.
- The package's own queries and requests (execution rows, checkpoints, interrupts, the stream store, batch dispatch) are never `db` or `http` spans, even with `tracing.capture.db` or `tracing.capture.http` on for the whole run. Only node bodies and tools produce them.
- Traces are sent synchronously by the process that did the work. No queue worker is needed.

### Added
- Tracing: the `TracerExporter` abstraction with `pulse`, `log` and `null` exporters; `Trace::span()` and `TraceContext::tryGet()`; tool-scoped tracing with gated HTTP/DB capture; the capture-depth settings; `config/model_pricing.php` and `agenticgraph:pricing:sync`; the OTLP HTTP/JSON exporter.
- `file` tracing exporter (`AGENTICGRAPH_TRACING_EXPORTER=file`): one JSON file per flushed segment at `{tracing.file.path}/{trace_id}/{Ymd-His-u}.json`. New config key `tracing.file.path` (`AGENTICGRAPH_TRACING_FILE_PATH`).
- `schema_version: agenticgraph.trace.v1` as the first key of every trace envelope, and the wire description `spec/tracing-wire-v1.md` (shipped with the package) ending in a machine-readable key registry.
- Microsecond timestamps on every span and trace field.
- `trace.active_ms`: the time the trace was actually running, excluding waits between segments.
- The `continued` segment kind, for queue jobs and scopes that work on a trace another process started.
- `TraceSegmentMerger`: reference merge of a trace's segment envelopes (final segment wins, otherwise the later `segment.ended_at`; `active_ms` is the union of segment windows).
- Route spans (`span_type: route`) for conditional edges and joins, with `route.from`, `route.targets`, `route.router` (fully qualified router class, like a node span's `node_type`), `route.kind` (`llm`, `deterministic`, `fan_out`, `join`), `route.from_cache`, `route.fallback`, `route.reason`. The parent is the root graph span; a router's LLM span is a child of its route span. Emitted through the new `RouteDecided` event.
- `streamed` on llm spans and `llm_calls`. New optional `TokensUsed` argument `streamed` (default false).
- `PrismRouter::lastRouteFlags()` returns the `from_cache` and `fallback` flags of the last route.
- The interrupt checkpoint stores the loop context in `metadata['loop']`.
- New optional `ExecuteNodeJob` argument `loopMetadata`. Jobs queued before it run as before.
- Config `tracing.transport.connect_timeout` (`AGENTICGRAPH_TRACING_CONNECT_TIMEOUT`, default 1) and `tracing.transport.timeout` (`AGENTICGRAPH_TRACING_TIMEOUT`, default 3).

### Changed
- Every node span's parent is the root graph span, including fan-out branches.
- One retry loop for streaming, sync and queue execution.
- `url.full` (capture `http_urls = full`) replaces URL credentials with `REDACTED:REDACTED` and the values of sensitive query parameters (`key`, `api_key`, `apikey`, `token`, `access_token`, `signature`, `sig`, `password`, and the OTel list: `X-Amz-Signature`, `X-Amz-Credential`, `X-Amz-Security-Token`, `X-Goog-Signature`; any case) with `REDACTED`.
- OTLP: attributes are sent under their own names (no `http.` or `db.` prefixing). LLM spans send `gen_ai.operation.name`, `gen_ai.request.model` (without the provider prefix), `gen_ai.provider.name`, `gen_ai.usage.input_tokens`, `gen_ai.usage.output_tokens`, `gen_ai.request.stream`, plus `agenticgraph.total_tokens`, `agenticgraph.cost_usd`, `agenticgraph.cached`; none of the `gen_ai.*` on a cache hit. Tool spans send `gen_ai.operation.name = execute_tool` and `gen_ai.tool.name`. `gen_ai.system`, `gen_ai.prompt`, `gen_ai.completion` and `gen_ai.usage.total_tokens` are no longer sent. Names verified against semantic-conventions v1.44.0 and semantic-conventions-genai `e57c543b`.
- Config keys added since the first published config fall back to code defaults when missing: `storage` (`database`), `middleware.global` (`[]`), `interrupts.handler` (null), `execution.queue_connection` (`redis`), `execution.queue_name` (`agenticgraph`). The OTLP exporter reads `tracing.otlp.endpoint` once, default `http://localhost:4318/v1/traces`.
- Queue node jobs, continue-execution jobs and batch failures each send their own `continued` segment, so a trace run across several workers is no longer lost or cut short.
- The default `tracing.endpoint` is `https://ingest.agenticgraph.dev`.
- Pulse transport: connect timeout 1 s, timeout 3 s, one retry after 500 ms on a connection error or 5xx, never on 4xx (worst case about 6.5 s). A failed send logs a warning with the response `code` or `connection_error`.
- The OTLP exporter shares the segment lifecycle and emits the root graph span only in the final segment.
- A failed batch branch dispatches `GraphExecutionFailed` with the root span id, so the trace ends as `failed`.
- Pulse, file and log exporters send one envelope built by `TracePayloadBuilder`, with the same segment, sampling, interrupt and resume lifecycle (`BufferedTraceExporter`).
- The log exporter logs the full envelope (message `agenticgraph.trace`), one entry per segment: it adds `schema_version`, drops the `exporter` key, applies sampling and size limits, and logs the resumed segment.
- In an HTTP request, every buffered exporter sends after the response (previously only pulse). Console and queue processes send synchronously even with a request bound.
- Instrumentation resolves the exporter lazily.
- Floats keep their zero fraction on the file and pulse wire.
- One instant defines each segment boundary, so a segment's end and the next one's start agree.

### Fixed
- Timestamps were in whole seconds.
- Sync fan-out branches had no parent in the trace and overlapping times.
- A trace could have several root spans across interrupt, resume, failure and queue. There is now one root span id per trace.
- Retries collapsed into one node span.
- A resumed trace reported a duration of 0.
- A resumed trace lost its first segment in OTLP.
- Traces run across several workers were lost.
- A span that arrived before the trace start (for example from a query in the same process) opened a `continued` segment and the trace start was dropped. The segment now becomes the `initial` segment with the full trace header.
- `duration_ms` is computed from raw microtime values.
- A false "no outgoing edges" warning for `addJoin` sources.
- A false "HTTP response without matching request" warning. The HTTP instrumentation singleton now also cleans up execution state.
- A negative interval is clamped to 0. The `capture.max_length` fallback is 2000.
- Sending a segment forgets only that trace. Clearing every open trace is left to `reset()`, for tests.
- `loop_context.node_visit_count` is correct after a sync or streaming resume, on sync and queued parallel branches, and after a queued join. Exported counts change on these paths. A checkpoint without `metadata['loop']` resumes with fresh counts.
- A streaming `tools()` call in `PrismNode` now dispatches `TokensUsed`, so it produces an llm span.
- A middleware that throws, before or after the node runs, now produces a failed node attempt span.
- `AGENTICGRAPH_TRACING_EXPORTER=null` selects the null exporter. Previously `env()` turned it into PHP null and the factory threw `Unknown agenticgraph tracing exporter []`.

### Removed
- The duplicate failure events and second `onError` in the streaming path and `ExecuteNodeJob`.
- `db.rows_affected` on db spans (always null).
- The trace buffer in the cache and its config (`tracing.buffer.store`, `tracing.buffer.ttl_seconds`). A resume no longer depends on the cache.
- The unused protected helper `PulseExporter::findSpansOutsideTraceWindow()`.

### Known limitation
- With `sampling.always_on_error` and a rate below 1.0, a failed trace that was not sampled is sent with its header and status but no spans. A later version adds them.

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
