<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use InvalidArgumentException;

final class MissingModelException extends InvalidArgumentException
{
    public static function notConfigured(): self
    {
        return new self(
            'No Prism model configured. Set AGENTICGRAPH_PRISM_MODEL (config agenticgraph.prism.model) '.
            'or the protected ?string $model property on the node or router, as provider:model '.
            '(for example openai:gpt-4o-mini).'
        );
    }

    public static function missingProvider(string $model): self
    {
        return new self(
            "Prism model [{$model}] has no provider. Use provider:model (for example openai:{$model}) ".
            'in AGENTICGRAPH_PRISM_MODEL or the $model property.'
        );
    }
}
