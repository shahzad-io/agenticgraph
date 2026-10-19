<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use Attribute;

/**
 * Names the item type of an array parameter for {@see DtoHydrator}: #[ListOf(Item::class)] or #[ListOf('string')].
 * A docblock `@param list<Item> $items` on the constructor works too.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class ListOf
{
    public function __construct(public string $type) {}
}
