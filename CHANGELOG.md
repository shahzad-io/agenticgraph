# Changelog

## Unreleased

### Added
- `file` tracing exporter (`AGENTICGRAPH_TRACING_EXPORTER=file`): one JSON file per flushed segment at `{tracing.file.path}/{trace_id}/{Ymd-His-u}.json`. New config key `tracing.file.path` (`AGENTICGRAPH_TRACING_FILE_PATH`).
- `schema_version: agenticgraph.trace.v1` as the first key of every trace envelope. Draft wire description in `docs/tracing-wire-v1.md`.

- `TraceSegmentMerger`: reference merge of a trace's segment envelopes (final segment wins, otherwise the later `segment.ended_at`; `active_ms` is the union of segment windows).
- Config `tracing.transport.connect_timeout` (`AGENTICGRAPH_TRACING_CONNECT_TIMEOUT`, default 1) and `tracing.transport.timeout` (`AGENTICGRAPH_TRACING_TIMEOUT`, default 3).

### Changed
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
- A middleware that throws, before or after the node runs, now produces a failed node attempt span.
- `AGENTICGRAPH_TRACING_EXPORTER=null` selects the null exporter. Previously `env()` turned it into PHP null and the factory threw `Unknown agenticgraph tracing exporter []`.

### Removed
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
