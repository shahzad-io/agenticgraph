<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

        $this->endpoint = config('agenticgraph.tracing.endpoint', 'https://ingest.agenticgraph.dev');
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
            // Encoded here so float fields keep their zero fraction on the wire.
            // Short timeouts and one retry on a connection error or 5xx, never on 4xx.
            /** @var Response $response */
            $response = Http::withToken($this->apiKey)
                ->connectTimeout((float) config('agenticgraph.tracing.transport.connect_timeout', 1))
                ->timeout((float) config('agenticgraph.tracing.transport.timeout', 3))
                ->retry(2, 500, fn (\Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->withBody(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 'application/json')
                ->post("{$this->endpoint}/v1/ingest");

            if ($response->failed()) {
                $this->logFailure([
                    'status' => $response->status(),
                    'code' => $response->json('code'),
                    'body' => $response->body(),
                ]);
            }
        } catch (ConnectionException $e) {
            $this->logFailure(['code' => 'connection_error', 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Silent fail - don't break the user's application
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                Log::debug('AgenticPulse ingest error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(array $context): void
    {
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::warning('AgenticPulse ingest failed', $context);
        }
    }
}
