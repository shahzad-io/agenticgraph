---
name: "agenticgraph-core"
description: "Guides AgenticGraph graphs, nodes, execution, streaming helpers, interrupts, and MCP usage. Invoke when building or modifying AgenticGraph workflows."
---

# AgenticGraph Core

Use this skill when building or modifying AgenticGraph graphs, nodes, execution, interrupts, streaming, or MCP tooling.

## Installation

```bash
composer require shahzad/agenticgraph
php artisan vendor:publish --provider="Shahzad\\AgenticGraph\\AgenticGraphServiceProvider"
php artisan vendor:publish --tag=prism-config
php artisan vendor:publish --tag=relay-config
php artisan migrate
```

Verify:

```bash
php artisan agenticgraph:list
```

## Configuration

Common `.env` keys:

```env
AGENTICGRAPH_STORAGE=database
AGENTICGRAPH_QUEUE_CONNECTION=database
AGENTICGRAPH_QUEUE_NAME=agenticgraph
AGENTICGRAPH_MAX_ITERATIONS=1000
AGENTICGRAPH_LOCK_TIMEOUT=10
AGENTICGRAPH_PRISM_MODEL=anthropic:claude-sonnet-4
AGENTICGRAPH_PRISM_CACHE=true
AGENTICGRAPH_PRISM_CACHE_TTL=60
AGENTICGRAPH_TRACING=false
AGENTICPULSE_API_KEY=
```

## Core Concepts

- **State** is immutable data flowing through nodes.
- **Nodes** transform state. Use `AbstractNode` for non-LLM and `PrismNode` for LLM calls.
- **Graphs** are built with `GraphBuilder` and registered in `config/agenticgraph.php`.
- **Routers** control branching with deterministic or Prism-based logic.
- **ExecutionHandle** exposes status, streaming, final state, and resume.

## Build and Register a Graph

Create a graph class with `GraphBuilder`:

```php
namespace App\Graphs;

use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\Graph;
use App\Nodes\DraftNode;
use App\Nodes\PublishNode;
use App\Routers\QualityRouter;

final class ContentGraph
{
    public static function build(): Graph
    {
        return GraphBuilder::create('content-approval')
            ->description('Draft, review, and publish content')
            ->addNode('draft', DraftNode::class)
            ->addNode('publish', PublishNode::class)
            ->setEntryPoint('draft')
            ->addConditionalEdge('draft', QualityRouter::class)
            ->addExitPoint('publish')
            ->build();
    }
}
```

Register in `config/agenticgraph.php`:

```php
return [
    'graphs' => [
        'content-approval' => \App\Graphs\ContentGraph::class,
    ],
];
```

## Node Types: AbstractNode vs PrismNode

Choose based on whether you need LLM reasoning:

- **AbstractNode** for deterministic logic, validation, API calls, DB reads/writes.
- **PrismNode** for text generation, classification, extraction, and tool calling.

Example `AbstractNode`:

```php
namespace App\Nodes;

use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

final class ValidateOrderNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $total = $state->get('order.total');
        if ($total < 0) {
            throw new \RuntimeException('Order total cannot be negative');
        }

        return $state->set('order.is_valid', true);
    }
}
```

Example `PrismNode`:

```php
namespace App\Nodes;

use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

final class DraftNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $draft = $this->prompt('Write about: {topic}', [
            'topic' => $state->get('topic'),
        ]);

        return $state->set('draft', $draft);
    }
}
```

## PrismNode Method Cheat Sheet

Use these helpers inside any `PrismNode`:

- `prism()`: get a raw Prism text request
- `prompt(string $template, array $variables = [], array $additionalContent = [])`
- `promptMessages(array $messages)`
- `promptTemplate(PromptTemplate $template, array $variables = [], array $additionalContent = [])`
- `chatPrompt(ChatPromptTemplate $template, array $variables = [], array $additionalContent = [])`
- `structured(string $prompt, mixed $schema)`
- `tools(string $prompt, array $tools)`
- `embeddingsFromInput(string|array $inputs, array $providerOptions = [])`
- `embeddings()`, `image()`, `audio()`
- `generateImage(string $prompt, array $additionalContent = [], array $providerOptions = [])`
- `audioToText(Audio $audio, array $providerOptions = [])`
- `textToAudio(string $text, ?string $voice = null, array $providerOptions = [])`
- `withMcp(string $serverName, array $options = [])`
- `withModel(string $model)`, `withCache(int $ttlMinutes)`, `withoutCache()`
- `shouldInterrupt(StateContract $state)`, `getInterruptReason(StateContract $state)`
- `onResume(StateContract $state, mixed $humanInput)`
- `handleApproval(StateContract $state, bool $approved, ?string $comments = null)`

