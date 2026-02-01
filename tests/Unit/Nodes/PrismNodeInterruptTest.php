<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class TestApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('processed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval(
            $state,
            $humanInput['approved'] ?? false,
            $humanInput['comments'] ?? null
        );
    }
}

class TestValidationNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('validation_complete', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('validated_data');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Please validate the data';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        if (is_array($humanInput)) {
            return $state->set('validated_data', $humanInput);
        }

        return $state;
    }
}

class NeverInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

it('should interrupt when not approved', function () {
    $node = new TestApprovalNode;
    $state = new State;

    expect($node->shouldInterrupt($state))->toBeTrue;
});

it('should not interrupt when approved', function () {
    $node = new TestApprovalNode;
    $state = new State(['approved' => true]);

    expect($node->shouldInterrupt($state))->toBeFalse;
});

it('handles resume with approval', function () {
    $node = new TestApprovalNode;
    $state = new State;

    $result = $node->onResume($state, [
        'approved' => true,
        'comments' => 'Looks good',
    ]);

    expect($result->get('approved'))->toBeTrue;
    expect($result->get('approval_comments'))->toBe('Looks good');
});

it('handles resume with rejection', function () {
    $node = new TestApprovalNode;
    $state = new State;

    $result = $node->onResume($state, [
        'approved' => false,
        'comments' => 'Needs more work',
    ]);

    expect($result->get('approved'))->toBeFalse;
    expect($result->get('approval_comments'))->toBe('Needs more work');
});

it('provides custom interrupt reason', function () {
    $node = new TestApprovalNode;
    $state = new State;

    $reason = $node->getInterruptReason($state);

    expect($reason)->toBe('Needs approval');
});

it('default shouldInterrupt returns false', function () {
    $node = new NeverInterruptNode;
    $state = new State;

    expect($node->shouldInterrupt($state))->toBeFalse;
});

it('default getInterruptReason returns generic message', function () {
    $node = new NeverInterruptNode;
    $state = new State;

    $reason = $node->getInterruptReason($state);

    expect($reason)->toContain('Never Interrupt');
    expect($reason)->toContain('human review');
});

it('default onResume merges array into state', function () {
    $node = new NeverInterruptNode;
    $state = new State(['original' => 'data']);

    $result = $node->onResume($state, ['new' => 'value', 'extra' => 123]);

    expect($result->get('original'))->toBe('data');
    expect($result->get('new'))->toBe('value');
    expect($result->get('extra'))->toBe(123);
});

it('default onResume handles non-array input gracefully', function () {
    $node = new NeverInterruptNode;
    $state = new State(['original' => 'data']);

    $result = $node->onResume($state, 'some string');

    expect($result->get('original'))->toBe('data');
});

it('validation node interrupts when data not validated', function () {
    $node = new TestValidationNode;
    $state = new State;

    expect($node->shouldInterrupt($state))->toBeTrue;
});

it('validation node does not interrupt when data validated', function () {
    $node = new TestValidationNode;
    $state = new State(['validated_data' => ['email' => 'test@test.com']]);

    expect($node->shouldInterrupt($state))->toBeFalse;
});

it('validation node stores data on resume', function () {
    $node = new TestValidationNode;
    $state = new State;

    $result = $node->onResume($state, ['email' => 'test@test.com', 'name' => 'John']);

    expect($result->get('validated_data'))->toBe(['email' => 'test@test.com', 'name' => 'John']);
});
