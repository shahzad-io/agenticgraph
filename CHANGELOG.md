# Changelog

## Unreleased

### Added
- `file` tracing exporter (`AGENTICGRAPH_TRACING_EXPORTER=file`): one JSON file per flushed segment at `{tracing.file.path}/{trace_id}/{Ymd-His-u}.json`. New config key `tracing.file.path` (`AGENTICGRAPH_TRACING_FILE_PATH`).
- `schema_version: agenticgraph.trace.v1` as the first key of every trace envelope. Draft wire description in `spec/tracing-wire-v1.md` (shipped with the package).

- `TraceSegmentMerger`: reference merge of a trace's segment envelopes (final segment wins, otherwise the later `segment.ended_at`; `active_ms` is the union of segment windows).
- Route spans (`span_type: route`) for conditional edges and joins, with `route.from`, `route.targets`, `route.router` (fully qualified router class, like a node span's `node_type`), `route.kind` (`llm`, `deterministic`, `fan_out`, `join`), `route.from_cache`, `route.fallback`, `route.reason`. The parent is the root graph span; a router's LLM span is a child of its route span. Emitted through the new `RouteDecided` event.
- `streamed` on llm spans and `llm_calls`. New optional `TokensUsed` argument `streamed` (default false).
- `PrismRouter::lastRouteFlags()` returns the `from_cache` and `fallback` flags of the last route.
- The interrupt checkpoint stores the loop context in `metadata['loop']`.
- New optional `ExecuteNodeJob` argument `loopMetadata`. Jobs queued before it run as before.
- Config `tracing.transport.connect_timeout` (`AGENTICGRAPH_TRACING_CONNECT_TIMEOUT`, default 1) and `tracing.transport.timeout` (`AGENTICGRAPH_TRACING_TIMEOUT`, default 3).

### Changed
- **Breaking (wire):** span attributes live in one `attributes` object with dotted names. Top level keeps only the common keys, the llm fields (`model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost_usd`, `cached`, `streamed`) and `state_diff`, `loop_context`, `error`, `state_snapshot`. Moves: route `route.*`; tool `tool_type`, `tool_provider`, `tool_request_id`, `input_size`, `output_size`, `tool_call_id` become `tool.type`, `tool.provider`, `tool.request_id`, `tool.input_size`, `tool.output_size`, `gen_ai.tool.call.id`; interrupt `interrupt_reason`, `checkpoint_id` become `interrupt.reason`, `checkpoint.id`; resume `checkpoint_id` becomes `checkpoint.id`. Names in `spec/tracing-wire-v1.md`.
- **Breaking (wire):** db spans: `db.connection` becomes `laravel.db.connection`, `db.type` becomes `laravel.db.statement_type`, `db.bindings_count` becomes `laravel.db.bindings_count`, `db.sql` becomes `laravel.db.query_summary` (summary) or `db.query.text` (full). New `db.operation.name` (the SQL verb, single statements only).
- **Breaking (wire):** http spans: `status_code` is `OK` or `ERROR` like every other span; the HTTP status is `attributes["http.response.status_code"]` (int). The `http` object is gone: `http.request.method`, `laravel.http.url_summary` or `url.full`, `laravel.http.response_size` are in `attributes`. A connection failure or fallback span with an exception carries `error`.
- `url.full` (capture `http_urls = full`) replaces URL credentials with `REDACTED:REDACTED` and the values of sensitive query parameters (`key`, `api_key`, `apikey`, `token`, `access_token`, `signature`, `sig`, `password`, and the OTel list: `X-Amz-Signature`, `X-Amz-Credential`, `X-Amz-Security-Token`, `X-Goog-Signature`; any case) with `REDACTED`.
- **Breaking (wire):** `trace.resource` and the OTLP resource use `deployment.environment.name` instead of the deprecated `deployment.environment`.
- OTLP: attributes are sent under their own names (no `http.` or `db.` prefixing). LLM spans send `gen_ai.operation.name`, `gen_ai.request.model` (without the provider prefix), `gen_ai.provider.name`, `gen_ai.usage.input_tokens`, `gen_ai.usage.output_tokens`, `gen_ai.request.stream`, plus `agenticgraph.total_tokens`, `agenticgraph.cost_usd`, `agenticgraph.cached`; none of the `gen_ai.*` on a cache hit. Tool spans send `gen_ai.operation.name = execute_tool` and `gen_ai.tool.name`. `gen_ai.system`, `gen_ai.prompt`, `gen_ai.completion` and `gen_ai.usage.total_tokens` are no longer sent. Names verified against semantic-conventions v1.44.0 and semantic-conventions-genai `e57c543b`.
- `auto` exporter resolves to `null` when `app()->runningUnitTests()`, so a test suite never sends traces (spec J-1). An explicit driver still applies.
- Config keys added since the first published config fall back to code defaults when missing: `storage` (`database`), `middleware.global` (`[]`), `interrupts.handler` (null), `execution.queue_connection` (`redis`), `execution.queue_name` (`agenticgraph`). The OTLP exporter reads `tracing.otlp.endpoint` once, default `http://localhost:4318/v1/traces`.
- **Breaking (wire):** every envelope carries a `segment` block (`id`, `kind`, `final`, `started_at`, `ended_at`) and only that segment's spans and LLM calls. A resumed trace is no longer resent whole: receivers merge segments by trace id. Non-final segments have `trace.status = running` (or `interrupted`).
- Queue node jobs, continue-execution jobs and batch failures each send their own `continued` segment, so a trace run across several workers is no longer lost or cut short.
- **Breaking:** `TracerExporter` signatures changed. `completeTrace`, `failTrace` and `interruptTrace` take an optional `?\DateTimeInterface $at`; `resumeTrace` takes `?string $graphName`, `string $kind` and `?\DateTimeInterface $at`. Third-party exporters must update.
- Sampling is deterministic per trace id instead of a random draw per process.
- The default `tracing.endpoint` is `https://ingest.agenticgraph.dev`.
- Pulse transport: connect timeout 1 s, timeout 3 s, one retry after 500 ms on a connection error or 5xx, never on 4xx (worst case about 6.5 s). A failed send logs a warning with the response `code` or `connection_error`.
- The OTLP exporter shares the segment lifecycle and emits the root graph span only in the final segment, so a resumed trace no longer loses its first segment.
- A failed batch branch dispatches `GraphExecutionFailed` with the root span id, so the trace ends as `failed`.
- Pulse, file and log exporters send one envelope built by `TracePayloadBuilder`, with the same segment, sampling, interrupt and resume lifecycle (`BufferedTraceExporter`).
- The log exporter now logs the full envelope (message `agenticgraph.trace`), one entry per segment.
- In an HTTP request, every buffered exporter sends after the response (previously only pulse). Console and queue processes send synchronously even with a request bound.
- A payload over 10 MB keeps its real `trace.status`. The reason moves to `_meta.error_reason = payload_too_large`, with dropped counts in `_meta.dropped`.

### Fixed
- `loop_context.node_visit_count` is correct after a sync or streaming resume, on sync and queued parallel branches, and after a queued join. Exported counts change on these paths. A checkpoint without `metadata['loop']` resumes with fresh counts.
- A streaming `tools()` call in `PrismNode` now dispatches `TokensUsed`, so it produces an llm span.
- A middleware that throws, before or after the node runs, now produces a failed node attempt span.
- `AGENTICGRAPH_TRACING_EXPORTER=null` selects the null exporter. Previously `env()` turned it into PHP null and the factory threw `Unknown agenticgraph tracing exporter []`.

### Removed
- `db.rows_affected` on db spans (always null).
- The trace buffer in the cache and its config (`tracing.buffer.store`, `tracing.buffer.ttl_seconds`). A resume no longer depends on the cache.
- The unused protected helper `PulseExporter::findSpansOutsideTraceWindow()`.

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
