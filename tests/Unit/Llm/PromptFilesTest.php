<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Llm\LlmRequest;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\PromptFile;
use Shahzad\AgenticGraph\Prompts\Prompts;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class PromptFileProbeNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function call(Closure $build): mixed
    {
        return $build($this->llm());
    }
}

const PROMPT_FILE_REPLY = <<<'MD'
---
name: support.reply
version: 2
description: "Support reply: short"
---

## system

You help with {product}.
Reply as {{"answer": "..."}}.

### Rules
Be brief.

## messages: history

## user

{question}
MD;

function promptDir(): string
{
    return sys_get_temp_dir().'/ag2-prompt-files-'.getmypid();
}

function writePrompt(string $relative, string $content): void
{
    $path = promptDir().'/'.$relative;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}

beforeEach(function () {
    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);
    config()->set('agenticgraph.prism.cache_enabled', false);
    config()->set('agenticgraph.prompts.path', promptDir());
    writePrompt('support/reply.md', PROMPT_FILE_REPLY);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory(promptDir());
});

it('loads a dotted name from its path as a ChatPromptTemplate with name, version and a 12-hex content hash', function () {
    $prompt = Prompts::get('support.reply');

    expect($prompt)->toBeInstanceOf(ChatPromptTemplate::class)
        ->and($prompt->meta())->toBe(['name' => 'support.reply', 'version' => '2', 'hash' => substr(hash('sha256', PROMPT_FILE_REPLY), 0, 12)])
        ->and($prompt->description)->toBe('Support reply: short')
        ->and($prompt->format(['product' => 'Acme', 'question' => 'Hi {x}', 'history' => [['role' => 'assistant', 'content' => 'Earlier {y}']]]))->toBe([
            ['role' => 'system', 'content' => "You help with Acme.\nReply as {\"answer\": \"...\"}.\n\n### Rules\nBe brief."],
            ['role' => 'assistant', 'content' => 'Earlier {y}'],
            ['role' => 'user', 'content' => 'Hi {x}'],
        ]);
});

it('caches by content hash, returns a fresh copy each time, and picks up an edit', function () {
    $first = Prompts::get('support.reply');
    $first->user('appended');

    expect(Prompts::get('support.reply')->getMessages())->toHaveCount(3)
        ->and(Prompts::get('support.reply'))->not->toBe(Prompts::get('support.reply'));

    writePrompt('support/reply.md', str_replace('version: 2', 'version: 3', PROMPT_FILE_REPLY));

    expect(Prompts::get('support.reply')->version)->toBe('3');
});

it('rejects a bad file with an error that names the problem', function (string $content, string $message) {
    writePrompt('bad.md', $content);

    expect(fn () => Prompts::get('bad'))->toThrow(TemplateException::class, $message);
})->with([
    'no front matter' => ["## user\nhi", 'front matter missing'],
    'missing name' => ["---\nversion: 1\n---\n## user\nhi", 'front matter name is missing (expected [bad])'],
    'mismatched name' => ["---\nname: other\nversion: 1\n---\n## user\nhi", 'front matter name [other] does not match the path [bad]'],
    'missing version' => ["---\nname: bad\n---\n## user\nhi", 'front matter version is missing'],
    'model key' => ["---\nname: bad\nversion: 1\nmodel: openai:gpt-6-luna\n---\n## user\nhi", 'front matter key [model] is not allowed. Model and settings stay on the node'],
    'settings key' => ["---\nname: bad\nversion: 1\nsettings: x\n---\n## user\nhi", 'front matter key [settings] is not allowed'],
    'unknown key' => ["---\nname: bad\nversion: 1\nowner: me\n---\n## user\nhi", 'unknown front matter key [owner]'],
    'nested yaml' => ["---\nname: bad\nversion: 1\n  - x\n---\n## user\nhi", 'is not a flat key: value'],
    'unknown heading' => ["---\nname: bad\nversion: 1\n---\n## tool\nhi", 'unknown heading [## tool]'],
    'text before a heading' => ["---\nname: bad\nversion: 1\n---\nhello\n## user\nhi", 'text before the first role heading'],
    'empty section' => ["---\nname: bad\nversion: 1\n---\n## system\n\n## user\nhi", '## system is empty'],
    'text under messages' => ["---\nname: bad\nversion: 1\n---\n## messages: history\nhi", '## messages: history takes no text'],
    'no sections' => ["---\nname: bad\nversion: 1\n---\n", 'no role sections'],
]);

