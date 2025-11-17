<?php

namespace Shahzad\AgenticGraph\State;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use JsonSerializable;
use Shahzad\AgenticGraph\Contracts\StateContract;

final class State implements StateContract, JsonSerializable, Arrayable
{
    /**
     * @var array<string, mixed>
     */
    protected array $data;
    /**
     * @var array<string, mixed>
     */
    protected array $metadata;

    public function __construct(array $data = [], array $metadata = [])
    {
        $this->data = $data;
        $this->metadata = $metadata;
    }

    /**
     * @return static
     */
    public function set(string $key, mixed $value): static
    {
        $data = $this->data;
        Arr::set($data, $key, $value);
        return new static($data, $this->metadata);
    }

    /**
     * @return static
     */
    public function merge(StateContract $other): static
    {
        return new static(
            $this->mergeArrays($this->data, $other->toArray()),
            $this->mergeArrays($this->metadata, $other->getMetadata())
        );
    }

    /**
     * Merge arrays with proper handling of numeric vs associative arrays
     *
     * @param array<string, mixed> $array1
     * @param array<string, mixed> $array2
     * @return array<string, mixed>
     */
    private function mergeArrays(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                // If both are arrays, check if they're numeric (lists) or associative
                if (array_is_list($array1[$key]) && array_is_list($value)) {
                    // For numeric arrays, replace completely (don't merge)
                    $array1[$key] = $value;
                } else {
                    // For associative arrays, merge recursively
                    $array1[$key] = $this->mergeArrays($array1[$key], $value);
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

    /**
     * @return static
     */
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

    /**
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
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
}
