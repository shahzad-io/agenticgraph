<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use Prism\Prism\Enums\ToolChoice;
use Shahzad\AgenticGraph\Exceptions\ReasoningException;

/**
 * Turns a reasoning level or token budget into the provider option Prism reads for that provider. The package knows
 * the provider, never the model: a model that cannot apply the option answers with a vendor error, which the executor
 * rethrows with the level and the option sent.
 *
 * @internal Used by {@see LlmExecutor}.
 */
final class ReasoningMapper
{
    public const LEVELS = ['off', 'low', 'medium', 'high'];

    /** The lowest effort OpenAI accepts for 'off'; the live check (B12 step 3) confirms or changes it. */
    private const OPENAI_OFF = 'minimal';

    /**
     * @param  array{low?: mixed, medium?: mixed, high?: mixed}  $budgets  config agenticgraph.prism.reasoning.budgets
     * @return array{options: array<string, mixed>, note: string|null}
     */
    public static function options(string $provider, ?string $level, ?int $budget, bool $forcedToolChoice, array $budgets): array
    {
        $setting = $budget !== null ? "budget {$budget}" : "level [{$level}]";

        if ($provider === 'deepseek') {
            throw ReasoningException::unsupported($provider, $setting, 'Prism sends no reasoning option to DeepSeek');
        }

        if ($budget !== null) {
            return ['options' => match ($provider) {
                'gemini' => ['thinkingBudget' => $budget],
                'anthropic' => self::anthropic($budget, $forcedToolChoice),
                'openrouter' => ['reasoning' => ['max_tokens' => $budget]],
                'openai', 'ollama' => throw ReasoningException::unsupported($provider, $setting, 'this provider takes a level, not a token budget'),
                default => throw self::unknown($provider, $setting),
            }, 'note' => null];
        }

        assert($level !== null);

        return match ($provider) {
            'openai' => ['options' => ['reasoning' => ['effort' => $level === 'off' ? self::OPENAI_OFF : $level]], 'note' => null],
            'gemini' => ['options' => $level === 'off' ? ['thinkingBudget' => 0] : ['thinkingLevel' => $level], 'note' => null],
            'anthropic' => ['options' => $level === 'off'
                ? ['thinking' => ['enabled' => false]]
                : self::anthropic(self::levelBudget($level, $budgets), $forcedToolChoice), 'note' => null],
            'openrouter' => ['options' => ['reasoning' => $level === 'off' ? ['enabled' => false] : ['effort' => $level]], 'note' => null],
            'ollama' => ['options' => ['thinking' => $level !== 'off'], 'note' => $level === 'off' ? null : "Ollama thinks or does not: level [{$level}] is reduced to on"],
            default => throw self::unknown($provider, $setting),
        };
    }

    public static function assertValid(?string $level, ?int $budget): void
    {
        if ($level !== null && $budget !== null) {
            throw new \InvalidArgumentException('A reasoning level and a reasoning budget are exclusive; set one.');
        }
        if ($level !== null && ! in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Reasoning level [{$level}] is not one of: ".implode(', ', self::LEVELS).'.');
        }
        if ($budget !== null && $budget < 1) {
            throw new \InvalidArgumentException('A reasoning budget is a positive number of tokens; use level off to disable reasoning.');
        }
    }

    public static function forcesToolChoice(string|ToolChoice|null $choice): bool
    {
        return $choice !== null && $choice !== ToolChoice::Auto;
    }

    /**
     * @param  array<string, mixed>  $budgets
     */
    private static function levelBudget(string $level, array $budgets): int
    {
        $budget = $budgets[$level] ?? null;

        return is_numeric($budget) && (int) $budget > 0 ? (int) $budget : throw ReasoningException::missingBudget($level);
    }

    /**
     * @return array<string, mixed>
     */
    private static function anthropic(int $budget, bool $forcedToolChoice): array
    {
        if ($forcedToolChoice) {
            throw ReasoningException::unsupported('anthropic', "budget {$budget}", 'Anthropic does not allow thinking with a forced tool choice');
        }

        return ['thinking' => ['enabled' => true, 'budgetTokens' => $budget]];
    }

    private static function unknown(string $provider, string $setting): ReasoningException
    {
        return ReasoningException::unsupported($provider, $setting, 'the package has no mapping for this provider (supported: openai, gemini, anthropic, openrouter, ollama)');
    }
}
