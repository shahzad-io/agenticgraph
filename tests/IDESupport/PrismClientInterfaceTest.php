<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tests\IDESupport;

use PHPUnit\Framework\TestCase;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingsRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use ReflectionClass;
use Shahzad\AgenticGraph\Contracts\PrismClient;

class PrismClientInterfaceTest extends TestCase
{
    public function test_prism_client_interface_has_proper_return_types(): void
    {
        $reflection = new ReflectionClass(PrismClient::class);

        $expectedReturnTypes = [
            'text' => PendingTextRequest::class,
            'structured' => PendingStructuredRequest::class,
            'embeddings' => PendingEmbeddingsRequest::class,
            'image' => PendingImageRequest::class,
            'audio' => PendingAudioRequest::class,
        ];

        foreach ($expectedReturnTypes as $method => $expectedType) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Method {$method} not found in PrismClient interface"
            );

            $methodReflection = $reflection->getMethod($method);
            $returnType = $methodReflection->getReturnType();

            $this->assertNotNull(
                $returnType,
                "Method {$method} must have a return type (found 'mixed')"
            );

            $this->assertEquals(
                $expectedType,
                $returnType->getName(),
                "Method {$method} return type mismatch"
            );
        }
    }

    public function test_prism_client_interface_has_no_mixed_types(): void
    {
        $reflection = new ReflectionClass(PrismClient::class);

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();

            // Check it's not 'mixed'
            $this->assertNotEquals(
                'mixed',
                $returnType?->getName(),
                "Method {$method->getName()} should not return 'mixed'"
            );
        }
    }

    public function test_prism_client_has_all_required_methods(): void
    {
        $reflection = new ReflectionClass(PrismClient::class);

        $requiredMethods = ['text', 'structured', 'embeddings', 'image', 'audio'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "PrismClient must have method: {$method}"
            );
        }
    }
}
