<?php

use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

// Test node using structured() method
class StructuredTestNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $result = $this->structured('Analyze this', new \Prism\Prism\Schema\StringSchema('result', 'analysis'));

        return $state->set('analysis', $result);
    }
}

// Test node using tools() method
class ToolsTestNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $tools = []; // Empty tools for test
        $result = $this->tools('Use a tool', $tools);

        return $state->set('tool_calls', $result);
    }
}

it('emits TokensUsed event from structured() with callType', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    // Create a fake structured response with usage
    $fake = StructuredResponseFake::make()
        ->withStructured(['result' => 'test analysis'])
        ->withUsage(new Usage(50, 30))
        ->withMeta(new Meta('id', 'model'));

    Prism::fake([$fake]);

    $node = new StructuredTestNode;
    $result = $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'structured'
            && $e->promptTokens === 50
            && $e->completionTokens === 30
            && $e->totalTokens === 80
            && $e->model !== null
            && $e->durationMs !== null;
    });
});

it('emits TokensUsed event from tools() with callType', function () {
    Event::fake();
    config()->set('agenticgraph.prism.cache_enabled', false);

    $fake = TextResponseFake::make()
        ->withText('I will use the tool')
        ->withToolCalls([])
        ->withUsage(new Usage(25, 15))
        ->withMeta(new Meta('id', 'model'));

    Prism::fake([$fake]);

    $node = new ToolsTestNode;
    $result = $node->execute(new State);

    Event::assertDispatched(TokensUsed::class, function (TokensUsed $e) {
        return $e->callType === 'tools'
            && $e->promptTokens === 25
            && $e->completionTokens === 15
            && $e->totalTokens === 40;
    });
});

it('TokensUsed event has backward compatible defaults', function () {
    $event = new TokensUsed(
        node: 'TestNode',
        promptTokens: 10,
        completionTokens: 20,
        totalTokens: 30
    );

    // Verify defaults for new optional fields
    expect($event->callType)->toBe('text');
    expect($event->model)->toBeNull();
    expect($event->durationMs)->toBeNull();
});

it('TokensUsed event accepts all new fields', function () {
    $event = new TokensUsed(
        node: 'TestNode',
        promptTokens: 10,
        completionTokens: 20,
        totalTokens: 30,
        callType: 'structured',
        model: 'openai:gpt-4o',
        durationMs: 150.5
    );

    expect($event->callType)->toBe('structured');
    expect($event->model)->toBe('openai:gpt-4o');
    expect($event->durationMs)->toBe(150.5);
});
