# PrismNode examples

Docs: `/docs/nodes`, `/docs/api/nodes`, `/docs/mcp`

## A prompt

```php
namespace App\Nodes;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class DraftNode extends PrismNode
{
    protected ?string $model = 'openai:gpt-4o-mini';

    public function execute(StateContract $state): StateContract
    {
        $draft = $this->prompt('Write a short post about {topic}.', [
            'topic' => $state->get('topic'),
        ]);

        return $state->set('draft', $draft);
    }
}
```

## Structured output

The root schema should be an object. `structured()` takes a string, so format a template first.

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

        $result = $this->structured("Normalize this signup payload: {$signup}", $schema);

        return $state->set('lead', is_array($result) ? $result : []);
    }
}
```

## A tool loop with agent()

`agent()` runs Prism's multi-step tool loop and returns a `TextResponse`. `maxSteps` defaults to 5. Tools passed here are traced; tools added to raw `prism()` are not.

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

        $response = $this->agent((string) $state->get('message'), [$convert], [
            'systemPrompt' => 'You are a currency conversion agent.',
            'maxSteps' => 5,
        ]);

        return $state->set('response', $response->text);
    }
}
```

`tools($prompt, $tools)` is the single-step variant: it returns `ToolCall[]` and no text answer.

## MCP tools

```php
namespace App\Nodes;

use Prism\Prism\Enums\ToolChoice;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class LibraryDocsNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $mcp = $this->withMcp('context7', ['toolChoice' => ToolChoice::Any, 'maxSteps' => 5])
            ->withSystemPrompt('Resolve the library id, query its docs, then answer.')
            ->withPrompt((string) $state->get('question'))
            ->asMcp();

        return $state
            ->set('answer', $mcp->cleanText())
            ->set('tool_calls', $mcp->toolCalls());
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
