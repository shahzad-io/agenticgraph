<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tests\IDESupport;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\Facades\StateManager;

class DocBlockValidationTest extends TestCase
{
    public function test_prism_node_has_docblock_with_method_annotations(): void
    {
        $reflection = new ReflectionClass(PrismNode::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse(
            $docComment,
            'PrismNode must have a docblock for IDE support'
        );

        // Check for @method annotations
        $this->assertMatchesRegularExpression(
            '/@method/',
            $docComment,
            'PrismNode should document available methods with @method tags'
        );
    }

    public function test_state_manager_has_docblock_with_method_annotations(): void
    {
        $reflection = new ReflectionClass(StateManager::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse(
            $docComment,
            'StateManager must have a docblock for IDE support'
        );

        // Check for @method annotations
        $this->assertMatchesRegularExpression(
            '/@method/',
            $docComment,
            'StateManager should document available methods with @method tags'
        );
    }

    public function test_prism_node_documents_helper_methods(): void
    {
        $reflection = new ReflectionClass(PrismNode::class);
        $docComment = $reflection->getDocComment();

        $requiredMethods = ['prism', 'structured', 'tools', 'embeddings', 'prompt', 'withMcp'];

        foreach ($requiredMethods as $method) {
            $pattern = '/@method\s+.*\s+'.preg_quote($method, '/').'\(/';
            $this->assertMatchesRegularExpression(
                $pattern,
                $docComment,
                "PrismNode must document helper method: {$method}"
            );
        }
    }

    public function test_state_manager_facade_documents_methods(): void
    {
        $reflection = new ReflectionClass(StateManager::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse(
            $docComment,
            'StateManager facade must have a docblock'
        );

        $requiredMethods = ['diff', 'getVersions', 'rollback', 'encrypt', 'decrypt', 'compress', 'decompress', 'mergeAll'];

        foreach ($requiredMethods as $method) {
            $pattern = '/@method\s+static\s+.*\s+'.preg_quote($method, '/').'\(/';
            $this->assertMatchesRegularExpression(
                $pattern,
                $docComment,
                "StateManager must document method: {$method}"
            );
        }
    }
}
