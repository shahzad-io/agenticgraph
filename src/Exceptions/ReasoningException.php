<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;

final class ReasoningException extends RuntimeException
{
    public static function unsupported(string $provider, string $setting, string $why): self
    {
        return new self("Reasoning {$setting} cannot be applied on the [{$provider}] provider: {$why}. Remove the setting for this node or call, or use a provider that supports it.");
    }

    public static function missingBudget(string $level): self
    {
        return new self("Reasoning level [{$level}] on this provider needs a token budget. Set config agenticgraph.prism.reasoning.budgets.{$level} (env AGENTICGRAPH_PRISM_REASONING_BUDGET_".strtoupper($level).') or pass reasoning(budget: N).');
    }

    public static function missingOff(string $provider): self
    {
        return new self("Reasoning level [off] on the [{$provider}] provider needs the value your models take for no reasoning (it differs per model: for example none, minimal or off). Set config agenticgraph.prism.reasoning.off.{$provider}, or send it for one node with providerOptions(['reasoning' => ['effort' => '<value>']]).");
    }

    /**
     * @param  array<string, mixed>  $options  the provider options sent to Prism
     */
    public static function rejected(string $setting, array $options, \Throwable $previous, ?float $temperature = null): self
    {
        $settings = $temperature !== null ? "reasoning {$setting} and temperature {$temperature}" : "reasoning {$setting}";

        return new self(
            "The provider rejected the request with {$settings} set. Options sent to Prism: ".json_encode($options, JSON_UNESCAPED_SLASHES).'. Provider error: '.$previous->getMessage(),
            (int) $previous->getCode(),
            $previous,
        );
    }
}
