<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;

final class StreamNodeCompletePayloadBuilder
{
    private const VALID_MODES = ['none', 'diff', 'full'];

    public function __construct(
        private readonly StateManager $stateManager,
        private readonly string $captureMode = 'none',
    ) {}

    public static function fromConfig(StateManager $stateManager): self
    {
        $mode = (string) config('agenticgraph.streaming.capture.state', 'none');
        if (! in_array($mode, self::VALID_MODES, true)) {
            $mode = 'none';
        }

        return new self($stateManager, $mode);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        string $nodeName,
        float $durationMs,
        StateContract $inputState,
        StateContract $outputState
    ): array {
        $payload = [
            'node_name' => $nodeName,
            'duration_ms' => $durationMs,
        ];

        return match ($this->captureMode) {
            'diff' => $payload + ['state_diff' => $this->stateManager->diff($inputState, $outputState)],
            'full' => $payload + ['state' => State::toStoragePayload($outputState)],
            default => $payload,
        };
    }
}
