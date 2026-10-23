---
name: agenticgraph-tracing
description: "Trace AgenticGraph runs: tracing drivers (auto, pulse, file, log, otlp, null), segments, span types and attributes, the agenticgraph.trace.v1.1 wire format, sampling, Trace::span and TraceContext, sending traces to AgenticPulse, and testing traces."
---

# AgenticGraph Tracing

docs-sha: 34f68f1

Condensed from the AgenticGraph docs (`/docs/...` paths below) at that commit. When they disagree with the source, the source wins.

Other skills, use them when installed: `agenticgraph-core` for graphs, nodes and events. `agenticgraph-streaming` for live events while a run is going; tracing is the record afterwards. `agenticgraph-vector` for vector spans, if `shahzad/agenticgraph-vector` is installed.

## Rules

1. Tracing is the record of a run. Streaming is live progress. Do not use one in place of the other.
2. Turn tracing on in the environment (`AGENTICGRAPH_TRACING=true`), and set the exporter the same way. The exporter is built once per process, so it cannot change between two runs in the same process.
3. The wire format is `agenticgraph.trace.v1.1`. Receivers accept `agenticgraph.trace.v1` as well. Keys and values are only added, never renamed or removed.
4. Do not put secrets in span attributes, state key names, node names or exception messages: those are always sent. Keep capture off unless you have decided what may be stored.
5. The defaults keep structure, not content: `capture.state=keys` sends state key names and value types. `diff`, `full` and `error_only` send state values; `full` on any capture is your choice to store content.

## Task: turn tracing on

```dotenv
AGENTICGRAPH_TRACING=true
AGENTICGRAPH_SERVICE_NAME=my-app
```

- `AGENTICGRAPH_TRACING` (`tracing.enabled`, default `false`) is read on each event and at the start of each run, so a runtime change applies to the next run. Off means no spans and no exporter work. The exporter driver is different: it is picked once, when the exporter is first resolved.
- With no API key, the default driver writes the trace to the Laravel log as an `agenticgraph.trace` line.
- Streaming is a different pipe: tokens while the run happens, not this tree.

Docs: `/docs/tracing`

## Task: choose a driver

Set `AGENTICGRAPH_TRACING_EXPORTER`.

| Driver | What it does |
|---|---|
| `auto` | Default. `null` in unit tests, `pulse` when `AGENTICPULSE_API_KEY` is set, otherwise `log`. |
| `pulse` | POST to AgenticPulse |
| `log` | `agenticgraph.trace` JSON on the Laravel log. Only when `AGENTICGRAPH_LOGGING` is on (default `true`). |
| `file` | One JSON file per segment: `{path}/{trace_id}/{Ymd-His-u}.json`. `AGENTICGRAPH_TRACING_FILE_PATH`, default `storage/app/agenticgraph/traces`. |
| `null` | Instrumentation runs, nothing is sent |
| `otlp` | OTLP/HTTP JSON to `AGENTICGRAPH_OTLP_ENDPOINT` |

- An explicit driver always wins over `auto`, in tests too. An unknown name throws when the exporter is built.
- `pulse`, `log` and `file` send the same envelope. `otlp` sends OTLP spans.

Docs: `/docs/tracing`, `/docs/api/tracing`

### AgenticPulse

```dotenv
AGENTICGRAPH_TRACING=true
AGENTICPULSE_API_KEY=ap_live_...
AGENTICGRAPH_CAPTURE_STATE=diff
AGENTICPULSE_ENDPOINT=https://ingest.agenticgraph.dev
```

