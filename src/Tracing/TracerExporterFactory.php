<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\FileExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\LogExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\NullExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\OtlpExporter;

final class TracerExporterFactory
{
    public static function make(Container $container): TracerExporter
    {
        // env('X') turns the string "null" into PHP null, so a null value means the null exporter.
        $driver = (string) (config('agenticgraph.tracing.exporter', 'auto') ?? 'null');

        // auto never sends from a test run (spec J-1); an explicit driver still applies.
        if ($driver === 'auto') {
            $driver = match (true) {
                app()->runningUnitTests() => 'null',
                filled(config('agenticgraph.tracing.api_key', null)) => 'pulse',
                default => 'log',
            };
        }

        return match ($driver) {
            'pulse' => $container->make(PulseExporter::class),
            'log' => $container->make(LogExporter::class),
            'file' => $container->make(FileExporter::class),
            'null' => $container->make(NullExporter::class),
            'otlp' => $container->make(OtlpExporter::class),
            default => throw new InvalidArgumentException("Unknown agenticgraph tracing exporter [{$driver}]. Supported: auto, pulse, log, file, null, otlp."),
        };
    }
}
