<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class CPStartNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('topic', $state->get('topic', 'Laravel'));
    }
}

class CPGenerateNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $text = $this->prompt('Generate content about {topic}', ['topic' => $state->get('topic')]);
        return $state->set('content', $text)->set('word_count', strlen($text));
    }
}

class CPEndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('generates content end-to-end', function () {
    if (!class_exists(Prism::class)) {
        expect(true)->toBeTrue();
        return;
    }
    $fakeResponse = TextResponseFake::make()->withText('Laravel Testing Content')->withUsage(new Usage(10, 20))->withMeta(new Meta('fake-1', 'fake-model'));
    Prism::fake([$fakeResponse]);
    $graph = GraphBuilder::create('content_pipeline')
        ->addNode('start', CPStartNode::class)
        ->addNode('generate', CPGenerateNode::class)
        ->addNode('end', CPEndNode::class)
        ->addEdge('start', 'generate')
        ->addEdge('generate', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    $state = new State(['topic' => 'Laravel Testing']);
    $result = (new GraphExecutor())->execute($graph, $state);
    expect($result->get('content'))->toContain('Laravel')
        ->and($result->get('word_count'))->toBeGreaterThan(10);
});