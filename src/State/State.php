<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\State;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use JsonSerializable;
use Shahzad\AgenticGraph\Contracts\StateContract;

/**
 * @implements Arrayable<string, mixed>
 */
final class State implements Arrayable, JsonSerializable, StateContract
{
    /**
     * @var array<string, mixed>
     */
    protected array $data;

    /**
     * @var array<string, mixed>
     */
    protected array $metadata;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(array $data = [], array $metadata = [])
    {
        $this->data = $data;
        $this->metadata = $metadata;
    }

    public function set(string $key, mixed $value): static
    {
        $data = $this->data;
        Arr::set($data, $key, $value);

        return new self($data, $this->metadata);
    }

    public function merge(StateContract $other): static
    {
        return new static(
            $this->mergeArrays($this->data, $other->toArray()),
            $this->mergeArrays($this->metadata, $other->getMetadata())
        );
    }

    /**
     * Maximum recursion depth for array merging to prevent infinite loops.
     */
    private const MAX_MERGE_DEPTH = 10;

    /**
     * Merge arrays with proper handling of numeric vs associative arrays.
     * Includes depth limit to prevent infinite recursion on circular references.
     *
     * @param  array<string, mixed>  $array1
     * @param  array<string, mixed>  $array2
     * @param  int  $depth  Current recursion depth
     * @return array<string, mixed>
     */
    private function mergeArrays(array $array1, array $array2, int $depth = 0): array
    {
        // Prevent infinite recursion
        if ($depth >= self::MAX_MERGE_DEPTH) {
            return $array2;
        }

        foreach ($array2 as $key => $value) {
            // Handle objects - convert to array if possible, otherwise replace
            if (is_object($value)) {
                // If value is an object, replace completely (don't try to merge)
                $array1[$key] = $value;
            } elseif (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                // If both are arrays, check if they're numeric (lists) or associative
                if (array_is_list($array1[$key]) && array_is_list($value)) {
                    // For numeric arrays, replace completely (don't merge)
                    $array1[$key] = $value;
                } else {
                    // For associative arrays, merge recursively
                    $array1[$key] = $this->mergeArrays($array1[$key], $value, $depth + 1);
                }
            } else {
                // For non-array values, replace
                $array1[$key] = $value;
            }
        }

        return $array1;
    }

    public function validate(): bool
    {
        $rules = $this->rules();
        if (empty($rules)) {
            return true;
        }
        $validator = Validator::make($this->data, $rules);
        if ($validator->fails()) {
            throw new StateValidationException($validator->errors()->toJson());
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->data, $key);
    }

    public function forget(string $key): static
    {
        $data = $this->data;
        Arr::forget($data, $key);

        return new static($data, $this->metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public static function toStoragePayload(StateContract $state): mixed
    {
        return [
            '__agenticgraph_state' => [
                'data' => $state->toArray(),
                'metadata' => $state->getMetadata(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $payload = $data['__agenticgraph_state'] ?? null;
        if (is_array($payload)) {
            return new static($payload['data'] ?? [], $payload['metadata'] ?? []);
        }

        return new static($data);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setInterrupt(string $reason, string $checkpointId): static
    {
        $data = $this->data;
        Arr::set($data, '_interrupt', [
            'reason' => $reason,
            'checkpoint_id' => $checkpointId,
            'created_at' => (new \DateTimeImmutable)->format(DATE_ATOM),
        ]);

        return new self($data, $this->metadata);
    }

    public function hasInterrupt(): bool
    {
        return $this->has('_interrupt');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInterrupt(): ?array
    {
        return $this->get('_interrupt');
    }

    public function getInterruptCheckpointId(): ?string
    {
        return $this->get('_interrupt.checkpoint_id');
    }

    public function clearInterrupt(): static
    {
        return $this->forget('_interrupt');
    }
}
