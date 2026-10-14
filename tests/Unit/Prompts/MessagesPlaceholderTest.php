<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

/** Visitor text that must reach the model byte for byte. */
const PLACEHOLDER_BRACES = 'Hi {name}, literal {{x}}, stray { and } here';

class MessagesPlaceholderChatNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $template = ChatPromptTemplate::create()
            ->system('You are a support assistant for {brand}.')
            ->messagesPlaceholder('history')
            ->user('Answer the last message for {brand}.');

        return $state->set('reply', $this->withoutCache()->chatPrompt($template, [
            'brand' => 'Acme',
            'history' => $state->get('history'),
        ]));
    }
}

function placeholderTemplate(): ChatPromptTemplate
{
    return ChatPromptTemplate::create()
        ->system('You help {brand}.')
        ->messagesPlaceholder('history')
        ->user('Reply for {brand}.');
}

function placeholderHistory(): array
{
    return [
        ['role' => 'user', 'content' => PLACEHOLDER_BRACES],
        ['role' => 'assistant', 'content' => 'Sure {name}'],
    ];
}

it('inserts array messages in order, uninterpolated, between system and a later user message', function () {
    expect(placeholderTemplate()->format(['brand' => 'Acme', 'history' => placeholderHistory()]))->toBe([
        ['role' => 'system', 'content' => 'You help Acme.'],
        ['role' => 'user', 'content' => PLACEHOLDER_BRACES],
        ['role' => 'assistant', 'content' => 'Sure {name}'],
        ['role' => 'user', 'content' => 'Reply for Acme.'],
    ]);
});

it('inserts Prism message objects and keeps the original object', function () {
    $user = new UserMessage(PLACEHOLDER_BRACES);
    $assistant = new AssistantMessage('Sure {name}');
    $system = new SystemMessage('Context {x}');

    $formatted = placeholderTemplate()->format(['brand' => 'Acme', 'history' => [$user, $assistant, $system]]);

    expect(array_map(fn ($m) => [$m['role'], $m['content']], $formatted))->toBe([
        ['system', 'You help Acme.'],
        ['user', PLACEHOLDER_BRACES],
        ['assistant', 'Sure {name}'],
        ['system', 'Context {x}'],
        ['user', 'Reply for Acme.'],
    ])
        ->and($formatted[1]['message'])->toBe($user)
        ->and($formatted[2]['message'])->toBe($assistant)
        ->and($formatted[3]['message'])->toBe($system);
});

it('accepts a mixed list of arrays and Prism objects', function () {
    $formatted = placeholderTemplate()->format(['brand' => 'Acme', 'history' => [
        ['role' => 'user', 'content' => 'first {a}'],
        new AssistantMessage('second {b}'),
        ['role' => 'user', 'content' => 'third }{'],
    ]]);

    expect(array_column($formatted, 'content'))->toBe(['You help Acme.', 'first {a}', 'second {b}', 'third }{', 'Reply for Acme.']);
});

it('inserts nothing for an empty list', function () {
    expect(placeholderTemplate()->format(['brand' => 'Acme', 'history' => []]))->toBe([
        ['role' => 'system', 'content' => 'You help Acme.'],
        ['role' => 'user', 'content' => 'Reply for Acme.'],
    ]);
});

it('rejects invalid placeholder input with a message naming the variable', function (mixed $history, string $message) {
    expect(fn () => placeholderTemplate()->format(['brand' => 'Acme', 'history' => $history]))
        ->toThrow(TemplateException::class, $message);
})->with([
    'unknown role' => [[['role' => 'tool', 'content' => 'x']], "Messages placeholder 'history' item 0 has unknown role 'tool'"],
    'missing role' => [[['content' => 'x']], "Messages placeholder 'history' item 0 has unknown role 'null'"],
    'non-string content' => [[['role' => 'user', 'content' => ['x']]], "Messages placeholder 'history' item 0 content must be a string"],
    'not a list (string)' => ['hello', "Messages placeholder 'history' must be a list of messages"],
    'not a list (assoc)' => [['a' => ['role' => 'user', 'content' => 'x']], "Messages placeholder 'history' must be a list of messages"],
    'item not a message' => [['hello'], "Messages placeholder 'history' item 0 must be a message array or a Prism message, got string"],
]);

it('throws when the placeholder variable is missing', function () {
    expect(fn () => placeholderTemplate()->format(['brand' => 'Acme']))
        ->toThrow(TemplateException::class, 'Missing messages placeholder variable: history');
});

it('lists the placeholder variable and keeps braces in formatAsString', function () {
    expect(placeholderTemplate()->getVariables())->toBe(['brand', 'history'])
        ->and(placeholderTemplate()->formatAsString(['brand' => 'Acme', 'history' => placeholderHistory()]))
        ->toBe("SYSTEM: You help Acme.\n\nUSER: ".PLACEHOLDER_BRACES."\n\nASSISTANT: Sure {name}\n\nUSER: Reply for Acme.");
});

it('sends the exact history text through chatPrompt', function (bool $streamed) {
    $fake = Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))]);

    app()->forgetInstance(StreamContext::class);
    if ($streamed) {
        app()->instance(StreamContext::class, new StreamContext(
            new StreamSession('stream-ph', 'exec-ph', new StreamOptions, time()),
            new MemorySink
        ));
    }

    $state = (new MessagesPlaceholderChatNode)->execute(new State(['history' => placeholderHistory()]));
    app()->forgetInstance(StreamContext::class);

    expect($state->get('reply'))->toBe('ok');

    $fake->assertRequest(function (array $recorded): void {
        $messages = $recorded[0]->messages();

        expect(array_map(fn ($m) => $m::class, $messages))->toBe([
            SystemMessage::class, UserMessage::class, AssistantMessage::class, UserMessage::class,
        ])
            ->and($messages[0]->content)->toBe('You are a support assistant for Acme.')
            ->and($messages[1]->content)->toBe(PLACEHOLDER_BRACES)
            ->and($messages[2]->content)->toBe('Sure {name}')
            ->and($messages[3]->content)->toBe('Answer the last message for Acme.');
    });
})->with(['not streamed' => [false], 'streamed' => [true]]);