## Schemas and Tools Examples

Structured extraction with `ObjectSchema` (root schema should be an object):

```php
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class DataExtractorNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $signup = $state->get('input.signup', []);
        if (! is_array($signup)) {
            $signup = [];
        }
        $signupJson = json_encode($signup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $prompt = <<<PROMPT
You are a customer onboarding assistant. Normalize the signup payload into a clean lead profile.

Signup payload: {$signupJson}

Return only the structured result.
PROMPT;

        $schema = new ObjectSchema(
            name: 'lead_profile',
            description: 'Normalized lead profile for onboarding',
            properties: [
                new StringSchema('name', 'Customer full name', nullable: true),
                new StringSchema('email', 'Customer email', nullable: true),
                new StringSchema('company', 'Company name', nullable: true),
                new StringSchema('role', 'Job title or role', nullable: true),
                new StringSchema('goal', 'Primary onboarding goal', nullable: true),
                new ArraySchema(
                    name: 'pain_points',
                    description: 'Key pains or needs',
                    items: new StringSchema('pain', 'A pain point'),
                    nullable: true
                ),
            ],
            requiredFields: ['name', 'email', 'company', 'role', 'goal', 'pain_points']
        );

        $result = $this->structured($prompt, $schema);

        return $state->set('lead', is_array($result) ? $result : []);
    }
}
```

Tool usage with object parameters and a Prism prompt:

```php
use Illuminate\Support\Facades\Http;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Facades\Tool;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class CurrencyToolNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $tools = [
            Tool::as('get_exchange_rate')
                ->for('Get exchange rate and convert currency')
                ->withParameter(new ObjectSchema(
                    name: 'input',
                    description: 'Input for currency conversion',
                    properties: [
                        new StringSchema('from', 'The currency code to convert from (e.g., USD)'),
                        new StringSchema('to', 'The currency code to convert to (e.g., EUR)'),
                        new NumberSchema('amount', 'The amount to convert'),
                    ],
                    requiredFields: ['from', 'to', 'amount']
                ))
                ->using(function (array $input) {
                    $response = Http::get('https://api.currencylayer.com/convert', [
                        'access_key' => config('services.currencylayer.key'),
                        'amount' => $input['amount'],
                        'from' => $input['from'],
                        'to' => $input['to'],
                    ]);

                    $result = $response->json();

                    if ($result['success'] ?? false) {
                        return json_encode([
                            'result' => $result['result'],
                            'rate' => $result['info']['quote'] ?? null,
                            'from' => $input['from'],
                            'to' => $input['to'],
                            'amount' => $input['amount'],
                        ]);
                    }

                    return json_encode(['error' => 'Conversion failed', 'details' => $result]);
                }),
        ];

        $response = $this->prism()
            ->withSystemPrompt('You are a Currency Conversion Agent.')
            ->withTools($tools)
            ->withMaxSteps(5)
            ->withPrompt((string) $state->get('message'))
            ->asText();

        return $state->set('response', $response->text);
    }
}
```

## Routers (Conditional Edges)

Use routers to choose the next node at runtime.

Abstract router example:

```php
namespace App\Routers;

use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\Contracts\StateContract;

final class ScoreRouter extends AbstractRouter
{
    public function __construct()
    {
        $this->routes = [
            'high' => 'High score path',
            'low' => 'Low score path',
        ];
        $this->defaultRoute = 'low';
    }

    public function route(StateContract $state): string
    {
        return $state->get('score', 0) >= 80 ? 'high' : 'low';
    }
}
```

Prism router example:

```php
use Shahzad\AgenticGraph\Routers\PrismRouter;

$router = new PrismRouter([
    'billing' => 'Questions about payment and invoices',
    'technical' => 'Errors, bugs, failures',
], defaultRoute: 'technical');
```

Class-based PrismRouter (recommended for reuse and cleaner graphs):

```php
namespace App\Routers;

use Shahzad\AgenticGraph\Routers\PrismRouter;

final class CustomerIntentRouter extends PrismRouter
{
    public function __construct()
    {
        parent::__construct(
            routes: [
                'billing' => 'Invoices, refunds, payment failures',
                'technical' => 'Errors, bugs, outages',
                'general' => 'Everything else',
            ],
            defaultRoute: 'general'
        );
    }
}
```

