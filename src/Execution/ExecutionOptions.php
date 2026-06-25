<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

final class ExecutionOptions
{
    /**
     * @param  list<string>|null  $nodeFilter
     * @param  list<string>|null  $eventTypeFilter
     */
    public function __construct(
        public readonly ExecutionMode $mode = ExecutionMode::SYNC,
        public readonly StreamingMode $streaming = StreamingMode::NONE,
        public readonly bool $interrupts = true,
        public readonly ?int $estimatedDurationSeconds = null,
        public readonly ?array $nodeFilter = null,
        public readonly ?array $eventTypeFilter = null,
    ) {}

    public function withStreaming(StreamingMode $streaming): self
    {
        return new self(
            mode: $this->mode,
            streaming: $streaming,
            interrupts: $this->interrupts,
            estimatedDurationSeconds: $this->estimatedDurationSeconds,
            nodeFilter: $this->nodeFilter,
            eventTypeFilter: $this->eventTypeFilter,
        );
    }

    /**
     * @param  list<string>|null  $nodeFilter
     */
    public function withNodeFilter(?array $nodeFilter): self
    {
        return new self(
            mode: $this->mode,
            streaming: $this->streaming,
            interrupts: $this->interrupts,
            estimatedDurationSeconds: $this->estimatedDurationSeconds,
            nodeFilter: self::normalizeStringListFromMixed($nodeFilter),
            eventTypeFilter: $this->eventTypeFilter,
        );
    }

    /**
     * @param  list<string>|null  $eventTypeFilter
     */
    public function withEventTypeFilter(?array $eventTypeFilter): self
    {
        return new self(
            mode: $this->mode,
            streaming: $this->streaming,
            interrupts: $this->interrupts,
            estimatedDurationSeconds: $this->estimatedDurationSeconds,
            nodeFilter: $this->nodeFilter,
            eventTypeFilter: self::normalizeStringListFromMixed($eventTypeFilter),
        );
    }

    /**
     * @return array{
     *     mode: string,
     *     streaming: string,
     *     interrupts: bool,
     *     estimated_duration_seconds: int|null,
     *     node_filter: list<string>|null,
     *     event_type_filter: list<string>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'streaming' => $this->streaming->value,
            'interrupts' => $this->interrupts,
            'estimated_duration_seconds' => $this->estimatedDurationSeconds,
            'node_filter' => $this->nodeFilter,
            'event_type_filter' => $this->eventTypeFilter,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $estimated = $data['estimated_duration_seconds'] ?? null;

        return new self(
            mode: ExecutionMode::from((string) $data['mode']),
            streaming: StreamingMode::from((string) $data['streaming']),
            interrupts: (bool) ($data['interrupts'] ?? true),
            estimatedDurationSeconds: $estimated === null ? null : (int) $estimated,
            nodeFilter: self::parseStringList($data['node_filter'] ?? null),
            eventTypeFilter: self::parseStringList($data['event_type_filter'] ?? null),
        );
    }

    public static function fromStored(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self;
        }

        if (! is_array($data) || ! isset($data['mode'], $data['streaming'])) {
            return new self;
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<int, mixed>|null  $value
     * @return list<string>|null
     */
    private static function normalizeStringListFromMixed(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $filtered = array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== ''
        ));

        return $filtered === [] ? null : $filtered;
    }

    /**
     * @return list<string>|null
     */
    private static function parseStringList(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        return self::normalizeStringListFromMixed($value);
    }
}
