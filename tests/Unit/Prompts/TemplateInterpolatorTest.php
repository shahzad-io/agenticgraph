<?php

use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\TemplateInterpolator;

test('escapes double braces as literal single braces', function () {
    $result = TemplateInterpolator::interpolate('Use {{literal}} in JSON', []);

    expect($result)->toBe('Use {literal} in JSON');
});

test('interpolates real variables alongside escaped braces', function () {
    $result = TemplateInterpolator::interpolate('{{escaped}} and {real_var}', [
        'real_var' => 'value',
    ]);

    expect($result)->toBe('{escaped} and value');
});

test('renders standalone double open and close braces', function () {
    $result = TemplateInterpolator::interpolate('open {{ and close }}', []);

    expect($result)->toBe('open { and close }');
});

test('interpolates variable wrapped in escaped braces', function () {
    $result = TemplateInterpolator::interpolate('{{{name}}}', [
        'name' => 'World',
    ]);

    expect($result)->toBe('{World}');
});

test('extractVariables ignores escaped brace placeholders', function () {
    $variables = TemplateInterpolator::extractVariables('{{literal}} and {real_var}');

    expect($variables)->toBe(['real_var']);
});

test('throws for missing variables', function () {
    TemplateInterpolator::interpolate('Hello {name}', []);
})->throws(TemplateException::class, 'Missing template variable: name');
