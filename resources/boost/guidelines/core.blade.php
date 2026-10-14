## AgenticGraph

- AgenticGraph runs agent graphs in Laravel: nodes do the work, edges and routers pick the next node, and immutable state carries the run.
- IMPORTANT: Activate `agenticgraph-core` when building or changing graphs, nodes, state, routers, interrupts, checkpoints, MCP tools, middleware, lifecycle events, the CLI, or tests for them.
- IMPORTANT: Activate `agenticgraph-tracing` when working with traces, spans, tracing drivers, AgenticPulse, OTLP, `Trace::span`, sampling, or tests that assert on traces.
- IMPORTANT: Activate `agenticgraph-streaming` when using `AgenticGraph::stream()` or `AgenticGraph::broadcast()`, `StreamingMode`, SSE or broadcast delivery, stream events, or the stream-client, stream-react and stream-vue JavaScript packages.
- Activate `agenticgraph-vector` for retrieval and vector stores, if `shahzad/agenticgraph-vector` is installed.
