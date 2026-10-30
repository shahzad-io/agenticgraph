<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Exceptions\StructuredHydrationException;
use Shahzad\AgenticGraph\Llm\DtoHydrator;
use Shahzad\AgenticGraph\Llm\ListOf;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

enum RetryLabel: string
{
    case Pos = 'pos';
    case Neg = 'neg';
}

final readonly class RetryItem
{
    public function __construct(public string $name, public float $price) {}
}

final readonly class RetryDto
{
    /**
     * @param  list<RetryItem>  $items
     * @param  list<string>  $tags
     */
    public function __construct(
        public RetryLabel $label,
        public array $items,
        #[ListOf('string')] public array $tags = [],
        public ?int $score = null,
        public ?DateTimeImmutable $at = null,
    ) {}
}

class RetryNode extends PrismNode
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

function retrySchema(): ObjectSchema
{
    return new ObjectSchema('out', 'out', [new EnumSchema('label', 'label', ['pos', 'neg'])], ['label']);
}

function retryReply(string $text, int $in = 10, int $out = 4): array
{
    return [
        'id' => 'resp', 'model' => 'gpt-test', 'status' => 'completed',
        'output' => [['type' => 'message', 'id' => 'm', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
    ];
}

function retryBindSink(): void
{
    app()->instance(StreamContext::class, new StreamContext(new StreamSession('s', 'e', new StreamOptions, time()), new MemorySink));
}

beforeEach(function () {
    config()->set('prism.providers.openai.api_key', 'k');
    config()->set('agenticgraph.prism.cache_enabled', false);
    app()->forgetInstance(StreamContext::class);
});

describe('structuredMode', function () {
    it('sends json_object on OpenAI and keeps the cache key stable when unset', function () {
        Http::fake(['api.openai.com/*' => Http::response(retryReply('{"label":"pos"}'))]);
        $node = new RetryNode;

        $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->structuredMode('json')->structured());

        Http::assertSent(fn ($r) => ($r->data()['text']['format']['type'] ?? null) === 'json_object');
        $plain = $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->explain());
        $mode = $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->structuredMode('json')->explain());
        expect($mode['cache']['key'])->not->toBe($plain['cache']['key'])
            ->and($mode['settings']['structured_mode'])->toBe(['value' => 'json', 'source' => 'call'])
            ->and($plain['settings']['structured_mode'])->toBe(['value' => null, 'source' => 'default']);
    });

    it('throws before any call on a provider that does not read it, and without a schema', function () {
        Http::fake();
        $node = new RetryNode;

        expect(fn () => $node->call(fn (LlmRequest $llm) => $llm->model('anthropic:x')->prompt('a')->schema(retrySchema())->structuredMode('json')->structured()))
            ->toThrow(LogicException::class, '[anthropic]')
            ->and(fn () => $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->structuredMode('json')->text()))
            ->toThrow(LogicException::class, 'need schema()')
            ->and(fn () => $node->call(fn (LlmRequest $llm) => $llm->structuredMode('xml')))
            ->toThrow(InvalidArgumentException::class);
        Http::assertNothingSent();
    });
});

