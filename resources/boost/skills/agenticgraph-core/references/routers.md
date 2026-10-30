# Router examples

Docs: `/docs/routing`, `/docs/edges`, `/docs/cyclic`, `/docs/parallel`

Every key a router returns is a node id. `$routes` maps node id to a description; the default `getAvailableRoutes()` is `array_keys($routes)`, and `build()` follows it for reachability.

## PrismRouter

The model picks one route from the descriptions. It never fans out. Subclass it for reuse:

```php
namespace App\Routers;

use Shahzad\AgenticGraph\Routers\PrismRouter;

final class SupportIntentRouter extends PrismRouter
{
    public function __construct()
    {
        parent::__construct(
            routes: [
                'billing' => 'Invoices, refunds, payment failures',
                'technical' => 'Errors, bugs, outages',
                'general' => 'Everything else',
            ],
            defaultRoute: 'general',
        );
    }
}
```

- `routeWithContext()` is final on `PrismRouter`. Change the prompt by overriding `protected function buildPrompt(StateContract $state): string`.
- A missing or invalid answer falls back to `defaultRoute`. Without one, `PrismRouter` throws `RoutingException`.
- If the rule is PHP, use `AbstractRouter` instead.

## Loop-aware router

Count visits so a loop leaves on purpose. The exit is its own node.

```php
namespace App\Routers;

use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Routers\AbstractRouter;

final class ReviewRouter extends AbstractRouter implements LoopAwareRouterInterface
{
    protected array $routes = [
        'publish' => 'Quality reached or out of attempts',
        'draft' => 'Revise again',
    ];

    public function route(StateContract $state): string|array
    {
        return $state->get('quality', 0) > 0.9 ? 'publish' : 'draft';
    }

    public function routeWithContext(StateContract $state, ExecutionContext $context): string|array
    {
        if ($context->getNodeVisitCount('draft') >= 5) {
            return 'publish';
        }

        return $this->route($state);
    }
}
```

```php
GraphBuilder::create('self-improving-writer')
    ->addNode('draft', DraftNode::class)
    ->addNode('critique', CritiqueNode::class)
    ->addNode('publish', PublishNode::class)
    ->addEdge('draft', 'critique')
    ->addConditionalEdge('critique', ReviewRouter::class)
    ->setEntryPoint('draft')
    ->addExitPoint('publish')
    ->build();
```

`LoopAwareRouterInterface` extends `RouterInterface`, so extend `AbstractRouter` and add `routeWithContext()`.

## Fan-out

Return an array to run branches in parallel, then join.

```php
final class FanOutRouter extends AbstractRouter
{
    protected array $routes = [
        'write' => 'Write the article',
        'seo' => 'Write SEO metadata',
    ];

    public function route(StateContract $state): string|array
    {
        return ['write', 'seo'];
    }
}

GraphBuilder::create('parallel-work')
    ->addNode('start', StartNode::class)
    ->addNode('write', WriteNode::class)
    ->addNode('seo', SeoNode::class)
    ->addNode('merge', MergeNode::class)
    ->addConditionalEdge('start', FanOutRouter::class)
    ->addJoin(['write', 'seo'], 'merge')
    ->setEntryPoint('start')
    ->addExitPoint('merge')
    ->build();
```

Each branch writes its own keys (`article`, `seo_meta`). The join node reads both.
