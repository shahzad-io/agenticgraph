<?php

use Illuminate\Support\Facades\Facade;

/**
 * `@method` is only for magic methods. On a facade it must name a public method of the facade
 * root. On any other class it must not name a declared method at all: a protected one would be
 * advertised as public API, and a public one duplicates the real signature and can drift.
 *
 * @return list<string> "file:line Class::method -> visibility"
 */
function invalidMethodTags(): array
{
    $src = dirname(__DIR__, 3).'/src';
    $problems = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());
        if (! preg_match_all('/@method\s+(?:static\s+)?.*?(\w+)\(/', $code, $tags, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        if (! preg_match('/namespace\s+([^;]+);/', $code, $namespace) || ! preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $code, $class)) {
            continue;
        }

        $className = $namespace[1].'\\'.$class[1];
        $target = is_subclass_of($className, Facade::class) ? $className::getFacadeRoot()::class : $className;

        foreach ($tags[1] as [$method, $offset]) {
            $visibility = method_exists($target, $method)
                ? implode(' ', Reflection::getModifierNames((new ReflectionMethod($target, $method))->getModifiers()))
                : 'missing';

            if ($target === $className) {
                $visibility = $visibility === 'missing' ? 'ok' : "declared {$visibility}";
            }

            if ($visibility !== 'ok' && ! ($target !== $className && str_contains($visibility, 'public'))) {
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $problems[] = str_replace($src.'/', '', $file->getPathname()).":{$line} {$target}::{$method} -> {$visibility}";
            }
        }
    }

    return $problems;
}

it('uses @method only for magic methods', function () {
    expect(invalidMethodTags())->toBe([]);
});
