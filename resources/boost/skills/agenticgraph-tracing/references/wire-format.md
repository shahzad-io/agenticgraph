# What a trace file holds

The `pulse`, `file` and `log` exporters send the same JSON. The Pulse exporter's payload is an internal, versioned format between AgenticGraph and AgenticPulse: read a `file` or `log` trace to debug, but do not build a receiver on it. To send traces to your own backend, use OTLP (`AGENTICGRAPH_TRACING_EXPORTER=otlp`).

## Top level

`schema_version` (`agenticgraph.trace.v1.1`), `trace`, `segment`, `spans`, `llm_calls`, and `_meta` only when the payload was truncated.

- `trace`: the run. `id` (the execution id), `graph_name`, `status` (`running`, `interrupted`, `completed`, `failed`), `sampled`, `resource` (`service.name`, `service.version`, `deployment.environment.name`), `error` on failure, `duration_ms` once the run ends.
- `segment`: which part of the run this file is. `kind` is `initial`, `resumed` (after an interrupt) or `continued` (a queued job); `final` is true on the completed or failed one. A run that pauses or queues work is sent as several segments with the same `trace.id`.
- Every timestamp is UTC with six fraction digits: `2026-09-27T14:03:11.482913+00:00`.

## Spans

Every span has `id`, `parent_id`, `node_name`, `span_type`, `status`, `status_code` (`OK`, `ERROR`, `PAUSED`), `started_at`, `ended_at`, `duration_ms`. What else it carries:

| `span_type` | Main fields |
|---|---|
| `graph` | the root; `error` on failure |
| `node` | `attempt`, `state_diff`, `loop_context`, `error` |
| `llm` | `model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost_usd`, `cached`, `streamed` |
| `route` | `attributes`: `route.from`, `route.targets`, `route.kind` |
| `tool` | `attributes`: `tool.type`, sizes, and args or results per capture depth |
| `http`, `db` | `attributes`: method and status, or statement type, per capture depth |
| `vector` | `attributes`: `vector.*` counts and timings, never query text |
| `interrupt`, `resume` | `attributes`: `interrupt.reason`, `checkpoint.id` |
| `custom` | whatever you passed to `Trace::span()` |

`state_diff.values` depends on `capture.state`: `keys` (default) sends each changed key's change type and value type, `diff` and `full` send values, `none` sends nothing.

## llm_calls

One row per LLM call: `model`, tokens, `cost_usd`, `duration_ms`, `streamed`, `cached`, and `prompt` / `response` only when `capture.prompts` / `capture.responses` are on.

## Size

Large payloads are cut down before they are sent (long responses and prompts first, then spans). `_meta` says what was dropped.
