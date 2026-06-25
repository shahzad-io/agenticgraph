<?php

use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;

test('can create and format simple template', function () {
    $template = PromptTemplate::create('Hello {name}!');
    $result = $template->format(['name' => 'World']);

    expect($result)->toBe('Hello World!');
});

test('can format template with multiple variables', function () {
    $template = PromptTemplate::create('Hello {name}, you have {count} messages');
    $result = $template->format([
        'name' => 'John',
        'count' => 5,
    ]);

    expect($result)->toBe('Hello John, you have 5 messages');
});

test('throws exception for missing variables', function () {
    $template = PromptTemplate::create('Hello {name}!');
    $template->format([]);
})->throws(TemplateException::class, 'Missing template variable: name');

test('can use default variables', function () {
    $template = PromptTemplate::create('Hello {name}!')
        ->withDefaults(['name' => 'World']);

    $result = $template->format([]);

    expect($result)->toBe('Hello World!');
});

test('provided variables override defaults', function () {
    $template = PromptTemplate::create('Hello {name}!')
        ->withDefaults(['name' => 'World']);

    $result = $template->format(['name' => 'John']);

    expect($result)->toBe('Hello John!');
});

test('can get all variables from template', function () {
    $template = PromptTemplate::create('Hello {name}, you have {count} messages about {topic}');
    $variables = $template->getVariables();

    expect($variables)->toBe(['name', 'count', 'topic']);
});

test('formats arrays as JSON', function () {
    $template = PromptTemplate::create('Data: {data}');
    $result = $template->format([
        'data' => ['key' => 'value', 'number' => 123],
    ]);

    expect($result)->toContain('"key": "value"');
    expect($result)->toContain('"number": 123');
});

test('formats booleans correctly', function () {
    $template = PromptTemplate::create('Active: {active}, Inactive: {inactive}');
    $result = $template->format([
        'active' => true,
        'inactive' => false,
    ]);

    expect($result)->toBe('Active: true, Inactive: false');
});

test('formats null as empty string', function () {
    $template = PromptTemplate::create('Value: {value}');
    $result = $template->format(['value' => null]);

    expect($result)->toBe('Value: ');
});

test('validates required variables', function () {
    $template = PromptTemplate::create('Hello {name}!')
        ->withRequired(['name']);

    $template->format(['name' => 'World']); // Should not throw

    expect(true)->toBeTrue();
});

test('throws exception for missing required variables', function () {
    $template = PromptTemplate::create('Hello {name}, you are {age} years old')
        ->withRequired(['name', 'age']);

    $template->format(['name' => 'John']);
})->throws(TemplateException::class, 'Missing required template variables: age');

test('can create template from file', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'template');
    file_put_contents($tempFile, 'Hello {name}!');

    $template = PromptTemplate::fromFile($tempFile);
    $result = $template->format(['name' => 'World']);

    expect($result)->toBe('Hello World!');

    unlink($tempFile);
});

test('throws exception for non-existent file', function () {
    PromptTemplate::fromFile('/non/existent/file.txt');
})->throws(TemplateException::class, 'Template file not found');

test('can convert template to string', function () {
    $template = PromptTemplate::create('Hello {name}!');

    expect((string) $template)->toBe('Hello {name}!');
});

test('handles multiline templates', function () {
    $template = PromptTemplate::create(
        <<<'TEMPLATE'
        Hello {name}!
        You have {count} messages.
        TEMPLATE
    );

    $result = $template->format([
        'name' => 'John',
        'count' => 5,
    ]);

    expect($result)->toContain('Hello John!');
    expect($result)->toContain('You have 5 messages.');
});

test('escapes double braces as literal single braces', function () {
    $template = PromptTemplate::create('Return JSON like {{key}} for {name}');
    $result = $template->format(['name' => 'Alice']);

    expect($result)->toBe('Return JSON like {key} for Alice');
});

test('getVariables ignores escaped brace placeholders', function () {
    $template = PromptTemplate::create('{{literal}} and {topic}');

    expect($template->getVariables())->toBe(['topic']);
});
