<?php

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent as PrismStreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\NodeStreamAccumulator;
use Shahzad\AgenticGraph\Streaming\PrismStreamAdapter;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

test('stream event implements contract and serializes', function () {
    $event = new StreamEventImpl(
        streamId: 'stream-1',
        executionId: 'exec-1',
        nodeName: 'node-a',
        spanId: 'span-1',
        parentSpanId: 'span-0',
        type: 'text_delta',
        payload: ['delta' => 'hi'],
        seq: 1,
        nodeSeq: 2,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );

    expect($event)->toBeInstanceOf(StreamEvent::class);

    $copy = unserialize(serialize($event));

    expect($copy)->toBeInstanceOf(StreamEventImpl::class)
        ->and($copy->streamId())->toBe('stream-1')
        ->and($copy->executionId())->toBe('exec-1')
        ->and($copy->nodeName())->toBe('node-a')
        ->and($copy->spanId())->toBe('span-1')
        ->and($copy->parentSpanId())->toBe('span-0')
        ->and($copy->type())->toBe('text_delta')
        ->and($copy->seq())->toBe(1)
        ->and($copy->nodeSeq())->toBe(2)
        ->and($copy->occurredAt()->format('c'))->toBe('2024-01-01T00:00:00+00:00')
        ->and($copy->payload())->toBe(['delta' => 'hi']);
});

test('prism stream adapter maps text delta events', function () {
    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $adapter = new PrismStreamAdapter(
        session: $session,
        nodeName: 'TestNode',
        spanId: 'span-1',
        parentSpanId: 'span-0'
    );

    $event = new TextDeltaEvent('evt-1', 123, 'Hello', 'msg-1');
    $mapped = $adapter->adapt($event);

    expect($mapped)->not->toBeNull()
        ->and($mapped->type())->toBe('text_delta')
        ->and($mapped->payload())->toMatchArray([
            'delta' => 'Hello',
            'message_id' => 'msg-1',
            'timestamp' => 123,
        ])
        ->and($mapped->seq())->toBe(1);
});

