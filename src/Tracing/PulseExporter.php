<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\Exporters\BufferedTraceExporter;

/**
 * Premium tracer exporter for AgenticPulse SaaS.
 * Posts the trace envelope to {endpoint}/v1/ingest once per segment.
 */
class PulseExporter extends BufferedTraceExporter
{
    private ?string $endpoint = null;

    private ?string $apiKey = null;

    private bool $enabled = false;

    public function __construct()
    {
        parent::__construct();

        $this->endpoint = config('agenticgraph.tracing.endpoint', 'https://api.agenticpulse.io');
        $this->apiKey = config('agenticgraph.tracing.api_key', null);
        $this->enabled = config('agenticgraph.tracing.enabled', false) && ! empty($this->apiKey);
    }

    /**
     * Check if the exporter is configured and enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): void
    {
        try {
            // Encoded here so float fields keep their zero fraction on the wire (spec G-3).
            /** @var Response $response */
            $response = Http::withToken($this->apiKey)
                ->timeout(5)
                ->withBody(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 'application/json')
                ->post("{$this->endpoint}/v1/ingest");

            if ($response->failed()) {
                if ((bool) config('agenticgraph.logging.enabled', true)) {
                    Log::warning('AgenticPulse ingest failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Silent fail - don't break the user's application
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                Log::debug('AgenticPulse ingest error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
