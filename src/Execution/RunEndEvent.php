<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionSkipped;
use Shahzad\AgenticGraph\Events\InterruptExpired;

/**
 * Fires the events that report the end of a run. The end is stored by then, so a listener that throws never stops the
 * caller here: its error is logged and returned, and the caller decides what to do with it after its own cleanup.
 *
 * @internal
 */
final class RunEndEvent
{
    /** @var array<string, \Closure(GraphExecutionCompleted|GraphExecutionFailed): void> by execution id */
    private static array $beforeListeners = [];

    /**
     * While $run runs, $work is called once, before the listeners of the event that completes or fails the execution:
     * what must be stored before any listener runs, for example the interrupt of a resume.
     *
     * @param  \Closure(GraphExecutionCompleted|GraphExecutionFailed): void  $work
     * @param  \Closure(): mixed  $run
     */
    public static function beforeListeners(string $executionId, \Closure $work, \Closure $run): mixed
    {
        $previous = self::$beforeListeners[$executionId] ?? null;
        self::$beforeListeners[$executionId] = $work;
        try {
            return $run();
        } finally {
            if ($previous !== null) {
                self::$beforeListeners[$executionId] = $previous;
            } else {
                unset(self::$beforeListeners[$executionId]);
            }
        }
    }

    /**
     * @return \Throwable|null what a listener threw; Laravel stops at the first listener that throws
     */
    public static function fire(
        GraphExecutionCompleted|GraphExecutionFailed|GraphExecutionSkipped|InterruptExpired $event,
        ?Dispatcher $events = null,
    ): ?\Throwable {
        if ($event instanceof GraphExecutionCompleted || $event instanceof GraphExecutionFailed) {
            $work = self::$beforeListeners[$event->executionId] ?? null;
            if ($work !== null) {
                unset(self::$beforeListeners[$event->executionId]);
                try {
                    $work($event);
                } catch (\Throwable $e) {
                    self::report($e);
                }
            }
        }

        try {
            ($events ?? app(Dispatcher::class))->dispatch($event);

            return null;
        } catch (\Throwable $error) {
            // Written even with logging.enabled off, and before any report(): Laravel does not report some
            // exceptions, so this can be the only record.
            try {
                Log::error('AgenticGraph: run-ending event listener failed', [
                    'execution_id' => $event->executionId,
                    'graph_name' => $event->graphName,
                    'event' => $event::class,
                    'error_type' => $error::class,
                    'message' => $error->getMessage(),
                ]);
            } catch (\Throwable) {
                // A logger that is down must not stop the end of the run.
            }

            return $error;
        }
    }

    /** report() that never throws: the caller still has to clean up and throw its own error. */
    public static function report(\Throwable $e): void
    {
        rescue(fn () => report($e), report: false);
    }
}
