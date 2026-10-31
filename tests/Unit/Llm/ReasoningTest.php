<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\ReasoningNotDisabled;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Exceptions\ReasoningException;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Nodes\PrismNode;

class ReasoningProbeNode extends PrismNode
{
    public function __construct(?string $model, ?string $reasoning = null, ?int $budget = null, array $providerOptions = [])
    {
        $this->model = $model;
        $this->reasoning = $reasoning;
        $this->reasoningBudget = $budget;
        $this->providerOptions = $providerOptions;
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

/** The provider options Prism received for one request on $node. */
function reasoningSent(ReasoningProbeNode $node, ?Closure $build = null): array
{
    $fake = Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))]);
    $node->call($build ?? fn (LlmRequest $llm) => $llm->prompt('hi')->text());
    $sent = null;
    $fake->assertRequest(function (array $recorded) use (&$sent): void {
        $sent = $recorded[0]->providerOptions();
    });

    return $sent;
}

beforeEach(function () {
    config()->set('agenticgraph.prism.cache_enabled', false);
    config()->set('agenticgraph.prism.reasoning.budgets', ['low' => 1024, 'medium' => 4096, 'high' => 16384]);
    config()->set('agenticgraph.prism.reasoning.off', ['openai' => 'minimal']);
});

describe('the option Prism receives per provider', function () {
    it('maps each level exactly', function (string $model, string $level, array $expected) {
        expect(reasoningSent(new ReasoningProbeNode($model, $level)))->toBe($expected);
    })->with([
        'openai off' => ['openai:o3', 'off', ['reasoning' => ['effort' => 'minimal']]],
        'openai low' => ['openai:o3', 'low', ['reasoning' => ['effort' => 'low']]],
        'openai high' => ['openai:o3', 'high', ['reasoning' => ['effort' => 'high']]],
        'gemini off' => ['gemini:gemini-2.5-flash', 'off', ['thinkingBudget' => 0]],
        'gemini medium' => ['gemini:gemini-2.5-flash', 'medium', ['thinkingLevel' => 'medium']],
        'anthropic off' => ['anthropic:claude-x', 'off', ['thinking' => ['type' => 'disabled']]],
        'anthropic medium' => ['anthropic:claude-x', 'medium', ['thinking' => ['enabled' => true, 'budgetTokens' => 4096]]],
        'openrouter off' => ['openrouter:vendor/model', 'off', ['reasoning' => ['enabled' => false]]],
        'openrouter high' => ['openrouter:vendor/model', 'high', ['reasoning' => ['effort' => 'high']]],
        'ollama off' => ['ollama:qwen3', 'off', ['thinking' => false]],
        'ollama high' => ['ollama:qwen3', 'high', ['thinking' => true]],
    ]);

    it('maps a token budget exactly', function (string $model, array $expected) {
        expect(reasoningSent(new ReasoningProbeNode($model, budget: 2000)))->toBe($expected);
    })->with([
        'gemini' => ['gemini:gemini-2.5-flash', ['thinkingBudget' => 2000]],
        'anthropic' => ['anthropic:claude-x', ['thinking' => ['enabled' => true, 'budgetTokens' => 2000]]],
        'openrouter' => ['openrouter:vendor/model', ['reasoning' => ['max_tokens' => 2000]]],
    ]);

    it('sends nothing when no level is set', function () {
        expect(reasoningSent(new ReasoningProbeNode('openai:o3')))->toBe([]);
    });
});

