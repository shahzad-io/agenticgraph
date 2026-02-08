<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tests\IDESupport;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Shahzad\AgenticGraph\AgenticGraph;
use Shahzad\AgenticGraph\Facades\AgenticGraph as AgenticGraphFacade;

class FacadeAnnotationsTest extends TestCase
{
    public function test_facade_has_method_annotations_for_all_public_methods(): void
    {
        $facadeReflection = new ReflectionClass(AgenticGraphFacade::class);
        $serviceReflection = new ReflectionClass(AgenticGraph::class);

        // Get actual public methods from service class
        $publicMethods = array_filter(
            $serviceReflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn ($method) => ! $method->isConstructor() && ! $method->isStatic()
        );

        // Get @method annotations from facade docblock
        $docComment = $facadeReflection->getDocComment();
        $this->assertNotFalse($docComment, 'Facade must have docblock');

        foreach ($publicMethods as $method) {
            $methodName = $method->getName();
            // More flexible pattern that handles complex return types with spaces
            $pattern = '/@method\s+static\s+.+?\s+'.preg_quote($methodName, '/').'\s*\(/';

            $this->assertMatchesRegularExpression(
                $pattern,
                $docComment,
                "Facade missing @method annotation for: {$methodName}"
            );
        }
    }

    public function test_facade_method_signatures_match_service_class(): void
    {
        $facadeDoc = (new ReflectionClass(AgenticGraphFacade::class))->getDocComment();
        $serviceMethods = (new ReflectionClass(AgenticGraph::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($serviceMethods as $method) {
            if ($method->isConstructor()) {
                continue;
            }

            $methodName = $method->getName();
            $params = $method->getParameters();
            $paramCount = count($params);

            // Extract @method annotation for this method (handle complex return types)
            preg_match(
                '/@method\s+static\s+(.+?)\s+'.preg_quote($methodName, '/').'\s*\((.*?)\)/',
                $facadeDoc,
                $matches
            );

            $this->assertNotEmpty(
                $matches,
                "No @method annotation found for {$methodName}"
            );

            // Count parameters in annotation (rough check)
            $annotatedParams = empty($matches[2]) ? 0 : substr_count($matches[2], '$');

            $this->assertEquals(
                $paramCount,
                $annotatedParams,
                "Parameter count mismatch for {$methodName}: expected {$paramCount}, got {$annotatedParams}"
            );
        }
    }

    public function test_facade_has_see_annotation(): void
    {
        $facadeReflection = new ReflectionClass(AgenticGraphFacade::class);
        $docComment = $facadeReflection->getDocComment();

        $this->assertNotFalse($docComment, 'Facade must have docblock');
        $this->assertMatchesRegularExpression(
            '/@see\s+\\\\?[\w\\\\]+/',
            $docComment,
            'Facade must have @see annotation pointing to service class'
        );
    }
}
