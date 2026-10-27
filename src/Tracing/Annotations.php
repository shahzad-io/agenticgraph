<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

/**
 * Developer-declared trace annotations: placeholder syntax for the builder, and the blocks the tracing
 * listener sends. Rendering never throws into a run; capture.annotations (all, static, none; anything else is none) is applied here.
 */
final class Annotations
{
    /** Graph expectation names and their types. Declarations only: nothing here changes a run. */
    public const GRAPH_EXPECTATIONS = ['read_only' => 'bool', 'max_retries' => 'int', 'max_loops' => 'int', 'max_cost_usd' => 'float'];

    public const TOOL_EXPECTATIONS = ['read_only' => 'bool'];

    private const PATH = '[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*';

    /** @var array<string, true> unknown switch values already warned about */
    private static array $warned = [];

    public static function isPath(string $path): bool
    {
        return preg_match('/^'.self::PATH.'$/', $path) === 1;
    }

    /**
     * The first syntax problem in a template, or null when it is valid.
     */
    public static function syntaxError(string $template): ?string
    {
        try {
            self::parse($template);

            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Annotations for a successful node span, rendered from the state after the node ran.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    public static function forNode(?Graph $graph, string $node, array $state): ?array
    {
        $mode = self::mode();
        $declared = $graph?->annotations['nodes'][$node] ?? [];
        if ($mode === 'none' || (! isset($declared['label']) && ! isset($declared['description']))) {
            return null;
        }

        try {
            $missing = [];
            $block = [];
            foreach (['label', 'description'] as $field) {
                if (isset($declared[$field]) && ($mode === 'all' || ! self::hasPlaceholder($declared[$field]))) {
                    $block[$field] = self::render($declared[$field], $state, $missing);
                }
            }

            return $block === [] ? null : $block + ['missing' => $missing];
        } catch (\Throwable $e) {
            return ['annotation_error' => $e::class];
        }
    }

    /**
     * The trace-level block. $state is the final state, the state at the pause ($partial), or null for a
     * failed run, whose outcome is null: a headline written for success would mislead, and the null
     * replaces a partial outcome sent at an earlier pause when the segments merge.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    public static function forTrace(?Graph $graph, ?array $state, bool $partial = false): ?array
    {
        $mode = self::mode();
        if ($graph === null || $mode === 'none') {
            return null;
        }
        $declared = $graph->annotations;

        $block = [];
        if (isset($declared['label'])) {
            $block['graph'] = ['label' => CaptureDepth::truncate($declared['label'])]
                + ($graph->description !== '' ? ['description' => CaptureDepth::truncate($graph->description)] : []);
        }

        $error = null;
        if (isset($declared['outcome']) && $state === null) {
            $block['outcome'] = null;
        } elseif (isset($declared['outcome'])) {
            try {
                $outcome = self::outcome($declared['outcome'], $state, $partial, $mode);
                if ($outcome !== null) {
                    $block['outcome'] = $outcome;
                }
            } catch (\Throwable $e) {
                $error = $e::class;
            }
        }

        if (isset($declared['expectations'])) {
            $block['expectations'] = $declared['expectations'];
        }
        if ($error !== null) {
            $block['annotation_error'] = $error;
        }

        return $block === [] ? null : $block;
    }

    /**
     * The resume span's human_input: the declared keys found in the resumed state.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function forResume(?Graph $graph, string $node, array $state): array
    {
        $keys = $graph?->annotations['nodes'][$node]['human_input'] ?? [];
        if ($keys === [] || self::mode() !== 'all') {
            return [];
        }

        try {
            $input = [];
            foreach ($keys as $key) {
                [$found, $value] = self::lookup($state, $key);
                if ($found) {
                    $input[$key] = self::value($value);
                }
            }

            return $input === [] ? [] : ['human_input' => $input];
        } catch (\Throwable $e) {
            return ['annotations' => ['annotation_error' => $e::class]];
        }
    }

    /**
     * @return array{expectations: array<string, bool>}|null
     */
    public static function forTool(?Graph $graph, string $tool): ?array
    {
        $expectations = $graph?->annotations['tools'][$tool] ?? null;

        return $expectations === null || self::mode() === 'none' ? null : ['expectations' => $expectations];
    }

    /**
     * The graph of the running execution, or the registered graph with this name.
     */
    public static function graph(?string $name = null): ?Graph
    {
        if (self::mode() === 'none') {
            return null;
        }

        if (app()->bound(Graph::class)) {
            $graph = app(Graph::class);
            if ($name === null || $graph->name === $name) {
                return $graph;
            }
        }

        return $name !== null ? app(GraphRegistry::class)->get($name) : null;
    }

    /**
     * @param  array{headline: string, fields: list<array{path: string, label?: string}>}  $declared
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private static function outcome(array $declared, array $state, bool $partial, string $mode): ?array
    {
        $missing = [];
        $outcome = [];
        if ($mode === 'all' || ! self::hasPlaceholder($declared['headline'])) {
            $outcome['headline'] = self::render($declared['headline'], $state, $missing);
        }

        if ($mode === 'all' && $declared['fields'] !== []) {
            $outcome['fields'] = array_map(function (array $field) use ($state, &$missing): array {
                [$found, $value] = self::lookup($state, $field['path']);
                if (! $found) {
                    $missing[] = $field['path'];
                }

                return ['path' => $field['path']]
                    + (isset($field['label']) ? ['label' => CaptureDepth::truncate($field['label'])] : [])
                    + ['value' => $found ? self::value($value) : null];
            }, $declared['fields']);
        }

        return $outcome === [] ? null : $outcome + ['partial' => $partial, 'missing' => array_values(array_unique($missing))];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $missing
     */
    private static function render(string $template, array $state, array &$missing): ?string
    {
        $text = '';
        foreach (self::parse($template) as $part) {
            if (is_string($part)) {
                $text .= $part;

                continue;
            }

            [$found, $value] = self::lookup($state, $part[0]);
            if (! $found) {
                if (! in_array($part[0], $missing, true)) {
                    $missing[] = $part[0];
                }

                continue;
            }

            $text .= match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => (string) self::encode($value),
            };
        }

        return CaptureDepth::truncate($text);
    }

    /**
     * Literal text as strings, placeholders as [path].
     *
     * @return list<string|array{0: string}>
     */
    private static function parse(string $template): array
    {
        $parts = [];
        $literal = '';
        $length = strlen($template);
        for ($i = 0; $i < $length; $i++) {
            $char = $template[$i];
            if (($char === '{' || $char === '}') && ($template[$i + 1] ?? null) === $char) {
                $literal .= $char;
                $i++;

                continue;
            }
            if ($char === '}') {
                throw new \InvalidArgumentException("unmatched '}' at offset {$i}");
            }
            if ($char !== '{') {
                $literal .= $char;

                continue;
            }

            if (preg_match('/\G\{('.self::PATH.')\}/', $template, $match, 0, $i) !== 1) {
                throw new \InvalidArgumentException("invalid placeholder at offset {$i}; use {path} with letters, digits, _ and - separated by dots");
            }
            if ($literal !== '') {
                $parts[] = $literal;
                $literal = '';
            }
            $parts[] = [$match[1]];
            $i += strlen($match[0]) - 1;
        }

        return $literal === '' ? $parts : [...$parts, $literal];
    }

    private static function hasPlaceholder(string $template): bool
    {
        return array_filter(self::parse($template), is_array(...)) !== [];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{bool, mixed}
     */
    private static function lookup(array $state, string $path): array
    {
        $missing = new \stdClass;
        $value = data_get($state, $path, $missing);

        return $value === $missing ? [false, null] : [true, $value];
    }

    /**
     * A declared value as state capture serializes it, bounded by capture.max_length.
     */
    private static function value(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return CaptureDepth::truncate(is_string($value) ? $value : self::encode($value));
    }

    /**
     * Arrays and JsonSerializable objects as JSON, other objects as their class name.
     */
    private static function encode(mixed $value): ?string
    {
        if (is_object($value) && ! $value instanceof \JsonSerializable) {
            return $value::class;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($encoded) ? $encoded : null;
    }

    private static function mode(): string
    {
        $mode = strtolower((string) config('agenticgraph.tracing.capture.annotations', 'all'));

        if (in_array($mode, ['all', 'static', 'none'], true)) {
            return $mode;
        }

        // A privacy switch fails closed: an unknown value sends nothing.
        if (! isset(self::$warned[$mode])) {
            self::$warned[$mode] = true;
            Log::warning('agenticgraph.annotations.unknown_switch', [
                'value' => $mode,
                'message' => "Unknown tracing.capture.annotations value; treated as 'none'. Use all, static or none.",
            ]);
        }

        return 'none';
    }
}