Loop-aware routing for cyclic graphs:

```php
use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionContext;

final class QualityRouter implements LoopAwareRouterInterface
{
    public function route(StateContract $state): string|array
    {
        return $state->get('quality', 0) > 0.9 ? 'publish' : 'draft';
    }

    public function routeWithContext(StateContract $state, ExecutionContext $context): string|array
    {
        if ($context->getNodeVisitCount('draft') >= 5) {
            return 'publish';
        }

        return $state->get('quality', 0) > 0.9 ? 'publish' : 'draft';
    }
}
```

## Cyclic Graphs (Loops)

Loop by adding edges that return to earlier nodes and use a router to exit.

```php
use Shahzad\AgenticGraph\Graph\GraphBuilder;

$graph = GraphBuilder::create('self-improving-writer')
    ->addNode('draft', DraftNode::class)
    ->addNode('critique', CritiqueNode::class)
    ->addNode('publish', PublishNode::class)
    ->addEdge('draft', 'critique')
    ->addConditionalEdge('critique', QualityRouter::class)
    ->setEntryPoint('draft')
    ->addExitPoint('publish')
    ->build();
```

## Parallel Execution (Fan-Out / Fan-In)

Return an array of node names from a router to run branches in parallel, then merge with `addJoin`.

```php
use Shahzad\AgenticGraph\Graph\GraphBuilder;

$graph = GraphBuilder::create('parallel-work')
    ->addNode('start', StartNode::class)
    ->addNode('write', WriteNode::class)
    ->addNode('seo', SeoNode::class)
    ->addNode('merge', MergeNode::class)
    ->addConditionalEdge('start', ParallelRouter::class)
    ->addJoin(['write', 'seo'], 'merge')
    ->setEntryPoint('start')
    ->addExitPoint('merge')
    ->build();
```

## Execution Methods

AgenticGraph uses a single entry point with `ExecutionOptions`.

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\State\State;

$handle = AgenticGraph::execute(
    'graph-name',
    new State(['input' => 'value']),
    new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::NONE,
        interrupts: false
    )
);

$finalState = $handle->getFinalState();
```

Key handle methods: `id()`, `status()`, `streamId()`, `stream()`, `getFinalState()`, `isInterrupted()`, `resume()`.

## Interrupts

Enable interrupts in options, then resume through the handle:

```php
$handle = AgenticGraph::execute(
    'graph-name',
    $state,
    new ExecutionOptions(interrupts: true)
);

if ($handle->isInterrupted()) {
    $handle = $handle->resume(['approved' => true]);
}
```

## Streaming

- Modes: `SSE`, `BROADCAST`, `ITERATOR`, `NONE`.
- Recommended pairing: sync + SSE, async + broadcast.
- SSE route: `GET /agentic-graph/streams/{streamId}`.

Domain-specific events:

```php
$this->streamEvent('keywords_item', ['keyword' => $keyword, 'index' => $index]);
$this->whenStreaming(function () use ($payload) {
    $this->streamEvent('meta_partial', $payload);
});
```

Raw Prism events include `text_start`, `text_delta`, `text_complete`.

## MCP Tooling

Relay servers are configured in `config/relay.php`:

```php
return [
    'servers' => [
        'context7' => [
            'command' => ['npx', '-y', '@modelcontextprotocol/server-context7'],
            'timeout' => 30,
            'env' => [],
            'transport' => \Prism\Relay\Enums\Transport::Stdio,
        ],
    ],
];
```

Use Prism or AgenticGraph APIs to call tools rather than ad-hoc HTTP calls.

## Testing

Use unit tests for nodes and routers, and feature tests for full graphs.

Node unit test:

```php
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;

final class CalculateTaxNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $subtotal = (int) $state->get('subtotal', 0);

        return $state->set('total', $subtotal + 10);
    }
}

it('calculates tax', function () {
    $node = new CalculateTaxNode;
    $result = $node->execute(new State(['subtotal' => 100]));

    expect($result->get('total'))->toBe(110);
});
```

Graph feature test:

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;

final class StartNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('started', true);
    }
}

final class EndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('status', 'completed');
    }
}

it('executes a graph end-to-end', function () {
    $graph = GraphBuilder::create('order-flow')
        ->addNode('start', StartNode::class)
        ->addNode('end', EndNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    AgenticGraph::register('order-flow', $graph);

    $handle = AgenticGraph::execute('order-flow', new State);
    $finalState = $handle->getFinalState();

    expect($finalState->get('status'))->toBe('completed');
});
```
