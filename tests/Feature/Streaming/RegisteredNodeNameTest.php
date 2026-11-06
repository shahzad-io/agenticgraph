<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;

// The class is Retrieve, the graph registers it as 'retrieve': every event must carry 'retrieve'.
class Retrieve extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('sources', ['ids' => [1, 2]]);
        $text = $this->llm()->prompt('Say hi')->text();
        $this->streamEvent('sources_done', []);

        return $state->set('text', $text);
    }
}

it('gives custom and model stream events the registered node name and a shared node_seq', function () {
    Prism::fake([TextResponseFake::make()->withText('hello')]);
    AgenticGraph::register('registered-name', GraphBuilder::create('registered-name')
        ->addNode('retrieve', Retrieve::class)
        ->setEntryPoint('retrieve')
        ->addExitPoint('retrieve')
        ->build());

    $handle = AgenticGraph::execute('registered-name', new State, new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR));
    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));

    $inNode = array_values(array_filter($events, fn (StreamEvent $e) => ! in_array($e->type(), ['graph_start', 'graph_complete', 'execution_terminal'], true)));
    $names = array_unique(array_map(fn (StreamEvent $e) => $e->nodeName(), $inNode));
    $types = array_map(fn (StreamEvent $e) => $e->type(), $inNode);
    $seqs = array_map(fn (StreamEvent $e) => $e->nodeSeq(), $inNode);

    expect($names)->toBe(['retrieve'])
        ->and($types)->toContain('sources', 'text_delta', 'sources_done')
        ->and($seqs)->toBe(range(1, count($seqs)));
});
