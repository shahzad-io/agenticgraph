<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Graph;

use Attribute;

/**
 * Trace annotations for a node class: #[Annotate('Look up the order', description: 'Looked up order {order_lookup.order_id}')].
 * The same named arguments on {@see GraphBuilder::addNode()} win per field. Checked when the graph is built.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Annotate
{
    /**
     * @param  list<string>|null  $humanInput  state keys read on a resume of this node
     */
    public function __construct(
        public ?string $label = null,
        public ?string $description = null,
        public ?array $humanInput = null,
    ) {}
}