describe('errors before the call', function () {
    it('throws for the DeepSeek provider and any unmapped provider, on every setting', function (string $model, ?string $level, ?int $budget) {
        Prism::fake([]);
        expect(fn () => (new ReasoningProbeNode($model, $level, $budget))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->text()))
            ->toThrow(ReasoningException::class);
    })->with([
        'deepseek off' => ['deepseek:deepseek-chat', 'off', null],
        'deepseek high' => ['deepseek:deepseek-chat', 'high', null],
        'deepseek budget' => ['deepseek:deepseek-chat', null, 100],
        'unmapped provider' => ['mistral:large', 'low', null],
    ]);

    it('throws a budget where the provider has none', function (string $model) {
        Prism::fake([]);
        expect(fn () => (new ReasoningProbeNode($model, budget: 500))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->text()))
            ->toThrow(ReasoningException::class, 'token budget');
    })->with(['openai:o3', 'ollama:qwen3']);

    it('throws naming the config keys when a level has no budget on Anthropic', function () {
        config()->set('agenticgraph.prism.reasoning.budgets', ['low' => null]);
        Prism::fake([]);
        expect(fn () => (new ReasoningProbeNode('anthropic:claude-x', 'low'))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->text()))
            ->toThrow(ReasoningException::class, 'agenticgraph.prism.reasoning.budgets.low');
    });

    it('sends the app value for off on openai and throws naming the key without it', function () {
        config()->set('agenticgraph.prism.reasoning.off', ['openai' => 'none']);
        expect(reasoningSent(new ReasoningProbeNode('openai:gpt-6-luna', 'off')))->toBe(['reasoning' => ['effort' => 'none']]);

        config()->set('agenticgraph.prism.reasoning.off', []);
        Prism::fake([]);
        expect(fn () => (new ReasoningProbeNode('openai:gpt-6-luna', 'off'))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->text()))
            ->toThrow(ReasoningException::class, 'agenticgraph.prism.reasoning.off.openai');
    });

    it('throws for Anthropic thinking with a forced tool choice but not with auto', function () {
        Prism::fake([]);
        $node = new ReasoningProbeNode('anthropic:claude-x', 'high');
        expect(fn () => $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->tools([llmReasoningTool()])->toolChoice('lookup')->run()))
            ->toThrow(ReasoningException::class, 'forced tool choice')
            ->and($node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->tools([llmReasoningTool()])->toolChoice(ToolChoice::Auto)->explain())['settings']['reasoning']['value']['options'])
            ->toBe(['thinking' => ['enabled' => true, 'budgetTokens' => 16384]]);
    });

    it('rejects an unknown level, and a level together with a budget', function () {
        $node = new ReasoningProbeNode('openai:o3');
        expect(fn () => $node->call(fn (LlmRequest $llm) => $llm->reasoning('extreme')))->toThrow(InvalidArgumentException::class, 'extreme')
            ->and(fn () => $node->call(fn (LlmRequest $llm) => $llm->reasoning('low', 5)))->toThrow(InvalidArgumentException::class, 'exclusive')
            ->and(fn () => $node->call(fn (LlmRequest $llm) => $llm->reasoning(budget: 0)))->toThrow(InvalidArgumentException::class);
    });
});