- Keys are `ap_live_...` (production) or `ap_test_...` (staging, local), from https://pulse.agenticgraph.dev.
- `AGENTICGRAPH_CAPTURE_STATE=diff` sends the old and new values of each changed state key, so Pulse can show them. Leave it out to send key names only.
- With a key set, `auto` picks `pulse`. Each segment is `POST {endpoint}/v1/ingest` with `Authorization: Bearer {key}`.
- `AGENTICGRAPH_TRACING_CONNECT_TIMEOUT` (1 s) and `AGENTICGRAPH_TRACING_TIMEOUT` (3 s per attempt).
- A connection error or 5xx is retried once after 500 ms. A 4xx is never retried. A send never throws into your app.
- A failure logs `AgenticPulse ingest failed` with the `code`, or `connection_error`.

Docs: `/docs/tracing#agenticpulse`

### OTLP

```dotenv
AGENTICGRAPH_TRACING=true
AGENTICGRAPH_TRACING_EXPORTER=otlp
AGENTICGRAPH_OTLP_ENDPOINT=http://localhost:4318/v1/traces
AGENTICGRAPH_OTLP_PROTOCOL=http/json
AGENTICGRAPH_OTLP_HEADERS_JSON={"Authorization":"Bearer token"}
```

- Only `http/json`. Another protocol logs `agenticgraph.otlp.unsupported_protocol` and skips the POST. An empty endpoint disables the exporter.
- Header shortcuts: `AGENTICGRAPH_OTLP_HONEYCOMB_API_KEY` (`x-honeycomb-team`), `AGENTICGRAPH_OTLP_DATADOG_API_KEY` (`dd-api-key`).
- Resource: `service.name`, `service.version` (`AGENTICGRAPH_SERVICE_VERSION`), `deployment.environment.name` (`APP_ENV`).
- LLM spans map to `gen_ai.*` (operation, request model, provider, usage tokens, request stream). Tool spans get `gen_ai.operation.name = execute_tool`. Cost and totals stay `agenticgraph.*`. Cached calls carry no `gen_ai.*` request or usage attributes.

Docs: `/docs/tracing#otlp`.

## Task: read the span tree

When a run is traced, each segment holds its own spans and LLM calls.

```text
graph
├── node: research
│   ├── llm: research llm
│   └── tool: search_web
│       ├── http: GET …
│       └── db: SELECT …
├── route: research route
├── node: draft (attempt 1, failed)
├── node: draft (attempt 2)
├── interrupt
└── resume
```

- One trace per run, keyed by the execution id. The root is the `graph` span.
- Node, route, interrupt and resume spans are direct children of the root. LLM and tool spans nest under their node. HTTP and DB nest under the tool or node.

| `span_type` | What it is | Notable fields |
|---|---|---|
| `graph` | The whole run | `error` on failure |
| `node` | One `execute()` attempt | `attempt`, `attempt_of`, `state_diff`, `loop_context` |
| `route` | A conditional edge or a join decision | `route.*` attributes |
| `llm` | A model call | `model`, token counts, `cost_usd`, `cached`, `streamed` |
| `tool` | A tool call made through `$this->llm()` with `tools()` or `mcp()` | `tool.type`, `tool.provider`, args and result per capture depth |
| `http` / `db` | Inside a tool or node body | `http.*`, `url.*`, `laravel.http.*`; `db.*`, `laravel.db.*` |
| `vector` | A vector store operation | `vector.*`, sent by `shahzad/agenticgraph-vector` |
| `interrupt` / `resume` | Human pause and continuation | `interrupt.reason`, `checkpoint.id` |
| `custom` | `Trace::span()` | your attributes |

