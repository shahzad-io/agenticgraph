<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\InterruptPending;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventEmitter;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

final class InterruptManager implements InterruptServiceInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ExecutionRepository $repo,
        private readonly CheckpointManager $checkpoints,
        private readonly StorageInterface $storage,
        private readonly GraphRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly Container $container,
        private readonly ExecutionResumer $executeFromNode,
        private readonly InterruptHandlerResolver $interruptHandlerResolver,
        private readonly TraceContextProvider $traceContextProvider,
        private readonly bool $interruptPersistenceEnabled,
        private readonly bool $interruptBroadcastEnabled,
        private readonly int $interruptDefaultExpiryDays,
        private readonly bool $loggingEnabled,
        private readonly ?PulseExporter $pulseExporter = null
    ) {}

    public function shouldInterrupt(NodeInterface $node, StateContract $state): bool
    {
        if (! $node instanceof PrismNode) {
            return false;
        }

        return $node->shouldInterrupt($state);
    }

    public function handle(
        string $executionId,
        string $graphName,
        string $nodeName,
        StateContract $state,
        ?StreamContext $streamContext = null
    ): InterruptResult {
        $graph = $this->registry->getOrBuild($graphName);
        if ($graph === null) {
            throw new \RuntimeException("Graph {$graphName} not found in registry or config");
        }

        $node = $graph->getNode($nodeName);
        if (! $node instanceof PrismNode) {
            throw new \InvalidArgumentException("Node {$nodeName} does not support interrupts");
        }

        $reason = $node->getInterruptReason($state);
        $interruptPayload = $node->getInterruptPayload($state);
        $session = $streamContext?->session();

        $checkpoint = new Checkpoint;
        $checkpoint->graphName = $graph->name;
        $checkpoint->executionId = $executionId;
        $checkpoint->state = $state;
        $checkpoint->currentNode = $nodeName;
        $checkpoint->metadata['interrupted'] = true;
        $checkpoint->metadata['interrupt_reason'] = $reason;
        if ($interruptPayload !== null) {
            $checkpoint->metadata['interrupt_payload'] = $interruptPayload;
        }
        if ($session) {
            $checkpoint->metadata['stream_id'] = $session->streamId;
        }
        $checkpoint->createdAt = new \DateTimeImmutable;

        $traceContext = ($this->traceContextProvider)();
        if ($traceContext) {
            $checkpoint->metadata['trace_context'] = $traceContext->toArray();
        }

        $checkpointId = $this->checkpoints->saveInterrupt($executionId, $checkpoint);

        $state = $state->setInterrupt($reason, $checkpointId);

        $this->repo->interrupted($executionId);

        $this->events->dispatch(new NodeInterrupted(
            executionId: $executionId,
            nodeName: $nodeName,
            reason: $reason,
            checkpointId: $checkpointId,
            stateSnapshot: $state->toArray(),
            interruptedAt: new \DateTimeImmutable
        ));

        $metadata = $state->getMetadata();
        if ($session) {
            $metadata['stream_delivery'] = $session->options->delivery;
            $metadata['stream_id'] = $session->streamId;
        }

        if ($this->interruptPersistenceEnabled) {
            $interruptRecord = InterruptRecord::query()->create([
                'execution_id' => $executionId,
                'checkpoint_id' => $checkpointId,
                'graph_name' => $graph->name,
                'node_name' => $nodeName,
                'reason' => $reason,
                'interrupt_payload' => $interruptPayload,
                'status' => 'pending',
                'expires_at' => $this->interruptDefaultExpiryDays > 0
                    ? now()->addDays($this->interruptDefaultExpiryDays)
                    : null,
                'metadata' => $metadata,
            ]);

            $handler = ($this->interruptHandlerResolver)();
            if ($handler) {
                $interruptRecord = $handler->handle($interruptRecord);
            }

            if ($this->interruptBroadcastEnabled) {
                $interruptId = $interruptRecord->interruptId();
                if ($interruptId === null) {
                    throw new \RuntimeException('Interrupt id is missing.');
                }
                $this->events->dispatch(new InterruptPending(
                    interruptId: $interruptId,
                    executionId: $executionId,
                    graphName: $graph->name,
                    nodeName: $nodeName,
                    reason: $reason,
                    checkpointId: $checkpointId,
                    payload: $interruptPayload,
                    metadata: $metadata,
                    occurredAt: new \DateTimeImmutable
                ));
            }
        } else {
            $interruptRecord = new InterruptRecord([
                'execution_id' => $executionId,
                'checkpoint_id' => $checkpointId,
                'graph_name' => $graph->name,
                'node_name' => $nodeName,
                'reason' => $reason,
                'interrupt_payload' => $interruptPayload,
                'status' => 'pending',
                'metadata' => $metadata,
            ]);

            $this->logger->warning(
                'Interrupt persistence is disabled; AgenticGraph::resume() will not be available for this execution. '.
                'Use a SYNC ExecutionHandle::resume() instead.',
                [
                    'execution_id' => $executionId,
                    'checkpoint_id' => $checkpointId,
                    'node' => $nodeName,
                ]
            );
        }

        $sink = $streamContext?->sink();
        if ($sink && $session) {
            $this->emitLifecycleEvent(
                $sink,
                $session,
                $nodeName,
                $traceContext?->getSpanId(),
                $traceContext?->getParentSpanId(),
                StreamLifecycleEventTypes::NODE_INTERRUPT,
                [
                    'node_name' => $nodeName,
                    'reason' => $reason,
                    'checkpoint_id' => $checkpointId,
                ]
            );

            if ($session->options->interrupts) {
                $sink->close(StreamStatus::CANCELLED, [
                    'reason' => $reason,
                    'checkpoint_id' => $checkpointId,
                    'final_state' => State::toStoragePayload($state),
                ]);
            }
        }

        if ($session) {
            $checkpoint->metadata['last_seq'] = $session->seq;
            $checkpoint->metadata['stream_cursor'] = $session->seq;
            $this->storage->save($checkpoint);

            if ($this->interruptPersistenceEnabled) {
                $metadata['stream_last_seq'] = $session->seq;
                $interruptRecord->update(['metadata' => $metadata]);
            }
        }

        if ($this->pulseExporter && $this->pulseExporter->isEnabled()) {
            $this->pulseExporter->interruptTrace($executionId, $reason);
        }

        if ($this->loggingEnabled) {
            $this->logger->info('Execution interrupted', [
                'execution_id' => $executionId,
                'node' => $nodeName,
                'reason' => $reason,
                'checkpoint_id' => $checkpointId,
                'interrupt_id' => $interruptRecord->interruptId(),
            ]);
        }

        return new InterruptResult(
            state: $state,
            checkpointId: $checkpointId,
            record: $interruptRecord,
            hasStreamContext: $streamContext !== null
        );
    }

    public function resume(string $checkpointId): StateContract
    {
        $checkpoint = $this->storage->load($checkpointId);
        if (! $checkpoint) {
            throw new \RuntimeException("Checkpoint {$checkpointId} not found.");
        }
        $graph = $this->registry->get($checkpoint->graphName);
        if (! $graph) {
            throw new \RuntimeException("Graph {$checkpoint->graphName} not found in registry.");
        }

        $executionId = $checkpoint->executionId;

        if (isset($checkpoint->metadata['trace_context']) && is_array($checkpoint->metadata['trace_context'])) {
            $traceContext = TraceContext::fromArray($checkpoint->metadata['trace_context']);
            $this->container->instance(TraceContext::class, $traceContext);

            if ($this->pulseExporter && $this->pulseExporter->isEnabled()) {
                $this->pulseExporter->resumeTrace($executionId);
            }
        }

        $loopMetadata = null;
        if (isset($checkpoint->metadata['loop'])) {
            $loopMetadata = LoopMetadata::fromArray($checkpoint->metadata['loop']);
        }

        return ($this->executeFromNode)(
            $graph,
            $checkpoint->state,
            $checkpoint->currentNode,
            $executionId,
            true,
            $loopMetadata
        );
    }

    public function resumeWithInput(string $checkpointId, mixed $humanInput): StateContract
    {
        $checkpoint = $this->storage->load($checkpointId);

        if ($checkpoint === null) {
            throw new \RuntimeException(
                "Interrupt checkpoint {$checkpointId} not found. ".
                'Persist the interrupt checkpoint id returned by State::getInterruptCheckpointId() and resume with it.'
            );
        }

        if (! isset($checkpoint->metadata['interrupted']) ||
            $checkpoint->metadata['interrupted'] !== true) {
            throw new \InvalidArgumentException(
                "Checkpoint {$checkpointId} is not an interrupt checkpoint. ".
                'Use the checkpoint id from State::getInterruptCheckpointId() or the interrupt event payload.'
            );
        }

        $graph = $this->registry->getOrBuild($checkpoint->graphName);

        if ($graph === null) {
            throw new \RuntimeException("Graph {$checkpoint->graphName} not found in registry or config");
        }

        $node = $graph->getNode($checkpoint->currentNode);
        $state = $checkpoint->state;
        $executionId = $checkpoint->executionId;

        if ($node instanceof PrismNode) {
            $state = $node->onResume($state, $humanInput);
        }

        $state = $state->clearInterrupt();

        $this->events->dispatch(new NodeResumed(
            executionId: $executionId,
            nodeName: $checkpoint->currentNode,
            checkpointId: $checkpointId,
            stateSnapshot: $state->toArray(),
            resumedAt: new \DateTimeImmutable
        ));

        if ($this->loggingEnabled) {
            $this->logger->info('Execution resumed', [
                'execution_id' => $executionId,
                'checkpoint_id' => $checkpointId,
                'node' => $checkpoint->currentNode,
            ]);
        }

        $loopMetadata = null;
        if (isset($checkpoint->metadata['loop'])) {
            $loopMetadata = LoopMetadata::fromArray($checkpoint->metadata['loop']);
        }

        if (isset($checkpoint->metadata['trace_context']) && is_array($checkpoint->metadata['trace_context'])) {
            $traceContext = TraceContext::fromArray($checkpoint->metadata['trace_context']);
            $this->container->instance(TraceContext::class, $traceContext);

            if ($this->pulseExporter && $this->pulseExporter->isEnabled()) {
                $this->pulseExporter->resumeTrace($executionId);
            }
        }

        return ($this->executeFromNode)(
            $graph,
            $state,
            $checkpoint->currentNode,
            $executionId,
            false,
            $loopMetadata,
            skipInterruptGateForEntry: true
        );
    }

    private function emitLifecycleEvent(
        StreamSinkInterface $sink,
        StreamSession $session,
        ?string $nodeName,
        ?string $spanId,
        ?string $parentSpanId,
        string $type,
        mixed $payload
    ): void {
        StreamLifecycleEventEmitter::emit(
            $sink,
            $session,
            $nodeName,
            $spanId,
            $parentSpanId,
            $type,
            $payload
        );
    }
}