test('prism stream adapter maps tool call events', function () {
    $session = new StreamSession(
        streamId: 'stream-2',
        executionId: 'exec-2',
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $adapter = new PrismStreamAdapter(
        session: $session,
        nodeName: 'TestNode',
        spanId: 'span-2',
        parentSpanId: 'span-1'
    );

    $toolCall = new ToolCall('tool-1', 'search', ['query' => 'hi']);
    $event = new ToolCallEvent('evt-2', 124, $toolCall, 'msg-2');
    $mapped = $adapter->adapt($event);

    expect($mapped)->not->toBeNull()
        ->and($mapped->type())->toBe('tool_call')
        ->and($mapped->payload())->toMatchArray([
            'tool_id' => 'tool-1',
            'tool_name' => 'search',
            'message_id' => 'msg-2',
            'reasoning_id' => null,
        ]);
});

test('prism stream adapter maps thinking delta events', function () {
    $session = new StreamSession(
        streamId: 'stream-3',
        executionId: 'exec-3',
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $adapter = new PrismStreamAdapter(
        session: $session,
        nodeName: 'TestNode',
        spanId: 'span-3',
        parentSpanId: 'span-2'
    );

    $event = new ThinkingEvent('evt-3', 125, 'thinking', 'reason-1', ['summary' => 'done']);
    $mapped = $adapter->adapt($event);

    expect($mapped)->not->toBeNull()
        ->and($mapped->type())->toBe('thinking_delta')
        ->and($mapped->payload())->toMatchArray([
            'id' => 'reason-1',
            'delta' => 'thinking',
            'summary' => ['summary' => 'done'],
        ]);
});

test('prism stream adapter ignores unknown events', function () {
    $session = new StreamSession(
        streamId: 'stream-4',
        executionId: 'exec-4',
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $adapter = new PrismStreamAdapter(
        session: $session,
        nodeName: 'TestNode',
        spanId: null,
        parentSpanId: null
    );

    $event = new readonly class('evt-x', 126) extends PrismStreamEvent
    {
        public function type(): StreamEventType
        {
            return StreamEventType::TextDelta;
        }

        public function toArray(): array
        {
            return [];
        }
    };

    expect($adapter->adapt($event))->toBeNull();
});

test('prism stream adapter maps stream start and stream end to their own events', function () {
    $session = new StreamSession(
        streamId: 'stream-5',
        executionId: 'exec-5',
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $adapter = new PrismStreamAdapter(
        session: $session,
        nodeName: 'TestNode',
        spanId: 'span-5',
        parentSpanId: 'span-4'
    );

    $start = new StreamStartEvent('evt-5', 200, 'gpt-4', 'openai');
    $startMapped = $adapter->adapt($start);

    $end = new StreamEndEvent('evt-6', 210, FinishReason::Stop, new Usage(3, 4));
    $endMapped = $adapter->adapt($end);

    expect($startMapped)->not->toBeNull()
        ->and($startMapped->type())->toBe('stream_start')
        ->and($startMapped->payload())->toMatchArray([
            'id' => 'evt-5',
            'timestamp' => 200,
            'model' => 'gpt-4',
            'provider' => 'openai',
        ])
        ->and($endMapped)->not->toBeNull()
        ->and($endMapped->type())->toBe('stream_end')
        ->and($endMapped->payload())->toMatchArray([
            'id' => 'evt-6',
            'timestamp' => 210,
            'finish_reason' => 'Stop',
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => null, 'thought_tokens' => null],
        ]);
});

test('prism stream adapter maps step start and step finish, with the step usage and finish reason', function () {
    $session = new StreamSession(
        streamId: 'stream-6',
        executionId: 'exec-6',
        options: new StreamOptions,
        startedAtUnix: time()
    );
    $adapter = new PrismStreamAdapter(
        session: $session,
        nodeName: 'TestNode',
        spanId: 'span-6',
        parentSpanId: 'span-5'
    );

    $stepStart = new StepStartEvent('step-start-1', 300);
    $stepFinish = new StepFinishEvent('step-finish-1', 310, new Usage(7, 2), finishReason: FinishReason::ToolCalls);
    $textDelta = new TextDeltaEvent('evt-delta', 305, 'Hello', 'msg-1');

    $startMapped = $adapter->adapt($stepStart);
    $finishMapped = $adapter->adapt($stepFinish);
    $deltaMapped = $adapter->adapt($textDelta);

    expect($startMapped)->not->toBeNull()
        ->and($startMapped->type())->toBe('step_start')
        ->and($startMapped->payload())->toMatchArray([
            'id' => 'step-start-1',
            'timestamp' => 300,
        ])
        ->and($finishMapped)->not->toBeNull()
        ->and($finishMapped->type())->toBe('step_finish')
        ->and($finishMapped->payload())->toBe([
            'id' => 'step-finish-1',
            'timestamp' => 310,
            'finish_reason' => 'ToolCalls',
            'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => null, 'thought_tokens' => null],
        ])
        ->and($deltaMapped)->not->toBeNull()
        ->and($deltaMapped->type())->toBe('text_delta')
        ->and($deltaMapped->payload()['delta'])->toBe('Hello');
});

test('node stream accumulator rebuilds text and tool calls', function () {
    $state = new State;
    $accumulator = new NodeStreamAccumulator($state);

    $accumulator->apply(makeStreamEvent('text_start', ['message_id' => 'msg-1']));
    $accumulator->apply(makeStreamEvent('text_delta', ['message_id' => 'msg-1', 'delta' => 'Hello ']));
    $accumulator->apply(makeStreamEvent('text_delta', ['message_id' => 'msg-1', 'delta' => 'world']));
    $accumulator->apply(makeStreamEvent('tool_call_delta', ['tool_id' => 'tool-1', 'delta' => '{"query":"hi"}']));
    $accumulator->apply(makeStreamEvent('tool_call', [
        'tool_id' => 'tool-1',
        'tool_name' => 'search',
        'message_id' => 'msg-1',
        'arguments' => null,
    ]));

    $result = $accumulator->finalize();

    expect($result->get('text'))->toBe('Hello world')
        ->and($result->get('tool_calls'))->toBe([
            [
                'tool_id' => 'tool-1',
                'tool_name' => 'search',
                'arguments' => ['query' => 'hi'],
                'message_id' => 'msg-1',
            ],
        ]);
});

test('node stream accumulator rebuilds thinking deltas', function () {
    $state = new State;
    $accumulator = new NodeStreamAccumulator($state);

    $accumulator->apply(makeStreamEvent('thinking_start', ['id' => 'reason-1']));
    $accumulator->apply(makeStreamEvent('thinking_delta', ['id' => 'reason-1', 'delta' => 'Step 1']));
    $accumulator->apply(makeStreamEvent('thinking_delta', ['id' => 'reason-1', 'delta' => ' Step 2']));

    $result = $accumulator->finalize();

    expect($result->get('thinking'))->toBe('Step 1 Step 2');
});

test('node stream accumulator keeps the final step text as a string (one message id per step)', function () {
    $state = new State;
    $accumulator = new NodeStreamAccumulator($state);

    $accumulator->apply(makeStreamEvent('step_start', ['id' => 'step-1']));
    $accumulator->apply(makeStreamEvent('text_delta', ['message_id' => 'msg-1', 'delta' => 'Checking.']));
    $accumulator->apply(makeStreamEvent('step_finish', ['id' => 'step-1-end']));
    $accumulator->apply(makeStreamEvent('step_start', ['id' => 'step-2']));
    $accumulator->apply(makeStreamEvent('text_delta', ['message_id' => 'msg-2', 'delta' => 'Final.']));

    expect($accumulator->finalize()->get('text'))->toBe('Final.');
});

test('node stream accumulator handles empty streams', function () {
    $state = new State;
    $accumulator = new NodeStreamAccumulator($state);

    $result = $accumulator->finalize();

    expect($result->all())->toBe([]);
});

test('node stream accumulator preserves tool call order', function () {
    $state = new State;
    $accumulator = new NodeStreamAccumulator($state);

    $accumulator->apply(makeStreamEvent('tool_call', [
        'tool_id' => 'tool-1',
        'tool_name' => 'search',
        'message_id' => 'msg-1',
        'arguments' => ['query' => 'a'],
    ]));
    $accumulator->apply(makeStreamEvent('tool_call', [
        'tool_id' => 'tool-2',
        'tool_name' => 'lookup',
        'message_id' => 'msg-1',
        'arguments' => ['query' => 'b'],
    ]));

    $result = $accumulator->finalize();

    expect($result->get('tool_calls'))->toBe([
        [
            'tool_id' => 'tool-1',
            'tool_name' => 'search',
            'arguments' => ['query' => 'a'],
            'message_id' => 'msg-1',
        ],
        [
            'tool_id' => 'tool-2',
            'tool_name' => 'lookup',
            'arguments' => ['query' => 'b'],
            'message_id' => 'msg-1',
        ],
    ]);
});

function makeStreamEvent(string $type, array $payload): StreamEventImpl
{
    return new StreamEventImpl(
        streamId: 'stream-test',
        executionId: 'exec-test',
        nodeName: 'node-test',
        spanId: null,
        parentSpanId: null,
        type: $type,
        payload: $payload,
        seq: 1,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );
}