describe('structured retry', function () {
    it('retries a reply that is not JSON with the failed reply and error fed back, and sums usage as unknown', function () {
        Http::fake(['api.openai.com/*' => Http::sequence()->push(retryReply('nope'))->push(retryReply('{"label":"pos"}', 30, 6))]);
        config()->set('agenticgraph.prism.structured.retries', 1);
        Event::fake([TokensUsed::class]);

        $response = (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->run());

        expect($response->structured)->toBe(['label' => 'pos'])
            ->and($response->attempts)->toBe(2)
            ->and($response->usage)->toBeNull()
            ->and($response->warnings[0])->toContain('unknown');
        $bodies = Http::recorded()->map(fn ($pair) => json_encode($pair[0]->data()))->all();
        expect($bodies)->toHaveCount(2)
            ->and($bodies[1])->toContain('nope')->and($bodies[1])->toContain('previous reply was rejected');
        Event::assertDispatchedTimes(TokensUsed::class, 1);
    });

    it('retries a hydration failure and covers both attempts in usage', function () {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(retryReply('{"label":"pos","items":[{"name":"a","price":"x"}]}', 10, 4))
            ->push(retryReply('{"label":"pos","items":[{"name":"a","price":1.5}]}', 30, 6))]);
        config()->set('agenticgraph.prism.structured.retries', 1);
        Event::fake([TokensUsed::class]);
        $schema = new ObjectSchema('out', 'out', [new StringSchema('label', 'l')], ['label']);

        $response = (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema($schema)->as(RetryDto::class)->run());

        expect($response->data)->toBeInstanceOf(RetryDto::class)
            ->and($response->data->items[0]->price)->toBe(1.5)
            ->and($response->attempts)->toBe(2)
            ->and([$response->usage->promptTokens, $response->usage->completionTokens])->toBe([40, 10]);
        expect(json_encode(Http::recorded()->last()[0]->data()))->toContain('items.0.price');
        Event::assertDispatchedTimes(TokensUsed::class, 2);
    });

    it('fails at once with retries 0 and after the last retry otherwise, never writing the cache', function () {
        Http::fake(['api.openai.com/*' => Http::response(retryReply('nope'))]);
        config()->set('agenticgraph.prism.cache_enabled', true);
        config()->set('agenticgraph.prism.structured.retries', 0);

        expect(fn () => (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->run()))
            ->toThrow(PrismStructuredDecodingException::class);
        Http::assertSentCount(1);

        config()->set('agenticgraph.prism.structured.retries', 2);
        expect(fn () => (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->run()))
            ->toThrow(PrismStructuredDecodingException::class);
        Http::assertSentCount(4);
        $key = (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->explain())['cache']['key'];
        expect(app('cache')->store()->has($key))->toBeFalse();
    });

    it('streamed structured call whose first reply fails to decode: usage covers both attempts', function () {
        retryBindSink();
        Prism::fake([
            TextResponseFake::make()->withText('not json')->withUsage(new Usage(10, 3)),
            StructuredResponseFake::make()->withStructured(['label' => 'neg'])->withUsage(new Usage(20, 5)),
        ]);
        config()->set('agenticgraph.prism.structured.retries', 1);
        Event::fake([TokensUsed::class]);

        $response = (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->run());

        expect($response->structured)->toBe(['label' => 'neg'])
            ->and([$response->usage->promptTokens, $response->usage->completionTokens])->toBe([30, 8]);
        Event::assertDispatchedTimes(TokensUsed::class, 2);
    });

    it('takes structuredRetries() over the config for this call and shows it in explain()', function () {
        Http::fake(['api.openai.com/*' => Http::response(retryReply('nope'))]);
        config()->set('agenticgraph.prism.structured.retries', 1);
        $node = new RetryNode;

        expect(fn () => $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->structuredRetries(0)->run()))
            ->toThrow(PrismStructuredDecodingException::class);
        Http::assertSentCount(1);

        expect(fn () => $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->structuredRetries(2)->run()))
            ->toThrow(PrismStructuredDecodingException::class);
        Http::assertSentCount(4);

        expect($node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->structuredRetries(3)->explain())['settings']['structured_retries'])
            ->toBe(['value' => 3, 'source' => 'call'])
            ->and($node->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->explain())['settings']['structured_retries'])
            ->toBe(['value' => 1, 'source' => 'config']);
    });

    it('refuses negative structuredRetries() and structuredRetries() without a schema', function () {
        Http::fake();
        $node = new RetryNode;

        expect(fn () => $node->call(fn (LlmRequest $llm) => $llm->structuredRetries(-1)))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $node->call(fn (LlmRequest $llm) => $llm->prompt('a')->structuredRetries(2)->text()))
            ->toThrow(LogicException::class, 'need schema()');
        Http::assertNothingSent();
    });

    it('with retries 0 a streamed reply that fails to decode throws instead of silently retrying', function () {
        retryBindSink();
        Prism::fake([
            TextResponseFake::make()->withText('not json')->withUsage(new Usage(10, 3)),
            StructuredResponseFake::make()->withStructured(['label' => 'neg'])->withUsage(new Usage(20, 5)),
        ]);
        config()->set('agenticgraph.prism.structured.retries', 0);

        expect(fn () => (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->schema(retrySchema())->run()))
            ->toThrow(PrismStructuredDecodingException::class);
    });
});

describe('as()', function () {
    it('hydrates nested DTOs, enums, lists, defaults and date-times', function () {
        $dto = DtoHydrator::hydrate(RetryDto::class, [
            'label' => 'neg', 'items' => [['name' => 'a', 'price' => 2], ['name' => 'b', 'price' => 1.5, 'extra' => true]],
            'tags' => ['x'], 'at' => '2026-01-02T03:04:05Z',
        ]);

        expect($dto->label)->toBe(RetryLabel::Neg)
            ->and($dto->items[0]->price)->toBe(2.0)
            ->and($dto->tags)->toBe(['x'])
            ->and($dto->score)->toBeNull()
            ->and($dto->at->format('Y'))->toBe('2026');
    });

    it('names the path, expected type and received value', function (array $data, string $message) {
        expect(fn () => DtoHydrator::hydrate(RetryDto::class, $data))->toThrow(StructuredHydrationException::class, $message);
    })->with([
        'enum' => [['label' => 'maybe', 'items' => []], 'at [label]: expected one of [pos|neg], got string "maybe"'],
        'nested' => [['label' => 'pos', 'items' => [['name' => 'a', 'price' => 1], ['name' => 'b', 'price' => '3']]], 'at [items.1.price]: expected float, got string "3"'],
        'missing' => [['label' => 'pos'], 'at [items]: the required value is missing'],
        'list item' => [['label' => 'pos', 'items' => [], 'tags' => ['a', 4]], 'at [tags.1]: expected string, got int 4'],
        'not a list' => [['label' => 'pos', 'items' => ['a' => 1]], 'at [items]: expected list<'],
        'strict int' => [['label' => 'pos', 'items' => [], 'score' => '5'], 'at [score]: expected int, got string "5"'],
        'date' => [['label' => 'pos', 'items' => [], 'at' => 'soon'], 'at [at]: expected a date-time string'],
    ]);

    it('needs schema() and a class', function () {
        expect(fn () => (new RetryNode)->call(fn (LlmRequest $llm) => $llm->prompt('a')->as(RetryDto::class)->run()))->toThrow(LogicException::class)
            ->and(fn () => (new RetryNode)->call(fn (LlmRequest $llm) => $llm->as('Nope\\Missing')))->toThrow(InvalidArgumentException::class);
    });
});
