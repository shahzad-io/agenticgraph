<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

final class StreamResumption
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly GraphRegistry $registry,
        private readonly GraphExecutor $executor,
        private readonly LoggerInterface $logger
    ) {}

    public function resumeStream(string $checkpointId, mixed $humanInput, StreamOptions $options): StreamHandle
    {
        $checkpoint = $this->storage->load($checkpointId);
        if ($checkpoint === null) {
            throw new \RuntimeException("Checkpoint {$checkpointId} not found");
        }

        if (! isset($checkpoint->metadata['interrupted']) || $checkpoint->metadata['interrupted'] !== true) {
            throw new \InvalidArgumentException(
                "Checkpoint {$checkpointId} is not an interrupt checkpoint"
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

        $parentSpanId = null;
        if (isset($checkpoint->metadata['trace_context']) && is_array($checkpoint->metadata['trace_context'])) {
            $storedTrace = $checkpoint->metadata['trace_context'];
            $parentSpanId = $storedTrace['current_span_id'] ?? $storedTrace['root_span_id'] ?? null;
        }

        app('events')->dispatch(new NodeResumed(
            executionId: $executionId,
            nodeName: $checkpoint->currentNode,
            checkpointId: $checkpointId,
            stateSnapshot: $state->toArray(),
            resumedAt: TraceTime::now(),
            parentSpanId: is_string($parentSpanId) ? $parentSpanId : null,
        ));

        if ((bool) config('agenticgraph.logging.enabled', true)) {
            $this->logger->info('Execution resumed', [
                'execution_id' => $executionId,
                'checkpoint_id' => $checkpointId,
                'node' => $checkpoint->currentNode,
            ]);
        }

        if (isset($checkpoint->metadata['trace_context']) && is_array($checkpoint->metadata['trace_context'])) {
            $traceContext = TraceContext::fromArray($checkpoint->metadata['trace_context']);
            app()->instance(TraceContext::class, $traceContext);

            if (app()->bound(TracerExporter::class)) {
                $exporter = app(TracerExporter::class);
                if ($exporter->isEnabled()) {
                    $exporter->resumeTrace($executionId);
                }
            }
        }

        $loopMetadata = null;
        if (isset($checkpoint->metadata['loop'])) {
            $loopMetadata = LoopMetadata::fromArray($checkpoint->metadata['loop']);
        }

        $streamId = $checkpoint->metadata['stream_id'] ?? uniqid('stream_', true);
        $lastSeq = $checkpoint->metadata['last_seq'] ?? null;
        if (! is_int($lastSeq)) {
            $lastSeq = 0;
        }

        $gapEvents = [];
        if ($options->storeEnabled && $options->resumeFromSeq !== null) {
            $fromSeq = $options->resumeFromSeq + 1;
            if ($fromSeq <= $lastSeq) {
                $store = app(StreamStoreInterface::class);
                foreach ($store->readFrom($streamId, $fromSeq, 200) as $event) {
                    if ($event->seq() > $lastSeq) {
                        break;
                    }
                    $gapEvents[] = [
                        'seq' => $event->seq(),
                        'node_seq' => $event->nodeSeq(),
                        'node_name' => $event->nodeName(),
                        'type' => $event->type(),
                        'payload' => $event->payload(),
                        'occurred_at' => $event->occurredAt()->format('c'),
                    ];
                }
            }
        }

        $session = new StreamSession(
            streamId: $streamId,
            executionId: $executionId,
            options: $options,
            startedAtUnix: time(),
            seq: $lastSeq
        );
        app()->instance(StreamContext::class, new StreamContext($session));

        $resumePayload = new StreamResumePayload(
            fromSeq: $lastSeq,
            resumedAtSeq: $lastSeq + 1,
            gapEvents: $gapEvents,
            checkpointId: $checkpointId
        );

        return $this->executor->executeWithStreamingFromNode(
            $graph,
            $state,
            $checkpoint->currentNode,
            $executionId,
            $options,
            $loopMetadata,
            $resumePayload
        );
    }
}
