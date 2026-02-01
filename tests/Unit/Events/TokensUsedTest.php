<?php

use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;

class TokensNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $text = $this->prompt('Hi {name}', ['name' => 'x']);

        return $state->set('t', $text);
    }
}

it('emits TokensUsed event from PrismNode', function () {
    Event::fake();
    config()->set('agenticgraph.prism.track_tokens', true);
    config()->set('agenticgraph.prism.cache_enabled', false);
    $fake = TextResponseFake::make()->withText('hello')->withUsage(new Usage(10, 20))->withMeta(new Meta('id', 'model'));
    Prism::fake([$fake]);
    $node = new TokensNode;
    $result = $node->execute(new State);
    Event::assertDispatched(TokensUsed::class, fn (TokensUsed $e) => $e->promptTokens === 10 && $e->completionTokens === 20 && $e->totalTokens === 30);
    expect($result->get('t'))->toBe('hello');
});

it('creates tokens event with execution clock when trace context missing', function () {
    $executionId = 'exec_123';
    app()->instance(ExecutionClock::class, new ExecutionClock($executionId));

    $startTime = microtime(true);
    $event = TokensUsed::fromLlmCall(
        node: 'TestNode',
        callStartMicrotime: $startTime,
        callDurationMs: 120.5,
        promptTokens: 5,
        completionTokens: 7,
        callType: 'text',
        model: 'openai:gpt-4o'
    );

    expect($event->executionId)->toBe($executionId);
    expect($event->startedAt)->toBeInstanceOf(\DateTimeImmutable::class);
    expect($event->endedAt)->toBeInstanceOf(\DateTimeImmutable::class);
    expect($event->endedAt->getTimestamp())->toBeGreaterThanOrEqual($event->startedAt->getTimestamp());

    app()->forgetInstance(ExecutionClock::class);
});
