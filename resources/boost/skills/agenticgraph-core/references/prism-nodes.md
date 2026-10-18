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

- The package maps the level to the provider's option (OpenAI `reasoning.effort`, Gemini `thinkingLevel` / `thinkingBudget`, Anthropic `thinking` with your budget from `agenticgraph.prism.reasoning.budgets.{low,medium,high}`, OpenRouter `reasoning`, Ollama `thinking`). It knows the provider, never the model: a vendor 4xx comes back as a `ReasoningException` with the level and the option sent.
- `off` that still reports thought tokens: a warning, a `ReasoningNotDisabled` event and `LlmResponse::$warnings`; not an exception.
- `explain()['settings']['reasoning']` and `['provider_options']` show the level, its source and the exact options passed to Prism.
