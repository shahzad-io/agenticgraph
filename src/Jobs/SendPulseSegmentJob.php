<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

/**
 * Re-sends one trace segment that Pulse refused with a 429 and a long Retry-After.
 * Holds the exact encoded body only; the endpoint and API key are read from config when it runs.
 */
class SendPulseSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public function __construct(
        public readonly string $body,
        public readonly string $traceId,
        public readonly string $segmentId,
    ) {
        $this->tries = (int) config('agenticgraph.tracing.transport.job_tries', 5);
        $this->onConnection(config('agenticgraph.tracing.transport.queue_connection'));
        $this->onQueue(config('agenticgraph.tracing.transport.queue_name'));
    }

    public function handle(): void
    {
        $exporter = new PulseExporter;

        // Under the capture guard, as the in-process send is. A job has no execution clock, so no http span is recorded anyway.
        $exporter->whileCapturing(function () use ($exporter): void {
            try {
                $response = $exporter->post($this->body);
            } catch (ConnectionException) {
                $this->release(30 * $this->attempts());

                return;
            }

            if ($response->successful()) {
                return;
            }
            if ($response->status() === 429 && ($wait = PulseExporter::retryAfter($response)) !== null) {
                $this->release(min($wait, (int) config('agenticgraph.tracing.transport.max_delay', 300)));
            } elseif ($response->serverError()) {
                $this->release(30 * $this->attempts());
            } else {
                $exporter->logFailure($this->context() + [
                    'status' => $response->status(),
                    'code' => $response->json('code'),
                    'body' => $response->body(),
                ]);
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::warning('AgenticPulse segment dropped after retries', $this->context() + ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @return array{trace_id: string, segment_id: string}
     */
    private function context(): array
    {
        return ['trace_id' => $this->traceId, 'segment_id' => $this->segmentId];
    }
}