- **Attempts.** A retried node produces one span per attempt. Each has `attempt` (1, 2, …). Later attempts carry `attempt_of`, the id of attempt 1. A node that fails in middleware still produces a failed attempt span.
- **Route spans.** `node_name` is `"{from} route"`. `route.kind` is `llm` (PrismRouter), `deterministic` (any other router, one target), `fan_out` (several targets) or `join`. `route.from_cache` and `route.fallback` describe PrismRouter answers. Static edges have no route span.
- **Attributes.** Top level holds only the common keys, the LLM fields and `state_diff`, `loop_context`, `error`, `state_snapshot`. Everything else is in one `attributes` object with dotted names. OTel names (`http.*`, `db.*`, `url.*`, `gen_ai.*`) are used only where the meaning matches; the rest are `route.*`, `tool.*`, `vector.*`, `interrupt.*`, `checkpoint.*`, `laravel.*`, `agenticgraph.*`.
- **Status.** `status_code` is `OK`, `ERROR` or `PAUSED` on every span. An HTTP status is `attributes["http.response.status_code"]`.
- **Streamed.** `streamed` is true on an LLM span and its `llm_calls` row when the call was streamed.
- Tools added to raw `prism()->withTools()` get no tool span. The package's own queries and requests are never spans.

Docs: `/docs/tracing#span-tree`.

## Task: know when a trace is sent

- Spans stay in memory in the process that made them. That process sends them as one **segment** when the run completes or fails, when a node interrupts, when a queued job for the run ends, or when an execution scope exits mid-trace.
- Segment kinds: `initial` (run start), `resumed` (after an interrupt), `continued` (queue jobs). At most one segment is `final`: the completed or failed one. A paused trace has none yet.
- The receiver merges segments by trace id. Nothing is repeated across segments.
- The listener is synchronous. No queue worker is involved. In an HTTP request the send runs after the response; in console, queue and tests it runs immediately.
- There is no trace buffer in the cache and no `tracing.buffer` config.

Docs: `/docs/tracing#when-traces-are-sent`.

## Task: read the wire format

- Envelope: `schema_version`, `trace`, `segment`, `spans`, `llm_calls`, and `_meta` only when truncated.
- Current `schema_version` is `agenticgraph.trace.v1.1` (earlier releases sent `agenticgraph.trace.v1`). Keys are only added.
- Every timestamp is UTC with six fraction digits: `2026-09-27T14:03:11.482913+00:00`.
- Payloads over the size limit are truncated, and `_meta` records what was dropped.
- The Pulse exporter's payload is an internal, versioned format between AgenticGraph and AgenticPulse. To send traces to your own backend, use OTLP.
- The main fields of a trace file: [references/wire-format.md](references/wire-format.md).

Docs: `/docs/tracing#wire-contract`.

## Task: add a custom span

```php
use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Tracing\TraceContext;

$result = Trace::span(
    name: 'enrich_context',
    callback: fn () => $crm->enrich($state->get('customer_id')),
    attributes: ['source' => 'crm'],
);

$context = TraceContext::tryGet();
if ($context !== null && $context->isEnabled()) {
    $executionId = $context->getExecutionId();
    $spanId = $context->getSpanId();
}
```

- Inside a running graph, the span is a child of the current span, usually the node. Outside one, the callback just runs.
- If the callback throws, the span closes as `failed` / `ERROR` and the exception is re-thrown.
- `$attributes` is taken by reference. Through the facade a literal array works. On `tool` spans the tracer merges the captured result into it.
- Use `TraceContext::tryGet()`, not `app(TraceContext::class)`. It returns `null` when no run is active.

Docs: `/docs/tracing#tracespan`, `/docs/api/tracing`

## Task: set capture and PII

| Env | Default |
|---|---|
| `AGENTICGRAPH_CAPTURE_STATE` | `keys`: changed top-level key names and value types, no values. `diff`: old and new values of changed keys. `full`: whole state per node, initial and final state. `error_only`: keys, plus the full state at a failure. `none`. |
| `AGENTICGRAPH_CAPTURE_PROMPTS` / `AGENTICGRAPH_CAPTURE_RESPONSES` | `false`. Prompts include embedding input text. |
| `AGENTICGRAPH_CAPTURE_MAX_LENGTH` | `2000` characters, for prompts, responses, attributes, tool args and results, SQL and state values |
| `AGENTICGRAPH_CAPTURE_ERRORS` | `message`: exception class and message. `class`: class only. |
| `AGENTICGRAPH_CAPTURE_HTTP` / `AGENTICGRAPH_CAPTURE_DB` | `false`. HTTP and DB outside tool spans. |
| `AGENTICGRAPH_CAPTURE_TOOL_ARGS` | `summary` (`none`, `summary`, `full`) |
| `AGENTICGRAPH_CAPTURE_TOOL_RESULTS` | `none` |
| `AGENTICGRAPH_CAPTURE_HTTP_URLS` | `summary`. Path without query string. |
| `AGENTICGRAPH_CAPTURE_DB_SQL` | `summary`. Operation and table. |
| `AGENTICGRAPH_DB_SLOW_THRESHOLD_MS` | `1000` |

