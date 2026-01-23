<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class TestPrismNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $text = $this->prompt('Hello {name}', ['name' => $state->get('name', 'world')]);

        return $state->set('text', $text);
    }
}

it('uses Prism fake to generate text', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }
    $fakeResponse = TextResponseFake::make()->withText('Hello John')->withUsage(new Usage(10, 20));
    Prism::fake([$fakeResponse]);
    $node = new TestPrismNode;
    $state = new State(['name' => 'John']);
    $result = $node->execute($state);
    expect($result->get('text'))->toContain('Hello John');
});
