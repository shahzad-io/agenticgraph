<?php

use Illuminate\Support\Facades\Validator;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\StateValidationException;

class VNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class ValidatedState implements StateContract
{
    public function __construct(private array $data = []) {}

    public function toArray(): array
    {
        return $this->data;
    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    public function merge(StateContract $other): static
    {
        return new static(array_replace_recursive($this->data, $other->toArray()));
    }

    public function validate(): bool
    {
        $v = Validator::make($this->data, ['foo' => 'required|string']);
        if ($v->fails()) {
            throw new \Shahzad\AgenticGraph\State\StateValidationException($v->errors()->toJson());
        }

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        $copy = $this->data;
        $copy[$key] = $value;

        return new static($copy);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): static
    {
        $copy = $this->data;
        unset($copy[$key]);

        return new static($copy);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function getMetadata(): array
    {
        return [];
    }

    public function setInterrupt(string $reason, string $checkpointId): static
    {
        $copy = $this->data;
        $copy['_interrupt'] = [
            'reason' => $reason,
            'checkpoint_id' => $checkpointId,
            'created_at' => (new \DateTimeImmutable)->format(DATE_ATOM),
        ];

        return new static($copy);
    }

    public function hasInterrupt(): bool
    {
        return isset($this->data['_interrupt']);
    }

    public function getInterrupt(): ?array
    {
        return $this->data['_interrupt'] ?? null;
    }

    public function getInterruptCheckpointId(): ?string
    {
        return $this->data['_interrupt']['checkpoint_id'] ?? null;
    }

    public function clearInterrupt(): static
    {
        $copy = $this->data;
        unset($copy['_interrupt']);

        return new static($copy);
    }
}

it('invokes validation and throws on invalid input', function () {
    $mw = new ValidationMiddleware;
    $node = new VNode;
    $state = new ValidatedState;
    expect(fn () => $mw->handle($state, $node, fn ($s) => $s))->toThrow(StateValidationException::class);
});

it('passes validation on valid input', function () {
    $mw = new ValidationMiddleware;
    $node = new VNode;
    $state = new ValidatedState(['foo' => 'bar']);
    $result = $mw->handle($state, $node, fn ($s) => $s);
    expect($result->get('foo'))->toBe('bar');
});
