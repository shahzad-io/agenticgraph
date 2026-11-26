<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\RouteFellBack;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Exceptions\RoutingException;
use Shahzad\AgenticGraph\Exceptions\StructuredHydrationException;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

/**
 * A structured reply must have every key its schema requires, at every depth. Prism sends the schema and decodes the
 * JSON; it does not validate it. Presence only: not types, enum values, extra keys or anyOf branches.
 */
final readonly class RequiredKeysVerdict
{
    public function __construct(public string $verdict, public ?string $reason = null) {}
}

class RequiredKeysNode extends PrismNode
{
    protected ?string $model = 'openai:gpt-test';

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function call(Closure $build): mixed
    {
        return $build($this->llm());
    }
}

function requiredKeysSchema(): ObjectSchema
{
    return new ObjectSchema('out', 'out', [
        new StringSchema('verdict', 'verdict'),
        new StringSchema('reason', 'reason', nullable: true),
    ], ['verdict', 'reason']);
}

function requiredKeysNestedSchema(): ObjectSchema
{
    $item = new ObjectSchema('item', 'item', [new StringSchema('name', 'n'), new StringSchema('note', 'n')], ['name', 'note']);

    return new ObjectSchema('out', 'out', [
        new ObjectSchema('meta', 'meta', [new StringSchema('source', 's')], ['source']),
        new ArraySchema('items', 'items', $item),
    ], ['meta', 'items']);
}

function requiredKeysReply(string $text): array
{
    return [
        'id' => 'resp', 'model' => 'gpt-test', 'status' => 'completed',
        'output' => [['type' => 'message', 'id' => 'm', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
    ];
}

function requiredKeysAnswers(string ...$replies): void
{
    $sequence = Http::sequence();
    foreach ($replies as $reply) {
        $sequence->push(requiredKeysReply($reply));
    }
    Http::fake(['api.openai.com/*' => $sequence]);
}

/** @param  Closure(LlmRequest): mixed|null  $with */
function requiredKeysCall(?object $schema = null, ?Closure $with = null): mixed
{
    return (new RequiredKeysNode)->call(function (LlmRequest $llm) use ($schema, $with) {
        $llm = $llm->prompt('judge')->schema($schema ?? requiredKeysSchema());

        return ($with ? $with($llm) : $llm)->run();
    });
}

function requiredKeysCacheKey(?Closure $with = null): string
{
    return (new RequiredKeysNode)->call(function (LlmRequest $llm) use ($with) {
        $llm = $llm->prompt('judge')->schema(requiredKeysSchema());

        return ($with ? $with($llm) : $llm)->explain();
    })['cache']['key'];
}

const REQUIRED_KEYS_GOOD = '{"verdict":"correct","reason":"matches"}';

beforeEach(function () {
    config()->set('prism.providers.openai.api_key', 'k');
    config()->set('agenticgraph.prism.cache_enabled', false);
    config()->set('agenticgraph.prism.structured.retries', 0);
    config()->set('agenticgraph.router.retry_delay_ms', 0);
    app()->forgetInstance(StreamContext::class);
});

it('rejects a reply without a required key and names its path', function (string $reply, string $path) {
    requiredKeysAnswers($reply);

    expect(fn () => requiredKeysCall())->toThrow(StructuredHydrationException::class, "required key [{$path}] is missing");
    Http::assertSentCount(1);
})->with([
    'another key instead' => ['{"judgment":"correct"}', 'verdict'],
    'second key missing' => ['{"verdict":"correct"}', 'reason'],
    'empty list reply' => ['[]', 'verdict'],
    'empty object reply' => ['{}', 'verdict'],
]);

it('rejects a missing key in a nested object or a list item', function (string $reply, string $path) {
    requiredKeysAnswers($reply);

    expect(fn () => requiredKeysCall(requiredKeysNestedSchema()))->toThrow(StructuredHydrationException::class, "required key [{$path}] is missing");
})->with([
    'nested object' => ['{"meta":{},"items":[]}', 'meta.source'],
    'list item' => ['{"meta":{"source":"a"},"items":[{"name":"a","note":"b"},{"name":"c"}]}', 'items.1.note'],
]);

it('walks a RawSchema', function () {
    requiredKeysAnswers('{"a":{}}');
    $schema = new RawSchema('out', ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'required' => ['b']]], 'required' => ['a']]);

    expect(fn () => requiredKeysCall($schema))->toThrow(StructuredHydrationException::class, 'required key [a.b] is missing');
});

it('retries with the missing path fed back, and succeeds', function () {
    requiredKeysAnswers('{"judgment":"correct"}', REQUIRED_KEYS_GOOD);
    config()->set('agenticgraph.prism.structured.retries', 1);

    $response = requiredKeysCall();

    expect($response->structured)->toBe(['verdict' => 'correct', 'reason' => 'matches'])
        ->and($response->attempts)->toBe(2)
        ->and(json_encode(Http::recorded()->last()[0]->data()))->toContain('required key [verdict] is missing');
    Http::assertSentCount(2);
});

it('throws after the last retry and writes no cache entry', function () {
    requiredKeysAnswers('{}', '{}', '{}');
    config()->set('agenticgraph.prism.structured.retries', 2);
    config()->set('agenticgraph.prism.cache_enabled', true);

    expect(fn () => requiredKeysCall())->toThrow(StructuredHydrationException::class, 'required key [verdict] is missing');
    Http::assertSentCount(3);
    expect(app('cache')->store()->has(requiredKeysCacheKey()))->toBeFalse();
});

