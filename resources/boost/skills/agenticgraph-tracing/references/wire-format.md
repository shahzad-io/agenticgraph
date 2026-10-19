# agenticgraph.trace.v1.1 in brief

The contract is `spec/tracing-wire-v1.md` in this package, locked by `TraceWireContractFreezeTest`. This page summarizes it; read the spec before writing a receiver or changing the payload. Any addition updates the spec's key registry and tables.

## Envelope

Keys in order: `schema_version`, `trace`, `segment`, `spans`, `llm_calls`, and `_meta` only when truncated. `schema_version` is always `agenticgraph.trace.v1.1` (`agenticgraph.trace.v1` before the v1.1 amendment).

```json
{
  "schema_version": "agenticgraph.trace.v1.1",
  "trace": {"id": "exec_…", "status": "completed", "sampled": true, "graph_name": "content", "resource": {"service.name": "my-app", "service.version": "unknown", "deployment.environment.name": "production"}, "instrumentation_scope": "agenticgraph"},
  "segment": {"id": "seg_0123456789abcdef", "kind": "initial", "final": true, "started_at": "2026-09-27T14:03:11.482913+00:00", "ended_at": "2026-09-27T14:03:12.104551+00:00"},
  "spans": [{"id": "…", "trace_id": "exec_…", "parent_id": null, "node_name": "content", "node_type": "graph", "span_type": "graph", "status": "success", "span_kind": "…", "status_code": "OK", "started_at": "…", "ended_at": "…", "duration_ms": 621.6}],
  "llm_calls": []
}
```

- `trace.status`: `running` (non-final segment), `interrupted`, `completed`, `failed`.
- Every timestamp is UTC with six fraction digits and `+00:00`.
- `pulse` and `file` keep a zero fraction (`0.0`). Treat numeric fields as numbers.

## Segments and merging

- `segment.kind`: `initial`, `resumed`, `continued`. `final` is true only on the completed or failed segment.
- A running trace has no root graph span yet. Its spans share the root's id as `parent_id`.
- A receiver merges by trace id (reference implementation: `TraceSegmentMerger`):
  - a segment id already seen replaces the earlier one;
  - segments are ordered by `segment.started_at`, then `segment.id`; the final segment applies last;
  - trace fields merge shallowly, key by key; spans and LLM calls are upserted by `id`;
  - `duration_ms` and `active_ms` are recomputed. `active_ms` is the union of segment windows, so human waits and queue gaps are excluded.
- The merged shape replaces `segment` with `segments`, the list of every segment block. A segment that ended interrupted keeps `interrupt` (`{reason, at}`) there, because the trace-level interrupt keys are removed once the run goes on; the wait is the next segment's `started_at` minus `interrupt.at`.

## Spans

Common keys: `id`, `trace_id`, `parent_id`, `node_name`, `node_type`, `span_type`, `status`, `span_kind`, `status_code`, `started_at`, `ended_at`, `duration_ms`.

| `span_type` | Top level | `attributes` |
|---|---|---|
| `graph` | `error` | none |
| `node` | `attempt`, `attempt_of`, `state_diff`, `loop_context`, `error` | none |
| `llm` | `model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost_usd`, `cached`, `streamed` | none |
| `route` | none | `route.from`, `route.targets`, `route.router`, `route.kind`, `route.from_cache`, `route.fallback`, `route.reason` |
| `tool` | `error` | `tool.type`, `tool.provider`, `tool.input_size`, `tool.output_size` (every capture depth), `tool.request_id` and `gen_ai.tool.call.id` (provider-executed calls only), `tool.arg_keys`, `tool.args`, `tool.result_size`, `tool.result` |
| `db` | none | `laravel.db.connection`, `laravel.db.statement_type`, `db.operation.name`, `laravel.db.bindings_count`, `laravel.db.query_summary`, `db.query.text` |
| `http` | `error` | `http.request.method`, `http.response.status_code`, `laravel.http.response_size`, `laravel.http.url_summary`, `url.full` |
| `vector` | `error` | any `vector.*` key |
| `interrupt` | `state_snapshot` | `interrupt.reason`, `checkpoint.id` |
| `resume` | `state_snapshot` | `checkpoint.id` |
| `custom` | `error` | whatever the caller passed |

`route.kind` is `llm`, `deterministic`, `fan_out` or `join`. For a join, `route.from` is the sorted branch names joined with `|` and `route.router` is null.

## llm_calls

`id`, `trace_id`, `span_id`, `node`, `call_type`, `model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `duration_ms`, `created_at`, `cost_usd`, `streamed`, plus `prompt` / `response` when capture is on, and `cached` / `cache_key` on cache hits.

## Size limits

- Over 1 MB the builder shrinks in order and stops when it fits: LLM responses, LLM prompts, spans (the root stays, plus the first 50 and last 50), state values.
- Over 10 MB it keeps the full `trace` with its real status and empties `spans` and `llm_calls`. `_meta.error_reason` is `payload_too_large`.
- `_meta` keys: `truncated`, `truncation_applied`, `original_size_bytes`, `dropped`, `truncated_at`, and `error_reason` on the hard limit only.
- A kept span whose parent was dropped attaches to the root.
