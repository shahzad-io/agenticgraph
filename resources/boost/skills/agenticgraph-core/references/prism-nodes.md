# PrismNode examples

Docs: `/docs/nodes`, `/docs/api/nodes`, `/docs/mcp`

Every model call starts with `$this->llm()`. Each builder method returns a new request; the node is never changed.

## A prompt

```php
namespace App\Nodes;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class DraftNode extends PrismNode
{
    // null: the model comes from config (AGENTICGRAPH_PRISM_MODEL). Set provider:model here only for this node.
    protected ?string $model = null;

    public function execute(StateContract $state): StateContract
    {
        $draft = $this->llm()
            ->prompt('Write a short post about {topic}.')
            ->vars(['topic' => $state->get('topic')])
            ->text();

        return $state->set('draft', $draft);
    }
}
```

A prompt string is a template: `{word}` is a placeholder and `{{ }}` are literal braces. Put dynamic text (model output, JSON, user input) in `vars()`, never into the template string. `system()`, `user()`, `assistant()` and `history()` are literal.

## Structured output

The root schema should be an object. `structured()` returns an array.

```php
namespace App\Nodes;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class LeadProfileNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $signup = json_encode($state->get('input.signup', []), JSON_UNESCAPED_SLASHES) ?: '{}';

        $schema = new ObjectSchema(
            name: 'lead_profile',
            description: 'Normalized lead profile',
            properties: [
                new StringSchema('name', 'Full name', nullable: true),
                new StringSchema('email', 'Email', nullable: true),
                new StringSchema('company', 'Company', nullable: true),
                new ArraySchema(
                    name: 'pain_points',
                    description: 'Key needs',
                    items: new StringSchema('pain', 'A pain point'),
                    nullable: true,
                ),
            ],
            requiredFields: ['name', 'email', 'company', 'pain_points'],
        );

        $lead = $this->llm()
            ->prompt('Normalize this signup payload: {signup}')
            ->vars(['signup' => $signup])
            ->schema($schema)
            ->structured();

        return $state->set('lead', $lead);
    }
}
```

### Retry and `as(Dto::class)`

Prism checks only that a structured reply is JSON. A reply that does not decode, or does not fit `->as(Ticket::class)`, is sent again with the failed reply and the error appended; `agenticgraph.prism.structured.retries` (`AGENTICGRAPH_PRISM_STRUCTURED_RETRIES`) extra calls, default `1`, `0` throws at once. It replaces the old silent streamed-to-non-streamed fallback. `as()` hydrates by reflection (constructor or public properties; scalars, nullable and union types, backed enums, date-times, nested classes, lists via `#[ListOf(Item::class)]` or `@param list<Item> $items`), strict, extra keys ignored; errors name the path (`lines.1.price`). It needs `schema()` and does not derive it. Read it with `->data()` or `run()->data`.

```php
$ticket = $this->llm()->prompt('{mail}')->vars(['mail' => $mail])->schema($schema)->as(Ticket::class)->data();
```

Constrain values in the schema first (enums, required fields); the retry is a safety net. Measured on one model and prompt, 20 calls each: enum left as a plain string, 3 of 20 needed the retry; enum in the schema, 0 of 20.

`run()->attempts` counts calls and `run()->usage` sums them; when Prism discarded a failed attempt's usage, `usage` is `null` (unknown, never zero) with a warning.

### Gateway and custom OpenAI model names

Since Prism 0.102, an OpenAI-provider model name Prism does not recognise (a custom name on an OpenAI-compatible gateway such as LiteLLM) gets strict `json_schema` structured output; only `gpt-3.5-turbo*`, `gpt-4`, `gpt-4-*` and `gpt-4o-2024-05-13` stay on `json_object`. A gateway or local server that rejects `json_schema` answers with a 400. Prism has a per-request switch for this, `StructuredMode::Json` (`->usingStructuredMode(StructuredMode::Json)` on the structured request, which sends `json_object` and asks for JSON in the prompt). `->structuredMode('json')` on the request sets it (`auto`, `json`, `structured`):

```php
$out = $this->llm()->prompt('...')->schema($schema)->structuredMode('json')->structured();
```

Only the OpenAI provider reads a mode; any other provider throws before the call. A streamed attempt ignores it.
`GatewayStructuredModeTest` pins both wire shapes. Prefer a gateway model that accepts `json_schema`.

## A tool loop

`tools()` defaults to one step and Prism's tool choice. Raise `maxSteps()` so the model can call a tool and then answer. Tools passed to `llm()` are traced; tools added to raw `prism()` are not. Tool requests never cache.

