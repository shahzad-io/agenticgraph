<?php

use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\Messages\AssistantMessage;
use Shahzad\AgenticGraph\Prompts\Messages\SystemMessage;
use Shahzad\AgenticGraph\Prompts\Messages\UserMessage;

test('can create empty chat template', function () {
    $template = ChatPromptTemplate::create();

    expect($template->getMessages())->toBeEmpty();
});

test('can add system message', function () {
    $template = ChatPromptTemplate::create()
        ->system('You are a helpful assistant');

    $messages = $template->getMessages();

    expect($messages)->toHaveCount(1);
    expect($messages[0])->toBeInstanceOf(SystemMessage::class);
    expect($messages[0]->getContent())->toBe('You are a helpful assistant');
});

test('can add user message', function () {
    $template = ChatPromptTemplate::create()
        ->user('Hello!');

    $messages = $template->getMessages();

    expect($messages)->toHaveCount(1);
    expect($messages[0])->toBeInstanceOf(UserMessage::class);
    expect($messages[0]->getContent())->toBe('Hello!');
});

test('can add assistant message', function () {
    $template = ChatPromptTemplate::create()
        ->assistant('Hi there!');

    $messages = $template->getMessages();

    expect($messages)->toHaveCount(1);
    expect($messages[0])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[0]->getContent())->toBe('Hi there!');
});

test('can chain multiple messages', function () {
    $template = ChatPromptTemplate::create()
        ->system('You are helpful')
        ->user('Hello')
        ->assistant('Hi!')
        ->user('How are you?');

    expect($template->getMessages())->toHaveCount(4);
});

test('can format messages with variables', function () {
    $template = ChatPromptTemplate::create()
        ->system('You are a {role}')
        ->user('My name is {name}');

    $formatted = $template->format([
        'role' => 'helpful assistant',
        'name' => 'John',
    ]);

    expect($formatted)->toHaveCount(2);
    expect($formatted[0]['role'])->toBe('system');
    expect($formatted[0]['content'])->toBe('You are a helpful assistant');
    expect($formatted[1]['role'])->toBe('user');
    expect($formatted[1]['content'])->toBe('My name is John');
});

test('throws exception for missing variables', function () {
    $template = ChatPromptTemplate::create()
        ->user('Hello {name}');

    $template->format([]);
})->throws(TemplateException::class, 'Missing template variable: name');

test('can format as string', function () {
    $template = ChatPromptTemplate::create()
        ->system('You are helpful')
        ->user('Hello');

    $result = $template->formatAsString();

    expect($result)->toContain('SYSTEM: You are helpful');
    expect($result)->toContain('USER: Hello');
});

test('can get all variables from all messages', function () {
    $template = ChatPromptTemplate::create()
        ->system('You are a {role}')
        ->user('My name is {name}')
        ->user('I have {count} questions');

    $variables = $template->getVariables();

    expect($variables)->toBe(['role', 'name', 'count']);
});

test('can create from array', function () {
    $template = ChatPromptTemplate::fromArray([
        ['role' => 'system', 'content' => 'You are helpful'],
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi!'],
    ]);

    $messages = $template->getMessages();

    expect($messages)->toHaveCount(3);
    expect($messages[0])->toBeInstanceOf(SystemMessage::class);
    expect($messages[1])->toBeInstanceOf(UserMessage::class);
    expect($messages[2])->toBeInstanceOf(AssistantMessage::class);
});

test('throws exception for unknown role in fromArray', function () {
    ChatPromptTemplate::fromArray([
        ['role' => 'unknown', 'content' => 'test'],
    ]);
})->throws(TemplateException::class, 'Unknown message role: unknown');

test('can convert to string', function () {
    $template = ChatPromptTemplate::create()
        ->system('You are helpful')
        ->user('Hello');

    $string = (string) $template;

    expect($string)->toContain('SYSTEM: You are helpful');
    expect($string)->toContain('USER: Hello');
});

test('formats arrays in messages', function () {
    $template = ChatPromptTemplate::create()
        ->user('Data: {data}');

    $formatted = $template->format([
        'data' => ['key' => 'value'],
    ]);

    expect($formatted[0]['content'])->toContain('"key": "value"');
});

test('can add custom message', function () {
    $customMessage = new SystemMessage('Custom system message');

    $template = ChatPromptTemplate::create()
        ->addMessage($customMessage);

    expect($template->getMessages())->toHaveCount(1);
    expect($template->getMessages()[0])->toBe($customMessage);
});

test('few-shot example pattern', function () {
    $template = ChatPromptTemplate::create()
        ->system('Classify sentiment as positive, negative, or neutral')
        ->user('I love this!')
        ->assistant('positive')
        ->user('This is terrible')
        ->assistant('negative')
        ->user('It arrived on time')
        ->assistant('neutral')
        ->user('Classify: {text}');

    $formatted = $template->format(['text' => 'This is amazing!']);

    expect($formatted)->toHaveCount(8); // 1 system + 3 pairs + 1 user = 8
    expect($formatted[7]['content'])->toBe('Classify: This is amazing!');
});

test('escapes double braces in chat messages', function () {
    $template = ChatPromptTemplate::create()
        ->user('Schema: {{field}} for {name}');

    $formatted = $template->format(['name' => 'Ada']);

    expect($formatted[0]['content'])->toBe('Schema: {field} for Ada');
});
