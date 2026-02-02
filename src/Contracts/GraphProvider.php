<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Shahzad\AgenticGraph\Graph\Graph;

/**
 * Interface for graph classes that can provide their own instance.
 *
 * Graph classes implementing this interface can be auto-discovered
 * and built from config without manual registration.
 *
 * @example
 * class MyGraph implements GraphProvider {
 *     public static function build(): Graph {
 *         return GraphBuilder::create('my-graph')
 *             ->addNode('start', StartNode::class)
 *             ->setEntryPoint('start')
 *             ->build();
 *     }
 * }
 */
interface GraphProvider
{
    /**
     * Build and return the graph instance.
     *
     * This method should be static so it can be called without
     * instantiating the provider class.
     */
    public static function build(): Graph;
}