```php
namespace App\Nodes;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class CurrencyNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $convert = Tool::as('convert_currency')
            ->for('Convert an amount between currencies')
            ->withParameter(new ObjectSchema(
                name: 'input',
                description: 'Conversion input',
                properties: [
                    new StringSchema('from', 'Currency code to convert from, e.g. USD'),
                    new StringSchema('to', 'Currency code to convert to, e.g. EUR'),
                    new NumberSchema('amount', 'Amount to convert'),
                ],
                requiredFields: ['from', 'to', 'amount'],
            ))
            ->using(fn (array $input): string => (string) json_encode(
                Http::get('https://api.example.com/convert', $input)->json()
            ));

        $response = $this->llm()
            ->system('You are a currency conversion agent.')
            ->prompt('{message}')
            ->vars(['message' => (string) $state->get('message')])
            ->tools([$convert])
            ->maxSteps(5)
            ->run();

        return $state->set('response', $response->text);
    }
}
```

`run()` returns an `LlmResponse`: `text`, `structured`, `toolCalls` and `toolResults` of every step, `usage`, `finishReason` and `cached`. It is `JsonSerializable`, so it can be stored in state. With one step it holds the requested calls and what each handler returned.

**Usage semantics (Prism 0.102).** One meaning on every provider, in `LlmResponse::$usage`, `toArray()['usage']`, `TokensUsed` and the llm span: `prompt_tokens` is the **uncached** input; `cache_read_tokens` and `cache_write_tokens` are cached input, never inside `prompt_tokens` (so the full input is the sum of the three); `completion_tokens` includes reasoning; `thought_tokens` is the reasoning part of it. The two cache fields and `thought_tokens` are `null` when the provider does not report them and `0` when it reports zero. Cost uses the model's `cache_read` / `cache_write` rate from `config/model_pricing.php` when the entry has one, else its `input` rate. Streamed usage is the sum of every step.

**Finish reasons.** `LlmResponse::$finishReason` (`toArray()['finish_reason']`) is Prism's: `stop`, `length`, `tool-calls`, `content-filter`, `refusal`, `pause`, `error`, `other`, `unknown`. A streamed call that is cut by `$maxTokens` returns `length` with the real usage and the text so far. A non-streamed OpenAI call cut by `$maxTokens` still throws `max tokens exceeded`. A streamed call that ends in an error (a provider error event, `response.failed`) throws a `PrismException` after its events were sent to the stream; a refusal is a normal `refusal` response.

## MCP tools

`mcp($server)` adds the server's tools through Relay into the same path as `tools()`. MCP requests never stream and never cache.

```php
namespace App\Nodes;

use Prism\Prism\Enums\ToolChoice;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class LibraryDocsNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $response = $this->llm()
            ->system('Resolve the library id, query its docs, then answer.')
            ->prompt('{question}')
            ->vars(['question' => (string) $state->get('question')])
            ->mcp('context7')
            ->toolChoice(ToolChoice::Any)
            ->maxSteps(5)
            ->run();

        return $state
            ->set('answer', $response->text)
            ->set('tool_calls', $response->toArray()['tool_calls']);
    }
}
```

```php
// config/relay.php (php artisan vendor:publish --tag=relay-config)
use Prism\Relay\Enums\Transport;

return [
    'servers' => [
        'context7' => [
            'transport' => Transport::Stdio,
            'command' => ['npx', '-y', '@upstash/context7-mcp'],
            'timeout' => 60,
            'env' => [],
        ],
    ],
];
```

## Reasoning

```php
class AnswerNode extends PrismNode
{
    protected ?string $reasoning = 'low'; // off | low | medium | high; null sends nothing

    public function execute(StateContract $state): StateContract
    {
        // ->reasoning(budget: 2000) for a provider that takes a token budget; a call wins over the node
        return $state->set('answer', $this->llm()->prompt('{q}')->vars(['q' => (string) $state->get('q', '')])->reasoning('high')->text());
    }
}
```

- The package maps the level to the provider's option (OpenAI `reasoning.effort`, Gemini `thinkingLevel` / `thinkingBudget`, Anthropic `thinking` (`off` sends `thinking.type = disabled`, which Claude 5 models need because they think by default; a level or budget sends your budget from `agenticgraph.prism.reasoning.budgets.{low,medium,high}`), OpenRouter `reasoning`, Ollama `thinking`). OpenAI `off` sends the value you set in `agenticgraph.prism.reasoning.off.openai` (it differs per model; no default, missing throws). It knows the provider, never the model: a vendor 4xx comes back as a `ReasoningException` with the level and the option sent.
- `off` that still reports thought tokens: a warning, a `ReasoningNotDisabled` event and `LlmResponse::$warnings`; not an exception.
- `explain()['settings']['reasoning']` and `['provider_options']` show the level, its source and the exact options passed to Prism.