describe('precedence, merge, explain and cache key', function () {
    it('takes the call over the node, and a call budget over a node level', function () {
        $node = new ReasoningProbeNode('gemini:gemini-2.5-flash', 'low');
        $explained = $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->reasoning('high')->explain());
        $budget = $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->reasoning(budget: 300)->explain());
        $fromNode = $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->explain());

        expect($explained['settings']['reasoning']['source'])->toBe('call')
            ->and($explained['settings']['provider_options']['value'])->toBe(['thinkingLevel' => 'high'])
            ->and($budget['settings']['provider_options']['value'])->toBe(['thinkingBudget' => 300])
            ->and($fromNode['settings']['reasoning']['source'])->toBe('node')
            ->and($fromNode['settings']['provider_options']['value'])->toBe(['thinkingLevel' => 'low']);
    });

    it('has no config layer: the source is default and nothing is sent', function () {
        config()->set('agenticgraph.prism.reasoning.level', 'high');
        $explained = (new ReasoningProbeNode('openai:o3'))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->explain());

        expect($explained['settings']['reasoning'])->toBe(['value' => ['level' => null, 'budget' => null, 'options' => [], 'note' => null], 'source' => 'default'])
            ->and($explained['settings']['provider_options']['value'])->toBe([]);
    });

    it('merges recursively so other keys under the same option survive, and the per-call option wins per key', function () {
        $node = new ReasoningProbeNode('openai:o3', 'low', providerOptions: ['reasoning' => ['summary' => 'auto'], 'store' => false]);

        expect(reasoningSent($node))->toBe(['reasoning' => ['summary' => 'auto', 'effort' => 'low'], 'store' => false])
            ->and(reasoningSent($node, fn (LlmRequest $llm) => $llm->prompt('hi')->providerOptions(['store' => true])->text()))
            ->toBe(['reasoning' => ['summary' => 'auto', 'effort' => 'low'], 'store' => true]);
    });

    it('shows the level, its source, the note and the exact options in explain()', function () {
        $explained = (new ReasoningProbeNode('ollama:qwen3', 'medium'))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->explain());

        expect($explained['settings']['reasoning']['value']['level'])->toBe('medium')
            ->and($explained['settings']['reasoning']['value']['note'])->toContain('reduced to on')
            ->and($explained['settings']['provider_options']['value'])->toBe(['thinking' => true]);
    });

    it('puts the level in the cache key', function () {
        $key = fn (?string $level) => (new ReasoningProbeNode('openai:o3', $level))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->withCache()->explain())['cache']['key'];

        expect($key('low'))->not->toBe($key('high'))->and($key('low'))->not->toBe($key(null));
    });
});

