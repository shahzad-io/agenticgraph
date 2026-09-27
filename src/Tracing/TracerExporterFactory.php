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

        if ($driver === 'auto') {
            $driver = filled(config('agenticgraph.tracing.api_key', null))
                ? 'pulse'
                : 'log';
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
