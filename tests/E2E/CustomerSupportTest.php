<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;

class CSStartNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class CSRespondNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $intent = $state->get('intent');
        $text = $this->prompt('Respond to {intent} issue', ['intent' => $intent]);

        return $state->set('response', $text);
    }
}

class CSTechnicalNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('intent', 'technical');
    }
}

class CSBillingNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('intent', 'billing');
    }
}

class CSGeneralNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('intent', 'general');
    }
}

class CSEndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('resolved', true);
    }
}

it('processes customer support workflow end-to-end', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }
    $routeText = TextResponseFake::make()
        ->withText(json_encode(['route' => 'technical']))
        ->withUsage(new Usage(10, 20))
        ->withMeta(new Meta('fake-1', 'fake-model'));
    $respText = TextResponseFake::make()
        ->withText('Technical response')
        ->withUsage(new Usage(10, 20))
        ->withMeta(new Meta('fake-2', 'fake-model'));
    Prism::fake([$routeText, $respText]);
    $router = new PrismRouter([
        'technical' => 'Route to technical',
        'billing' => 'Route to billing',
        'general' => 'Route to general',
    ], defaultRoute: 'general');

    $graph = GraphBuilder::create('customer_support')
        ->addNode('start', CSStartNode::class)
        ->addNode('classify', CSStartNode::class)
        ->addNode('technical', CSTechnicalNode::class)
        ->addNode('billing', CSBillingNode::class)
        ->addNode('general', CSGeneralNode::class)
        ->addNode('respond', CSRespondNode::class)
        ->addNode('end', CSEndNode::class)
        ->addEdge('start', 'classify')
        ->addConditionalEdge('classify', $router)
        ->addEdge('technical', 'respond')
        ->addEdge('billing', 'respond')
        ->addEdge('general', 'respond')
        ->addEdge('respond', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    $state = new State(['message' => 'I cannot log in']);
    $result = (new GraphExecutor)->execute($graph, $state);
    expect($result->get('resolved'))->toBeTrue()
        ->and($result->get('response'))->toContain('Technical');
});
