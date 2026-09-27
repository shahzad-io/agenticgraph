<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;

class NodeSeqCoherentAlphaNode extends AbstractNode
{
    protected ?string $name = 'alpha';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'a']);
        $this->streamEvent('text_delta', ['delta' => 'b']);
        $this->streamEvent('text_delta', ['delta' => 'c']);

        return $state->set('alpha_done', true);
    }
}

class NodeSeqCoherentBetaNode extends AbstractNode
{
    protected ?string $name = 'beta';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'x']);

        return $state->set('beta_done', true);
    }
}

class NodeSeqPrismStreamingNode extends PrismNode
{
    protected ?string $name = 'prism_stream';

    public function execute(StateContract $state): StateContract
    {
        $text = $this->prompt('Reply with exactly: hello');

        return $state->set('text', $text);
    }
}

function nodeSeqCoherenceStreamingOptions(): ExecutionOptions
{
    return new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::ITERATOR,
    );
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
});

/**
 * @return list<StreamEvent>
 */
function nodeSeqEventsForNode(array $events, string $nodeName): array
{
    return array_values(array_filter(
        $events,
        fn (StreamEvent $event) => $event->nodeName() === $nodeName
    ));
}

/**
 * @param  list<StreamEvent>  $events
 * @return list<int|null>
 */
function nodeSeqValuesForNode(array $events, string $nodeName): array
{
    return array_map(
        fn (StreamEvent $event) => $event->nodeSeq(),
        nodeSeqEventsForNode($events, $nodeName)
    );
}

it('forms a coherent gapless per-node sequence across lifecycle and streamEvent content', function () {
    $graph = GraphBuilder::create('node-seq-coherent-alpha')
        ->addNode('alpha', NodeSeqCoherentAlphaNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('node-seq-coherent-alpha', $graph);

    $handle = AgenticGraph::execute('node-seq-coherent-alpha', new State, nodeSeqCoherenceStreamingOptions());
    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));

    $alphaSeqs = nodeSeqValuesForNode($events, 'alpha');
    $alphaTypes = array_map(fn (StreamEvent $e) => $e->type(), nodeSeqEventsForNode($events, 'alpha'));

    expect($alphaTypes)->toBe(['node_start', 'text_delta', 'text_delta', 'text_delta', 'node_complete'])
        ->and($alphaSeqs)->toBe([1, 2, 3, 4, 5]);

    $globalSeqs = array_map(fn (StreamEvent $e) => $e->seq(), $events);
    expect($globalSeqs)->toBe(range(1, count($globalSeqs)));
});

it('resets per-node counters independently for sequential nodes', function () {
    $graph = GraphBuilder::create('node-seq-two-nodes')
        ->addNode('alpha', NodeSeqCoherentAlphaNode::class)
        ->addNode('beta', NodeSeqCoherentBetaNode::class)
        ->addEdge('alpha', 'beta')
        ->setEntryPoint('alpha')
        ->addExitPoint('beta')
        ->build();

    AgenticGraph::register('node-seq-two-nodes', $graph);

    $handle = AgenticGraph::execute('node-seq-two-nodes', new State, nodeSeqCoherenceStreamingOptions());
    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));

    expect(nodeSeqValuesForNode($events, 'alpha'))->toBe([1, 2, 3, 4, 5])
        ->and(nodeSeqValuesForNode($events, 'beta'))->toBe([1, 2, 3]);
});

it('leaves graph-level lifecycle events with null node_seq', function () {
    $graph = GraphBuilder::create('node-seq-graph-level')
        ->addNode('alpha', NodeSeqCoherentAlphaNode::class)
        ->setEntryPoint('alpha')
        ->addExitPoint('alpha')
        ->build();

    AgenticGraph::register('node-seq-graph-level', $graph);

    $handle = AgenticGraph::execute('node-seq-graph-level', new State, nodeSeqCoherenceStreamingOptions());
    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));

    $graphStarts = array_filter($events, fn (StreamEvent $e) => $e->type() === StreamLifecycleEventTypes::GRAPH_START);
    $graphCompletes = array_filter($events, fn (StreamEvent $e) => $e->type() === StreamLifecycleEventTypes::GRAPH_COMPLETE);

    expect($graphStarts)->not->toBeEmpty()
        ->and($graphCompletes)->not->toBeEmpty()
        ->and(array_values($graphStarts)[0]->nodeSeq())->toBeNull()
        ->and(array_values($graphCompletes)[0]->nodeSeq())->toBeNull();
});

it('populates coherent node_seq on Prism streaming text_delta events via real provider', function () {
    $apiKey = env('OPENAI_API_KEY');
    if (! is_string($apiKey) || $apiKey === '') {
        expect(true)->toBeTrue('OPENAI_API_KEY not configured, skipping real-provider Prism node_seq test');

        return;
    }

    config()->set('agenticgraph.tracing.enabled', false);
    config()->set('agenticgraph.prism.cache_enabled', false);
    config()->set('agenticgraph.prism.model', 'openai:gpt-4o-mini');

    $graph = GraphBuilder::create('node-seq-prism-real')
        ->addNode('prism_stream', NodeSeqPrismStreamingNode::class)
        ->setEntryPoint('prism_stream')
        ->addExitPoint('prism_stream')
        ->build();

    AgenticGraph::register('node-seq-prism-real', $graph);

    $handle = AgenticGraph::execute('node-seq-prism-real', new State, nodeSeqCoherenceStreamingOptions());
    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $prismEvents = nodeSeqEventsForNode($events, 'prism_stream');

    $start = collect($prismEvents)->firstWhere(fn (StreamEvent $e) => $e->type() === 'node_start');
    $complete = collect($prismEvents)->firstWhere(fn (StreamEvent $e) => $e->type() === 'node_complete');
    $deltas = array_values(array_filter($prismEvents, fn (StreamEvent $e) => $e->type() === 'text_delta'));

    expect($start)->not->toBeNull()
        ->and($complete)->not->toBeNull()
        ->and($deltas)->not->toBeEmpty()
        ->and($start->nodeSeq())->toBe(1);

    $deltaSeqs = array_map(fn (StreamEvent $e) => $e->nodeSeq(), $deltas);
    $expectedDeltaSeqs = range(2, 1 + count($deltas));

    expect($deltaSeqs)->toBe($expectedDeltaSeqs)
        ->and($complete->nodeSeq())->toBe(2 + count($deltas));

    $allPrismSeqs = nodeSeqValuesForNode($events, 'prism_stream');
    expect($allPrismSeqs)->toBe(range(1, count($allPrismSeqs)));
});
