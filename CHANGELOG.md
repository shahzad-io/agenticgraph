# Changelog

## Unreleased

### Added
- `file` tracing exporter (`AGENTICGRAPH_TRACING_EXPORTER=file`): one JSON file per flushed segment at `{tracing.file.path}/{trace_id}/{Ymd-His-u}.json`. New config key `tracing.file.path` (`AGENTICGRAPH_TRACING_FILE_PATH`).
- `schema_version: agenticgraph.trace.v1` as the first key of every trace envelope. Draft wire description in `docs/tracing-wire-v1.md`.

### Changed
- Pulse, file and log exporters send one envelope built by `TracePayloadBuilder`, with the same buffering, sampling, interrupt and resume lifecycle (`BufferedTraceExporter`).
- The log exporter now logs the full envelope (message `agenticgraph.trace`) and keeps resumed traces whole across processes.
- In an HTTP request, every buffered exporter sends after the response (previously only pulse).
- A payload over 10 MB keeps its real `trace.status`. The reason moves to `_meta.error_reason = payload_too_large`, with dropped counts in `_meta.dropped`.

### Fixed
- A middleware that throws, before or after the node runs, now produces a failed node attempt span.

### Removed
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
