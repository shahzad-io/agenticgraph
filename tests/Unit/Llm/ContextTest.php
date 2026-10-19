<?php

use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class ContextProbeNode extends PrismNode
{
    public function __construct(?bool $cacheEnabled = null)
    {
        $this->cacheEnabled = $cacheEnabled;
    }

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function call(Closure $build): mixed
    {
        return $build($this->llm());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function contextItems(): array
{
    return [
        ['id' => 'doc-1', 'content' => 'Refunds take {days} days.', 'metadata' => ['title' => 'Refunds'], 'score' => 0.91],
        ['id' => 7, 'content' => 'Ship in 2 days.', 'metadata' => ['title' => 'Shipping'], 'score' => 0.5],
    ];
}

/**
 * The prompt of the only Prism request, after running $build on a fresh node.
 */
function contextPrompt(Closure $build): string
{
    $fake = Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))]);
    (new ContextProbeNode)->call($build);
    $prompt = '';
    $fake->assertRequest(function (array $requests) use (&$prompt): void {
        $prompt = $requests[0]->prompt();
    });

    return $prompt;
}

beforeEach(function () {
    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);
    config()->set('agenticgraph.prism.cache_enabled', false);
});

it('joins the item contents into the variable and never interpolates them', function () {
    $prompt = contextPrompt(fn (LlmRequest $llm) => $llm->prompt("Q: {q}\n{context}")->vars(['q' => 'how long?'])->context(contextItems())->text());

    expect($prompt)->toBe("Q: how long?\nRefunds take {days} days.\n\nShip in 2 days.");
});

it('formats each item with a template over its metadata, id, content and score, joined by the separator', function () {
    $format = new PromptTemplate('[{id}] {title} ({score}): {content}');
    $prompt = contextPrompt(fn (LlmRequest $llm) => $llm->prompt('{docs}')->context(contextItems(), as: 'docs', format: $format, separator: "\n---\n")->text());

    expect($prompt)->toBe("[doc-1] Refunds (0.91): Refunds take {days} days.\n---\n[7] Shipping (0.5): Ship in 2 days.");
});

it('throws when the format names a key the item does not have, as the interpolator does', function () {
    (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('{context}')->context(contextItems(), format: new PromptTemplate('{url}'))->explain());
})->throws(TemplateException::class, 'Missing template variable: url');

it('takes any object with toContextItems(), with no shared interface', function () {
    $result = new class
    {
        public function toContextItems(): array
        {
            return contextItems();
        }
    };

    expect(contextPrompt(fn (LlmRequest $llm) => $llm->prompt('{context}')->context($result)->text()))->toBe("Refunds take {days} days.\n\nShip in 2 days.");
});

it('rejects an object without toContextItems() and an item without an id or content', function (mixed $items, string $message) {
    expect(fn () => (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('{context}')->context($items)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'object' => [fn () => new stdClass, 'toContextItems()'],
    'no id' => [[['content' => 'x']], 'item [0]'],
    'no content' => [[['id' => 1]], 'item [0]'],
]);

it('refuses a vars() key with the same name, so the context is never silently replaced', function () {
    (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('{context}')->vars(['context' => 'x'])->context(contextItems())->explain());
})->throws(LogicException::class, 'context() sets the variable [context]');

it('works with chat() templates: the context is a variable of a message', function () {
    $template = ChatPromptTemplate::create()->system("Use:\n{context}")->user('{q}');
    $messages = (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->chat($template)->vars(['q' => 'hi'])->context(contextItems())->explain()['messages']);

    expect($messages[0]['content'])->toBe("Use:\nRefunds take {days} days.\n\nShip in 2 days.")
        ->and($messages[1]['content'])->toBe('hi');
});

it('returns the items used as sources (id, metadata, score), also in toArray() and on a cache hit', function () {
    Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1)), TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))]);
    $node = new ContextProbeNode(cacheEnabled: true);
    $build = fn (LlmRequest $llm) => $llm->prompt('{context}')->context(contextItems())->run();
    $sources = [
        ['id' => 'doc-1', 'metadata' => ['title' => 'Refunds'], 'score' => 0.91],
        ['id' => 7, 'metadata' => ['title' => 'Shipping'], 'score' => 0.5],
    ];

    $first = $node->call($build);
    $hit = $node->call($build);

    expect($first->sources())->toBe($sources)
        ->and($first->toArray()['sources'])->toBe($sources)
        ->and($hit->cached)->toBeTrue()
        ->and($hit->sources())->toBe($sources)
        ->and((new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('x')->run())->sources())->toBe([]);
});

