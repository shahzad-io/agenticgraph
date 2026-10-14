# Stream client examples

Docs: `/docs/stream-getting-started`, `/docs/stream-core`, `/docs/stream-react`, `/docs/stream-vue`, `/docs/stream-transports`, `/docs/stream-limitations`

```bash
npm install @agenticgraph/stream-react   # React, pulls stream-client
npm install @agenticgraph/stream-vue     # Vue 3, pulls stream-client
npm install @agenticgraph/stream-client  # vanilla
```

Broadcast also needs `laravel-echo`, `pusher-js` and a configured Echo instance. SSE needs neither.

## The server side

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
        'stream_url' => $sse ? $handle->stream()->streamUrl : null,
        'channel' => $handle->broadcastChannel(),
    ]);
}

public function resume(Request $request, string $executionId): JsonResponse
{
    $result = AgenticGraph::resume($executionId, $request->only(['approved', 'comments']));

    return response()->json(['status' => $result->handle->refresh()->status()->value]);
}
```

## React

Create the transport after kickoff and pass it to one component. The hook owns the connection.

```tsx
import { useCallback, useMemo } from 'react';
import { EchoTransport, SSETransport, type StreamTransport } from '@agenticgraph/stream-client';
import { useAgenticStream, useStreamSelector, nodeTextSelector } from '@agenticgraph/stream-react';

function StreamPanel({ transport, executionId }: { transport: StreamTransport; executionId: string }) {
  const onResume = useCallback(async (decision: unknown) => {
    const response = await fetch(`/drafts/${executionId}/resume`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(decision),
    });
    if (!response.ok) throw new Error('Resume failed.');
  }, [executionId]);

  const { text, status, error, interrupt, resume, client } = useAgenticStream({ transport, onResume });
  const draftText = useStreamSelector(client, useMemo(() => nodeTextSelector('generate_draft'), []));

  if (status === 'interrupted' && interrupt) {
    const review = interrupt.payload.interrupt_payload as { draft?: string } | undefined;
    return (
      <section>
        <p>{interrupt.reason}</p>
        <p>{review?.draft}</p>
        <button type="button" onClick={() => void resume({ approved: true })}>Approve</button>
      </section>
    );
  }
  if (status === 'error') return <p>{error?.kind}</p>;
  if (status === 'complete') return <p>{text}</p>;
  return <p>{draftText || 'Streaming…'}</p>;
}

async function start(delivery: 'sse' | 'broadcast', topic: string) {
  const payload = await (await fetch('/drafts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ topic, delivery }),
  })).json();

  const transport = delivery === 'sse'
    ? new SSETransport({ url: payload.stream_url })
    : new EchoTransport({ echo: window.Echo, channel: payload.channel });

  return { transport, executionId: payload.execution_id };
}
```

- `useAgenticStream` returns `text`, `status`, `error`, `interrupt`, `resume`, `lastEvent`, `client`.
- `useStreamSelector(client, selector)` recomputes as events arrive. Memoize selector factories.
- Selectors: `nodeTextSelector(node)`, `activeNodeSelector`, `thinkingSelector`, `toolCallSelector`.

## Vue

Same API. Values are refs; `client` and `lastEvent` are shallow refs. `useStreamSelector` takes the `client` ref and returns a ref.

```vue
<script setup lang="ts">
import type { StreamTransport } from '@agenticgraph/stream-client';
import { useAgenticStream, useStreamSelector, nodeTextSelector } from '@agenticgraph/stream-vue';

const props = defineProps<{ transport: StreamTransport; executionId: string }>();

async function onResume(decision: unknown): Promise<void> {
  const response = await fetch(`/drafts/${props.executionId}/resume`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(decision),
  });
  if (!response.ok) throw new Error('Resume failed.');
}

const { text, status, interrupt, resume, client } = useAgenticStream({ transport: props.transport, onResume });
const draftText = useStreamSelector(client, nodeTextSelector('generate_draft'));
</script>

<template>
  <section v-if="status === 'interrupted' && interrupt">
    <p>{{ interrupt.reason }}</p>
    <button type="button" @click="resume({ approved: true })">Approve</button>
  </section>
  <p v-else-if="status === 'complete'">{{ text }}</p>
  <p v-else>{{ draftText || 'Streaming…' }}</p>
</template>
```

Do not call `AgenticStream.connect()` in the parent.

## Vanilla

```ts
import { AgenticStream, EchoTransport } from '@agenticgraph/stream-client';

const stream = await AgenticStream.connect({
  transport: new EchoTransport({ echo: window.Echo, channel: payload.channel }),
});

const unsubscribe = stream.subscribe('text_delta', (event) => render(event.payload.delta));

for await (const event of stream.events()) {
  if (event.eventType === 'graph_complete') break;
}

unsubscribe();
await stream.disconnect();
```

`events()` has no replay: it yields from the moment you start iterating.

## Tests

```ts
import { AgenticStream, MockTransport } from '@agenticgraph/stream-client';

const transport = new MockTransport();
const stream = await AgenticStream.connect({ transport });

transport.emitSequence(events);   // StreamEvent objects
transport.simulateDrop();         // connection-drop handling
```
