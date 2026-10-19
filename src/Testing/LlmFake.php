<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Testing;

use PHPUnit\Framework\Assert;
use Prism\Prism\Testing\PrismFake;

/**
 * Returned by {@see Llm::fake()}. Records every model call as the builder resolved it (one record per run(), however
 * many attempts or steps it took) and answers through Prism's fake, so tracing, token events, retries and streaming
 * run as in production.
 *
 * A record: node, vars (as resolved, context() variable included), template_vars (the placeholders the template
 * filled), history (the literal user(), assistant() and history() messages), plus everything explain() returns:
 * messages (as sent), settings (each with its source), stream, cache, tools, schema, context (with items), prompt
 * (the prompt file's name, version and hash).
 */
final class LlmFake
{
    /** @var list<array<string, mixed>> */
    private array $recorded = [];

    public function __construct(private readonly PrismFake $prism) {}

    /**
     * @internal Called by LlmExecutor::run().
     *
     * @param  array<string, mixed>  $record
     */
    public function record(array $record): void
    {
        $this->recorded[] = $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /** The Prism fake underneath, for provider-level assertions (assertRequest, assertProviderConfig). */
    public function prism(): PrismFake
    {
        return $this->prism;
    }

    /**
     * @param  \Closure(array<string, mixed>): bool  $match
     */
    public function assertPrompted(\Closure $match): void
    {
        Assert::assertNotEmpty(array_filter($this->recorded, $match), 'No recorded LLM call matched the callback ('.count($this->recorded).' sent).');
    }

    /** No call filled {$var} from vars(): it reached the model only as literal content, if at all. */
    public function assertNotInterpolated(string $var): void
    {
        $calls = array_keys(array_filter($this->recorded, fn (array $record): bool => in_array($var, $record['template_vars'], true)));

        Assert::assertSame([], $calls, "Variable [{$var}] was interpolated into the template of call(s) #".implode(', #', $calls).'.');
    }

    /**
     * Some call resolved these settings. Each entry is the value, or ['value' => ..., 'source' => ...] to check the
     * layer too (config, node or call). Keys are explain()'s: model, max_tokens, provider_options, reasoning, ...
     *
     * @param  array<string, mixed>  $expected
     */
    public function assertSettings(array $expected): void
    {
        $matches = array_filter($this->recorded, function (array $record) use ($expected): bool {
            foreach ($expected as $key => $value) {
                $setting = $record['settings'][$key] ?? null;
                if ((is_array($value) && array_key_exists('source', $value) ? $setting : $setting['value'] ?? null) != $value) {
                    return false;
                }
            }

            return true;
        });

        Assert::assertNotEmpty($matches, "No recorded LLM call has these settings.\nExpected: ".json_encode($expected)."\nRecorded: ".json_encode(array_column($this->recorded, 'settings')));
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded, "Expected {$count} LLM calls, ".count($this->recorded).' were sent.');
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }
}
