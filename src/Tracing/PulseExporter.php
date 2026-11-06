<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Composer\InstalledVersions;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Shahzad\AgenticGraph\Jobs\SendPulseSegmentJob;
use Shahzad\AgenticGraph\Tracing\Exporters\BufferedTraceExporter;

/**
 * Premium tracer exporter for AgenticPulse SaaS.
 * Posts the trace envelope to {endpoint}/v1/ingest once per segment.
 */
class PulseExporter extends BufferedTraceExporter
{
    private ?string $endpoint = null;

    private ?string $apiKey = null;

    public function __construct()
    {
        parent::__construct();

        $this->endpoint = config('agenticgraph.tracing.endpoint', 'https://ingest.agenticgraph.dev');
        $this->apiKey = config('agenticgraph.tracing.api_key', null);
        $this->refuseInsecureEndpoint();
    }

    /**
     * An http:// endpoint is redirected to https://, which turns the POST into a GET (405), so every segment would be
     * lost. Local hosts are allowed. A refused endpoint switches the exporter off and logs the setting to fix.
     */
    private function refuseInsecureEndpoint(): void
    {
        $endpoint = (string) $this->endpoint;
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $local = $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test');
        if ($scheme === 'https' || $local) {
            return;
        }

        $this->endpoint = null;
        Log::error(sprintf(
            'AgenticGraph: Pulse endpoint must use https (agenticgraph.tracing.endpoint / AGENTICPULSE_ENDPOINT = %s://%s); tracing to Pulse is off. Use the base URL, for example https://ingest.agenticgraph.dev, with no path.',
            $scheme === '' ? '(none)' : $scheme,
            $host === '' ? '(no host)' : $host,
        ));
    }

    /**
     * Check if the exporter is configured and enabled. tracing.enabled is read per call, so a runtime change applies.
     */
    public function isEnabled(): bool
    {
        return (bool) config('agenticgraph.tracing.enabled', false) && ! empty($this->apiKey) && $this->endpoint !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): void
    {
        try {
            $this->sendBody(
                TracePayloadBuilder::encode($payload),
                (string) ($payload['trace']['id'] ?? ''),
                (string) ($payload['segment']['id'] ?? ''),
            );
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
     * One POST of the exact body. Short timeouts and a second attempt on a connection error or a 5xx,
     * never on a 4xx. A 429 is handled by the caller. Throws ConnectionException once the attempts are spent.
     */
    public function post(string $body): Response
    {
        /** @var Response */
        return Http::withToken($this->apiKey)
            ->withUserAgent('agenticgraph/'.self::version())
            ->acceptJson()
            ->connectTimeout((float) config('agenticgraph.tracing.transport.connect_timeout', 1))
            ->timeout((float) config('agenticgraph.tracing.transport.timeout', 3))
            ->retry(2, 500, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false)
            ->withBody($body, 'application/json')
            ->post("{$this->endpoint}/v1/ingest");
    }

    private function sendBody(string $body, string $traceId, string $segmentId): void
    {
        try {
            $response = $this->post($body);
            if ($response->status() === 429) {
                $response = $this->afterRateLimit($response, $body, $traceId, $segmentId);
            }
        } catch (ConnectionException $e) {
            $this->logFailure(['code' => 'connection_error', 'error' => $e->getMessage()]);

            return;
        }

        if ($response?->failed()) {
            $this->logFailure([
                'status' => $response->status(),
                'code' => $response->json('code'),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * The response to report after a 429; null when a delayed job took the segment.
     * No Retry-After: not retried, the 429 is the logged loss. Up to retry_after_wait seconds: wait here and
     * post again once. Longer: a delayed job when the app has a real queue, else wait the cap and post once.
     */
    private function afterRateLimit(Response $response, string $body, string $traceId, string $segmentId): ?Response
    {
        $wait = self::retryAfter($response);
        if ($wait === null) {
            return $response;
        }

        $cap = (int) config('agenticgraph.tracing.transport.retry_after_wait', 2);
        if ($wait > $cap && $this->hasRealQueue()) {
            try {
                $delay = min($wait, (int) config('agenticgraph.tracing.transport.max_delay', 300));
                app(Dispatcher::class)->dispatch((new SendPulseSegmentJob($body, $traceId, $segmentId))->delay($delay));

                return null;
            } catch (\Throwable $e) {
                // For example an SQS message over 256 KB: fall through to wait and retry once.
                $this->logFailure(['code' => 'dispatch_failed', 'error' => $e->getMessage(), 'trace_id' => $traceId, 'segment_id' => $segmentId]);
            }
        }

        Sleep::for(min($wait, $cap))->seconds();

        return $this->post($body);
    }

    private function hasRealQueue(): bool
    {
        $connection = config('agenticgraph.tracing.transport.queue_connection') ?? config('queue.default');

        return ! in_array(config("queue.connections.{$connection}.driver"), [null, 'sync', 'null'], true);
    }

    /**
     * Seconds to wait from a Retry-After header, a number of seconds or an HTTP date; null when absent or unreadable.
     */
    public static function retryAfter(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $at = strtotime($value);

        return $at === false ? null : max($at - now()->getTimestamp(), 0);
    }

    private static function version(): string
    {
        static $version = null;

        try {
            return $version ??= (string) InstalledVersions::getPrettyVersion('agenticgraph/agenticgraph');
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function logFailure(array $context): void
    {
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::warning('AgenticPulse ingest failed', $context);
        }
    }
}