- Summary modes keep structure, not content (rule 5).
- Always sent, whatever the capture: state key names, node and graph names, router class names (`route.router`), exception classes, `context.ids` (first 20), `prompt.name` / `prompt.version` / `prompt.hash`, `cache_key`, `interrupt.reason`, `Trace::span()` attributes, service name and version, `APP_ENV`.
- Every driver gets the same content: `file` writes it to disk, `log` to your log channel (what `auto` does with no API key), `pulse` to AgenticPulse. `otlp` gets the spans, `state_diff` included (so `diff` values become vendor attributes), but no prompts, responses, initial or final state.
- With `http_urls=full`, userinfo and known secret query parameters are replaced with `REDACTED`. Nothing else is scanned.
- HTTP and DB inside a tool call nest under the tool span even with broad capture off.

Docs: `/docs/tracing#capture-and-pii`

## Task: sample and price

- `AGENTICGRAPH_SAMPLE_RATE` (`tracing.sampling.rate`, 0.0 to 1.0, default 1.0).
- The decision is deterministic per trace id (`crc32(trace_id) / 0xFFFFFFFF < rate`), so every process sending a segment of a trace agrees.
- `tracing.sampling.always_on_error` (default `true`) still sends a failed trace the rate dropped. In v1 it arrives with `trace.sampled: false`, its header and error, and no spans.
- LLM spans get `cost_usd` from `config/model_pricing.php` (USD per 1M tokens). `php artisan agenticgraph:pricing:sync` refreshes it from OpenRouter; use `--fixture=` in CI. Hand-set prices go in `config/model_pricing_overrides.php`: they win over the table and the sync never writes that file. Unknown models cost `model_pricing.fallback_cost_usd` (0.0). Cached calls cost nothing.

Docs: `/docs/tracing#sampling`, `/docs/tracing#cost`.

## Task: test traces

- `auto` is `null` in unit tests, so by default tests send nothing.
- Setting `tracing.enabled` at runtime in a test works: the package's own tests run a graph after the flag changes and read the spans. The driver does not change with it. The exporter is a singleton that keeps the driver it resolved first, so a test that changes the driver must rebind it (see the reference).
- Under `auto` in tests the driver is `null`, so changing only the flag writes nothing.
- The send is immediate in tests, so the file exists when `run()` returns.
- `Event::fake()` swallows the tracing listener. No trace is sent.
- Example: [references/testing-traces.md](references/testing-traces.md).

Docs: `/docs/guide-testing`, `/docs/tracing`

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing anywhere | `AGENTICGRAPH_TRACING=true`, and the exporter is not `null` |
| Expected AgenticPulse, got the log | `auto` without `AGENTICPULSE_API_KEY` is `log` |
| Nothing in the log | `AGENTICGRAPH_LOGGING=false` silences the `log` driver |
| Nothing in tests | `auto` is `null` in tests; see Task: test traces |
| Pulse sends fail | `AgenticPulse ingest failed` warning and its `code` |
| OTLP silent | Empty endpoint, or a protocol other than `http/json` |
| No tool spans | Tools attached with raw `prism()->withTools()` |

Docs: `/docs/guide-troubleshooting`, `/docs/tracing`