it('carries the item count and the first 20 ids as strings on TokensUsed, and nothing without context()', function () {
    Event::fake([TokensUsed::class]);
    Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1)), TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))]);
    $items = array_map(fn (int $i): array => ['id' => $i, 'content' => "c{$i}"], range(1, 25));

    (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('{context}')->context($items)->text());
    (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('plain')->text());

    $events = Event::dispatched(TokensUsed::class);
    expect($events[0][0]->contextCount)->toBe(25)
        ->and($events[0][0]->contextIds)->toBe(array_map('strval', range(1, 20)))
        ->and($events[1][0]->contextCount)->toBeNull()
        ->and($events[1][0]->contextIds)->toBeNull();
});

it('shows the formatted context and its item count in explain()', function () {
    $explain = (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('{docs}')->context(contextItems(), as: 'docs')->explain());

    expect($explain['context'])->toBe(['var' => 'docs', 'count' => 2, 'text' => "Refunds take {days} days.\n\nShip in 2 days."])
        ->and((new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('x')->explain())['context'])->toBeNull();
});

/*
 * FAQ app fit. A FAQ app stores each section as content "{title}\n\n{question}\n\n{answer}" with
 * title and question in metadata, and RetrieveNode builds the block below by hand (copied verbatim, with
 * Retriever::answer(), which strips the title and question prefix). A template has no string functions, so it cannot
 * strip that prefix from {content}: the fit needs the answer as the item's content (or as metadata).
 */
function faqAppHits(): array
{
    $hit = fn (string $id, string $title, string $question, string $answer, float $score): array => [
        'id' => $id,
        'content' => "{$title}\n\n{$question}\n\n{$answer}",
        'metadata' => ['brand' => 'acme', 'title' => $title, 'question' => $question, 'source_url' => "https://example.test/{$id}"],
        'score' => $score,
    ];

    return [
        $hit('acme:refunds:how-long', 'Refunds', 'How long do refunds take?', "Refunds take 5 to 7 days.\nUse {code} at checkout.", 0.82),
        $hit('acme:cert:valid', 'Certificates', 'How long is my card valid?', 'Two years from the class date.', 0.61),
        ['id' => 'acme:legacy:x', 'content' => 'A section stored without the prefix.', 'metadata' => ['title' => 'Legacy', 'question' => 'Old?'], 'score' => 0.4],
    ];
}

function faqAppAnswer(array $hit): string
{
    $prefix = ($hit['metadata']['title'] ?? '')."\n\n".($hit['metadata']['question'] ?? '')."\n\n";

    return str_starts_with($hit['content'], $prefix) ? substr($hit['content'], strlen($prefix)) : $hit['content'];
}

it('reproduces a FAQ app\'s context block from a format template once the items carry the answer as content', function () {
    $hits = faqAppHits();
    $current = implode("\n\n---\n\n", array_map(
        fn (array $hit): string => 'Title: '.($hit['metadata']['title'] ?? '')."\nQuestion: ".($hit['metadata']['question'] ?? '')."\nAnswer:\n".faqAppAnswer($hit),
        $hits,
    ));
    $format = new PromptTemplate("Title: {title}\nQuestion: {question}\nAnswer:\n{content}");
    $context = fn (array $items): string => (new ContextProbeNode)->call(fn (LlmRequest $llm) => $llm->prompt('{context}')->context($items, format: $format, separator: "\n\n---\n\n")->explain()['context']['text']);

    expect($context(array_map(fn (array $hit): array => ['content' => faqAppAnswer($hit)] + $hit, $hits)))->toBe($current)
        ->and($context($hits))->not->toBe($current);
});