describe('the request body Prism 0.102 sends per provider', function () {
    // Real handlers, faked HTTP: what the mapped option becomes on the wire (text, streamed and structured paths read the same keys).
    it('puts each mapped option where the vendor reads it', function (string $model, string $host, ?string $level, ?int $budget, Closure $body) {
        config()->set('prism.providers.anthropic.api_key', 'k');
        config()->set('prism.providers.gemini.api_key', 'k');
        config()->set('prism.providers.openrouter.api_key', 'k');
        config()->set('prism.providers.openai.api_key', 'k');
        Http::fake(["{$host}/*" => Http::response(['error' => ['message' => 'stop here']], 400)]);

        try {
            (new ReasoningProbeNode($model, $level, $budget))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->run());
        } catch (Throwable) {
            // The body is what is asserted.
        }

        Http::assertSent(fn ($request) => $body($request->data()));
    })->with([
        'openai low' => ['openai:o3', 'api.openai.com', 'low', null, fn (array $d) => $d['reasoning'] === ['effort' => 'low']],
        'gemini budget' => ['gemini:gemini-2.5-flash', 'generativelanguage.googleapis.com', null, 128, fn (array $d) => $d['generationConfig']['thinkingConfig'] === ['thinkingBudget' => 128]],
        'gemini off' => ['gemini:gemini-2.5-flash', 'generativelanguage.googleapis.com', 'off', null, fn (array $d) => $d['generationConfig']['thinkingConfig'] === ['thinkingBudget' => 0]],
        'gemini level' => ['gemini:gemini-3-pro', 'generativelanguage.googleapis.com', 'high', null, fn (array $d) => $d['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'high']],
        'anthropic budget' => ['anthropic:claude-sonnet-4-5', 'api.anthropic.com', null, 2048, fn (array $d) => $d['thinking'] === ['type' => 'enabled', 'budget_tokens' => 2048]],
        'anthropic off' => ['anthropic:claude-sonnet-5-5', 'api.anthropic.com', 'off', null, fn (array $d) => ($d['thinking'] ?? null) === ['type' => 'disabled']],
        'openrouter budget' => ['openrouter:vendor/model', 'openrouter.ai', null, 512, fn (array $d) => $d['reasoning'] === ['max_tokens' => 512]],
        'openrouter off' => ['openrouter:vendor/model', 'openrouter.ai', 'off', null, fn (array $d) => $d['reasoning'] === ['enabled' => false]],
        'ollama off' => ['ollama:qwen3', 'localhost:11434', 'off', null, fn (array $d) => $d['think'] === false],
        'ollama high' => ['ollama:qwen3', 'localhost:11434', 'high', null, fn (array $d) => $d['think'] === true],
    ]);
});

describe('after the call', function () {
    it('rethrows a vendor 400 with the level and the option sent, and leaves other failures alone', function (int $status, bool $rethrown) {
        config()->set('prism.providers.openai.api_key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Unsupported parameter: reasoning.effort']], $status)]);
        $node = new ReasoningProbeNode('openai:gpt-4o', 'high');

        try {
            $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->run());
            $thrown = null;
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown instanceof ReasoningException)->toBe($rethrown);
        if ($rethrown) {
            expect($thrown->getMessage())->toContain('level [high]')->toContain('"reasoning":{"effort":"high"}')->toContain('Unsupported parameter');
        }
        Http::assertSent(fn ($request) => ($request->data()['reasoning'] ?? null) === ['effort' => 'high']);
    })->with([[400, true], [429, false], [500, false]]);

    it('names the temperature too when a vendor 400 rejects a call with reasoning and temperature', function () {
        config()->set('prism.providers.openai.api_key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Unsupported parameter: temperature']], 400)]);
        $node = new ReasoningProbeNode('openai:gpt-4o', 'high');

        expect(fn () => $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->temperature(0.7)->run()))
            ->toThrow(ReasoningException::class, 'reasoning level [high] and temperature 0.7');
    });

    it('flags thought tokens under off: a warning, an event and a note on the response', function () {
        Event::fake([ReasoningNotDisabled::class, TokensUsed::class]);
        Log::spy();
        Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 10, thoughtTokens: 7))]);

        $response = (new ReasoningProbeNode('openai:o3', 'off'))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->run());

        expect($response->warnings)->toHaveCount(1)->and($response->toArray()['warnings'])->toBe($response->warnings);
        Event::assertDispatched(ReasoningNotDisabled::class, fn (ReasoningNotDisabled $e) => $e->thoughtTokens === 7 && $e->model === 'openai:o3');
        Log::shouldHaveReceived('warning')->once();
    });

    it('does not flag off with no thought tokens, nor a level other than off', function () {
        Event::fake([ReasoningNotDisabled::class]);
        Prism::fake([
            TextResponseFake::make()->withText('a')->withUsage(new Usage(5, 10)),
            TextResponseFake::make()->withText('b')->withUsage(new Usage(5, 10, thoughtTokens: 9)),
        ]);

        $off = (new ReasoningProbeNode('openai:o3', 'off'))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->run());
        $high = (new ReasoningProbeNode('openai:o3', 'high'))->call(fn (LlmRequest $llm) => $llm->prompt('hi')->run());

        expect($off->warnings)->toBe([])->and($high->warnings)->toBe([]);
        Event::assertNotDispatched(ReasoningNotDisabled::class);
    });
});

describe('thought tokens', function () {
    it('carries thoughtTokens on TokensUsed and LlmResponse::toArray(), null when the provider does not report them', function () {
        Event::fake([TokensUsed::class]);
        Prism::fake([
            TextResponseFake::make()->withText('a')->withUsage(new Usage(5, 10, thoughtTokens: 6)),
            TextResponseFake::make()->withText('b')->withUsage(new Usage(5, 10)),
        ]);
        $node = new ReasoningProbeNode('openai:o3');
        $with = $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->run());
        $without = $node->call(fn (LlmRequest $llm) => $llm->prompt('hi')->run());

        expect($with->toArray()['usage']['thought_tokens'])->toBe(6)->and($without->toArray()['usage']['thought_tokens'])->toBeNull();
        Event::assertDispatched(TokensUsed::class, fn (TokensUsed $e) => $e->thoughtTokens === 6);
        Event::assertDispatched(TokensUsed::class, fn (TokensUsed $e) => $e->thoughtTokens === null);
    });
});

function llmReasoningTool(): Tool
{
    return (new Tool)->as('lookup')->for('Look up a value')->withStringParameter('q', 'query')->using(fn (string $q): string => "found {$q}");
}
