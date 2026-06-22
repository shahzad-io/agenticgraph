<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

final class ExecutionOptions
{
    public function __construct(
        public readonly ExecutionMode $mode = ExecutionMode::SYNC,
        public readonly StreamingMode $streaming = StreamingMode::NONE,
        public readonly bool $interrupts = true,
        public readonly ?int $estimatedDurationSeconds = null
    ) {}

    public function withStreaming(StreamingMode $streaming): self
    {
        return new self(
            mode: $this->mode,
            streaming: $streaming,
            interrupts: $this->interrupts,
            estimatedDurationSeconds: $this->estimatedDurationSeconds,
        );
    }

    /**
     * @return array{
     *     mode: string,
     *     streaming: string,
     *     interrupts: bool,
     *     estimated_duration_seconds: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'streaming' => $this->streaming->value,
            'interrupts' => $this->interrupts,
            'estimated_duration_seconds' => $this->estimatedDurationSeconds,
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
}
