# Interrupt examples

Docs: `/docs/interrupts`, `/docs/checkpoints`, `/docs/api/execution`

## The gate

The check runs before `execute()`, so the gate pauses on what earlier nodes wrote. The gate node itself is usually a stub. Setting `approval_status` in `onResume()` stops the same visit from pausing again.

```php
namespace App\Nodes;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

class AwaitApprovalNode extends PrismNode
{
    protected ?string $name = 'await_approval';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('approval_gate_executed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return $state->get('approval_status') !== 'resolved';
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Draft requires human approval';
    }

    public function getInterruptPayload(StateContract $state): mixed
    {
        return ['draft' => $state->get('draft'), 'score' => $state->get('score')];
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval(
            $state,
            (bool) ($humanInput['approved'] ?? false),
            is_string($humanInput['comments'] ?? null) ? $humanInput['comments'] : null,
        )->set('approval_status', 'resolved');
    }
}
```

A router after the gate reads `approved` and names the next node. Return false from `shouldInterrupt()` for cases that need no human. `interrupts: false` on the options skips every gate; it does not approve anything.

## Start and resume over HTTP

```php
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\State\State;

public function start(Request $request): JsonResponse
{
    $handle = AgenticGraph::run('evaluator-optimizer', new State(['topic' => $request->string('topic')]));

    if (! $handle->isInterrupted()) {
        return response()->json(['execution_id' => $handle->id(), 'state' => $handle->getFinalState()->toArray()]);
    }

    $details = AgenticGraph::interruptDetails($handle->id());

    return response()->json([
        'execution_id' => $handle->id(),
        'reason' => $details->interrupt?->reason(),
        'node' => $details->interrupt?->nodeName(),
        'payload' => $details->interrupt?->interruptPayload(),
    ]);
}

public function resume(Request $request, string $executionId): JsonResponse
{
    $validated = $request->validate([
        'approved' => ['required', 'boolean'],
        'comments' => ['nullable', 'string'],
    ]);

    $result = AgenticGraph::resume($executionId, $validated);
    $handle = $result->handle->refresh();

    return response()->json([
        'status' => $handle->status()->value,
        'already_resolved' => $result->alreadyResolved,
    ]);
}
```

- The resume body must match what `onResume()` reads. The default `onResume()` merges an array into state.
- The resumed handle may be completed or interrupted again.
- `AgenticGraph::resume()` throws `InterruptPersistenceDisabledException` when `interrupts.persistence_enabled` is off. A sync handle in the same request can still call `$handle->resume($input)`.
- An async handle's `resume()` dispatches `ResumeExecutionJob` and returns while it runs.
