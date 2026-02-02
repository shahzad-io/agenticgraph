<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Routers\AbstractRouter;

class CDNodeA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class CDNodeB extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class CDNodeC extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class CDRouter extends AbstractRouter
{
    public function route(StateContract $state): string|array
    {
        return 'A';
    }

    public function getAvailableRoutes(): array
    {
        return ['A', 'B'];
    }
}

describe('detectCycles', function () {

    it('returns empty array for DAG without cycles', function () {
        $graph = GraphBuilder::create('dag-test')
            ->addNode('start', CDNodeA::class)
            ->addNode('middle', CDNodeB::class)
            ->addNode('end', CDNodeC::class)
            ->addEdge('start', 'middle')
            ->addEdge('middle', 'end')
            ->setEntryPoint('start')
            ->addExitPoint('end')
            ->build();

        $cycles = $graph->detectCycles();

        expect($cycles)->toBe([]);
    });

    it('detects simple cycle A→B→A', function () {
        $graph = GraphBuilder::create('cycle-test')
            ->addNode('A', CDNodeA::class)
            ->addNode('B', CDNodeB::class)
            ->addConditionalEdge('A', CDRouter::class)
            ->addEdge('B', 'A')
            ->setEntryPoint('A')
            ->addExitPoint('A')
            ->build();

        $cycles = $graph->detectCycles();

        // Should detect at least one cycle A→B→A
        $cycleHashes = array_map(fn ($c) => $c['hash'], $cycles);
        expect($cycleHashes)->toContain('A→B→A');

        // Verify the cycle path structure
        $foundCycle = null;
        foreach ($cycles as $cycle) {
            if ($cycle['hash'] === 'A→B→A') {
                $foundCycle = $cycle;
                break;
            }
        }
        expect($foundCycle)->not->toBeNull();
        expect($foundCycle['path'])->toBe(['A', 'B', 'A']);
    });

    it('detects self-loop A→A', function () {
        $graph = GraphBuilder::create('self-loop-test')
            ->addNode('A', CDNodeA::class)
            ->addConditionalEdge('A', CDRouter::class)
            ->setEntryPoint('A')
            ->addExitPoint('A')
            ->build();

        $cycles = $graph->detectCycles();

        expect($cycles)->toHaveCount(1);
        expect($cycles[0]['hash'])->toBe('A→A');
        expect($cycles[0]['path'])->toBe(['A', 'A']);
    });

});