it('rejects a missing key that as() would fill with null', function () {
    requiredKeysAnswers('{"verdict":"correct"}');

    expect(fn () => requiredKeysCall(with: fn (LlmRequest $llm) => $llm->as(RequiredKeysVerdict::class)))
        ->toThrow(StructuredHydrationException::class, 'required key [reason] is missing');
});

it('accepts a required key that is present with null', function () {
    requiredKeysAnswers('{"verdict":"correct","reason":null}');

    $response = requiredKeysCall(with: fn (LlmRequest $llm) => $llm->as(RequiredKeysVerdict::class));

    expect($response->data->reason)->toBeNull();
});

it('checks presence only', function () {
    $schema = new ObjectSchema('out', 'out', [
        new EnumSchema('label', 'l', ['pos', 'neg']),
        new ObjectSchema('meta', 'm', [new StringSchema('source', 's')], ['source'], nullable: true),
        new AnyOfSchema([new ObjectSchema('x', 'x', [new StringSchema('id', 'i')], ['id'])], 'choice', 'c'),
        new StringSchema('optional', 'o'),
    ], ['label', 'meta', 'choice']);
    requiredKeysAnswers('{"label":42,"meta":null,"choice":{},"extra":true}');

    expect(requiredKeysCall($schema)->structured)->toBe(['label' => 42, 'meta' => null, 'choice' => [], 'extra' => true]);
});

it('checks a streamed first reply too and retries without the stream', function () {
    app()->instance(StreamContext::class, new StreamContext(new StreamSession('s', 'e', new StreamOptions, time()), new MemorySink));
    Prism\Prism\Facades\Prism::fake([
        TextResponseFake::make()->withText('{"judgment":"correct"}')->withUsage(new Usage(10, 3)),
        StructuredResponseFake::make()->withStructured(['verdict' => 'correct', 'reason' => 'r'])->withUsage(new Usage(20, 5)),
    ]);
    config()->set('agenticgraph.prism.structured.retries', 1);

    $response = requiredKeysCall();

    expect($response->structured)->toBe(['verdict' => 'correct', 'reason' => 'r'])
        ->and($response->attempts)->toBe(2);
});

describe('cache hits', function () {
    beforeEach(function () {
        config()->set('agenticgraph.prism.cache_enabled', true);
    });

    it('counts a cached reply without a required key as a miss and replaces it', function (mixed $cached) {
        requiredKeysAnswers(REQUIRED_KEYS_GOOD);
        app('cache')->store()->put(requiredKeysCacheKey(), $cached, 600);
        Event::fake([TokensUsed::class]);

        $response = requiredKeysCall();

        expect($response->cached)->toBeFalse()
            ->and($response->structured)->toBe(['verdict' => 'correct', 'reason' => 'matches'])
            ->and(app('cache')->store()->get(requiredKeysCacheKey()))->toBe(['verdict' => 'correct', 'reason' => 'matches']);
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(TokensUsed::class, 1);

        expect(requiredKeysCall()->cached)->toBeTrue();
        Http::assertSentCount(1);
    })->with([
        'missing key' => [['verdict' => 'old']],
        'empty reply' => [[]],
    ]);

    it('counts it as a miss with as() too, where the hydrator would fill null', function () {
        requiredKeysAnswers(REQUIRED_KEYS_GOOD);
        $as = fn (LlmRequest $llm) => $llm->as(RequiredKeysVerdict::class);
        app('cache')->store()->put(requiredKeysCacheKey($as), ['verdict' => 'old'], 600);

        $response = requiredKeysCall(with: $as);

        expect($response->cached)->toBeFalse()
            ->and($response->data->reason)->toBe('matches');
    });

    // Control: a good entry is still a hit.
    it('still serves a cached reply that has every required key', function () {
        Http::fake();
        app('cache')->store()->put(requiredKeysCacheKey(), ['verdict' => 'cached', 'reason' => null], 600);

        $response = requiredKeysCall();

        expect($response->cached)->toBeTrue()
            ->and($response->structured)->toBe(['verdict' => 'cached', 'reason' => null]);
        Http::assertNothingSent();
    });
});

// PrismRouter checks the route key itself: its fallback keeps its reason and makes one call.
describe('PrismRouter', function () {
    it('keeps the missing_route_key fallback after one call', function (string $reply) {
        requiredKeysAnswers($reply, $reply, $reply);
        config()->set('agenticgraph.prism.model', 'openai:gpt-test');
        Event::fake([RouteFellBack::class]);

        $route = (new PrismRouter(['a' => 'A', 'b' => 'B'], 'b'))->route(new State);

        expect($route)->toBe('b');
        Http::assertSentCount(1);
        Event::assertDispatched(RouteFellBack::class, fn (RouteFellBack $event) => $event->reason === 'missing_route_key');
    })->with(['wrong key' => ['{"path":"a"}'], 'empty reply' => ['[]']]);

    it('keeps missing_route_key in the exception without a default route', function () {
        requiredKeysAnswers('{"path":"a"}', '{"path":"a"}', '{"path":"a"}');
        config()->set('agenticgraph.prism.model', 'openai:gpt-test');

        expect(fn () => (new PrismRouter(['a' => 'A']))->route(new State))
            ->toThrow(RoutingException::class, 'missing_route_key');
        Http::assertSentCount(1);
    });
});
