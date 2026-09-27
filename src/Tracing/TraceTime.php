<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Single place where trace timestamps are built, formatted, and shifted.
 * Every tracing timestamp is written in UTC with microsecond precision.
 */
final class TraceTime
{
    public const FORMAT = 'Y-m-d\TH:i:s.uP';

    public static function fromMicrotime(float $microtime): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $microtime))
            ?: new \DateTimeImmutable('@'.(int) $microtime);
    }

    public static function now(): \DateTimeImmutable
    {
        return self::fromMicrotime(microtime(true));
    }

    public static function format(float|\DateTimeInterface $at): string
    {
        $at = is_float($at) ? self::fromMicrotime($at) : \DateTimeImmutable::createFromInterface($at);

        return $at->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function toMicrotime(\DateTimeInterface $at): float
    {
        return (float) $at->format('U.u');
    }

    /**
     * Shift by milliseconds with microsecond precision. Negative values move back in time.
     */
    public static function addMs(\DateTimeInterface $at, float $ms): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at)
            ->modify(sprintf('%+d microseconds', (int) round($ms * 1000)));
    }

    /**
     * Milliseconds between two instants, computed from their raw microsecond values.
     */
    public static function diffMs(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        // Integer microseconds ('Uu') stay exact; float epoch seconds do not.
        return ((int) $to->format('Uu') - (int) $from->format('Uu')) / 1000;
    }

    /**
     * Resolve a trace end from raw time. A positive $durationMs (measured by the caller) is
     * kept; otherwise the duration is wall time from $startedAt until now.
     *
     * @return array{0: \DateTimeImmutable, 1: float}
     */
    public static function traceEnd(?\DateTimeInterface $startedAt, float $durationMs): array
    {
        if ($startedAt && $durationMs > 0) {
            return [self::addMs($startedAt, $durationMs), $durationMs];
        }

        $endedAt = self::now();

        return [$endedAt, $startedAt ? max(self::diffMs($startedAt, $endedAt), 0.0) : max($durationMs, 0.0)];
    }
}
