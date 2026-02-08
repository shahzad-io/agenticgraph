<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<InClassNode>
 */
class FacadeMethodAnnotationRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        // Only check Facade classes
        if (! $classReflection->isSubclassOf('Illuminate\Support\Facades\Facade')) {
            return [];
        }

        $docComment = $classReflection->getNativeReflection()->getDocComment();

        if ($docComment === false) {
            return [
                RuleErrorBuilder::message(
                    'Facade must have @method annotations for IDE support'
                )->build(),
            ];
        }

        // Check for @see annotation pointing to service class
        if (! preg_match('/@see\s+\\\\?[\w\\\\]+/', $docComment)) {
            return [
                RuleErrorBuilder::message(
                    'Facade must have @see annotation pointing to service class'
                )->build(),
            ];
        }

        // Check for @method annotations
        if (! preg_match('/@method/', $docComment)) {
            return [
                RuleErrorBuilder::message(
                    'Facade should have @method annotations for IDE autocomplete'
                )->build(),
            ];
        }

        return [];
    }
}