it('rejects a missing file and a name that is not dotted segments', function () {
    expect(fn () => Prompts::get('nope'))->toThrow(TemplateException::class, 'Prompt file not found: [nope]')
        ->and(fn () => Prompts::get('../etc/passwd'))->toThrow(TemplateException::class, 'Invalid prompt name');
});

it('sends the same messages through chat() as the equivalent hand-built template', function () {
    $vars = ['product' => 'Acme', 'question' => 'Refund?', 'history' => []];
    $requests = [];
    foreach ([Prompts::get('support.reply'), (new ChatPromptTemplate)->system("You help with {product}.\nReply as {{\"answer\": \"...\"}}.\n\n### Rules\nBe brief.")->messagesPlaceholder('history')->user('{question}')] as $template) {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))]);
        (new PromptFileProbeNode)->call(fn (LlmRequest $llm) => $llm->chat($template)->vars($vars)->text());
        $fake->assertRequest(function (array $r) use (&$requests): void {
            $requests[] = [$r[0]->systemPrompts(), $r[0]->messages()];
        });
    }

    expect($requests[0])->toEqual($requests[1]);
});

it('carries the prompt file name, version and hash on TokensUsed, also on a cache hit, and nothing for other templates', function () {
    Event::fake([TokensUsed::class]);
    config()->set('agenticgraph.prism.cache_enabled', true);
    Prism::fake([TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1)), TextResponseFake::make()->withText('ok')->withUsage(new Usage(5, 1))]);
    $vars = ['product' => 'Acme', 'question' => 'Refund?', 'history' => []];
    $meta = Prompts::get('support.reply')->meta();

    (new PromptFileProbeNode)->call(fn (LlmRequest $llm) => $llm->chat(Prompts::get('support.reply'))->vars($vars)->text());
    (new PromptFileProbeNode)->call(fn (LlmRequest $llm) => $llm->chat(Prompts::get('support.reply'))->vars($vars)->text());
    (new PromptFileProbeNode)->call(fn (LlmRequest $llm) => $llm->chat(ChatPromptTemplate::create()->user('plain'))->text());

    $events = Event::dispatched(TokensUsed::class);
    expect($events[0][0]->promptFile)->toBe($meta)
        ->and($events[1][0]->cached)->toBeTrue()
        ->and($events[1][0]->promptFile)->toBe($meta)
        ->and($events[2][0]->promptFile)->toBeNull();
});

it('shows the prompt name, version and hash in explain()', function () {
    $explain = (new PromptFileProbeNode)->call(fn (LlmRequest $llm) => $llm->chat(Prompts::get('support.reply'))->vars(['product' => 'A', 'question' => 'q', 'history' => []])->explain());

    expect($explain['prompt'])->toBe(Prompts::get('support.reply')->meta())
        ->and((new PromptFileProbeNode)->call(fn (LlmRequest $llm) => $llm->user('x')->explain())['prompt'])->toBeNull();
});

it('lists every prompt file with name, version and hash, and fails when one is invalid', function () {
    writePrompt('greeting.md', "---\nname: greeting\nversion: 1.0\n---\n## user\nHello");

    expect(Artisan::call('agenticgraph:prompts'))->toBe(0)
        ->and(Artisan::output())->toContain('support.reply')->toContain('greeting')->toContain(Prompts::get('greeting')->hash);

    writePrompt('broken/one.md', "---\nname: broken.two\nversion: 1\n---\n## user\nx");

    expect(Artisan::call('agenticgraph:prompts'))->toBe(1)
        ->and(Artisan::output())->toContain('does not match the path [broken.one]');
});

it('ships a valid publishable stub', function () {
    config()->set('agenticgraph.prompts.path', dirname(__DIR__, 3).'/resources/prompts');

    expect(Prompts::get('example'))->toBeInstanceOf(PromptFile::class)
        ->and(Prompts::get('example')->getVariables())->toBe(['product', 'history', 'question']);
});
