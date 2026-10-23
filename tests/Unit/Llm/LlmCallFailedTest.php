<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\LlmCallFailed;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Nodes\PrismNode;

class LlmCallFailedProbeNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function call(Closure $build): mixed
    {
        return $build($this->llm());
    }
}

it('dispatches LlmCallFailed for a provider exception and still throws it', function () {
    Event::fake([LlmCallFailed::class, TokensUsed::class]);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'provider down']], 500)]);
    $thrown = null;
    try {
        (new LlmCallFailedProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('hi')->text());
    } catch (PrismException $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toContain('provider down');

    Event::assertNotDispatched(TokensUsed::class);
    Event::assertDispatchedTimes(LlmCallFailed::class, 1);
    Event::assertDispatched(LlmCallFailed::class, fn (LlmCallFailed $event): bool => $event->node === 'Llm Call Failed Probe'
        && $event->callType === 'text' && $event->model === 'openai:gpt-4o-mini' && $event->exception === $thrown && $event->durationMs >= 0);
});
