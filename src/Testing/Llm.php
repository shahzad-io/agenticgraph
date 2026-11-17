<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Testing;

use Generator;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Fakes every LLM call of nodes and PrismRouter for a test:
 *
 *     $llm = Llm::fake(['Refund approved.', ['route' => 'billing'], new \RuntimeException('timeout')]);
 *     // run the graph
 *     $llm->assertPrompted(fn (array $call) => $call['node'] === 'reply' && $call['vars']['tone'] === 'formal');
 *     $llm->assertNotInterpolated('question');
 *
 * Responses are consumed in order, one per provider request (a structured retry or a tool step takes the next one):
 * a string is the reply text (decoded as JSON for a structured request, so invalid JSON triggers the retry), an array
 * is structured data, a Throwable is thrown (a failed attempt), and Prism's TextResponseFake or StructuredResponseFake
 * pass through (tool runs, usage, steps). No responses: every request gets an empty reply.
 *
 * Tools run as in a provider loop: a queued TextResponse with tool calls (and no steps or tool results of its own)
 * for a request with tools runs each handler, sends the results back as the tool result message and takes the next
 * response, until a response has no tool calls or maxSteps is reached. Streamed runs get tool_call and tool_result
 * events per step. A TextResponse with its own steps or tool results passes through as before, handlers not run.
 * runTools: false never runs handlers (the behavior up to 0.2.10).
 */
final class Llm
{
    /**
     * @param  list<string|array<mixed>|\Throwable|TextResponse|StructuredResponse>  $responses
     */
    public static function fake(array $responses = [], bool $runTools = true): LlmFake
    {
        $prism = new class($responses, $runTools) extends PrismFake
        {
            use CallsTools;

            /**
             * @param  array<int, mixed>  $responses
             */
            public function __construct(array $responses, private readonly bool $runTools)
            {
                parent::__construct($responses);
            }

            #[\Override]
            public function text(TextRequest $request): TextResponse
            {
                if (! $this->loops($request)) {
                    return parent::text($request);
                }

                $builder = new ResponseBuilder;
                do {
                    $response = parent::text($request);
                    $calls = $this->callsToRun($response, $request);
                    $results = $this->callTools($request->tools(), $calls);
                    $builder->addStep($this->step($response, $request, $calls, $results));
                } while ($this->continues($request, $builder, $response, $calls, $results));

                return $builder->toResponse();
            }

            /**
             * One stream around every step, as a provider's stream handler: tool_call, then the handler runs and
             * tool_result follows. A consumer that stops reading after a step gets no further request.
             */
            #[\Override]
            public function stream(TextRequest $request): Generator
            {
                if (! $this->loops($request)) {
                    foreach (parent::stream($request) as $event) {
                        yield $event;
                    }

                    return;
                }

                $messageId = EventID::generate();
                yield new StreamStartEvent(id: EventID::generate(), timestamp: time(), model: $request->model(), provider: 'fake');
                $builder = new ResponseBuilder;
                do {
                    $this->recorded[] = $request;
                    $response = $this->nextTextResponse() ?? throw new \LogicException('Llm::fake(): no queued response.');
                    $calls = $this->callsToRun($response, $request);
                    yield new StepStartEvent(id: EventID::generate(), timestamp: time());
                    foreach ($this->streamTextEvents($response->text, $messageId) as $event) {
                        yield $event;
                    }
                    foreach ($calls as $call) {
                        yield new ToolCallEvent(id: EventID::generate(), timestamp: time(), toolCall: $call, messageId: $messageId);
                    }
                    $results = [];
                    foreach ($this->callToolsAndYieldEvents($request->tools(), $calls, $messageId, $results) as $event) {
                        yield $event;
                    }
                    $builder->addStep($step = $this->step($response, $request, $calls, $results));
                    yield new StepFinishEvent(id: EventID::generate(), timestamp: time(), usage: $step->usage, additionalContent: $step->additionalContent, finishReason: $step->finishReason);
                } while ($this->continues($request, $builder, $response, $calls, $results));

                $final = $builder->toResponse();
                yield new StreamEndEvent(id: EventID::generate(), timestamp: time(), finishReason: $final->finishReason, usage: $final->usage);
            }

            /** The next queued response is a tool call this fake runs (peeked, not consumed). */
            private function loops(TextRequest $request): bool
            {
                $next = $this->responses[$this->responseSequence] ?? null;

                return $next instanceof TextResponse && $this->callsToRun($next, $request) !== [];
            }

            /**
             * @return list<ToolCall>
             */
            private function callsToRun(TextResponse $response, TextRequest $request): array
            {
                $own = $response->steps->isNotEmpty() || $response->toolResults !== [];

                return $this->runTools && $request->tools() !== [] && ! $own ? array_values($response->toolCalls) : [];
            }

            /**
             * @param  list<ToolCall>  $calls
             * @param  array<int, ToolResult>  $results
             */
            private function step(TextResponse $response, TextRequest $request, array $calls, array $results): Step
            {
                return new Step(
                    text: $response->text,
                    finishReason: $response->finishReason,
                    toolCalls: $calls,
                    toolResults: $results,
                    providerToolCalls: [],
                    usage: $response->usage,
                    meta: $response->meta,
                    messages: $request->messages(),
                    systemPrompts: $request->systemPrompts(),
                    additionalContent: $response->additionalContent,
                );
            }

            /**
             * After a tool step, below maxSteps: the assistant message and the tool results go back, as Prism's
             * provider handlers send them.
             *
             * @param  list<ToolCall>  $calls
             * @param  array<int, ToolResult>  $results
             */
            private function continues(TextRequest $request, ResponseBuilder $builder, TextResponse $response, array $calls, array $results): bool
            {
                if ($calls === [] || $builder->steps->count() >= $request->maxSteps()) {
                    return false;
                }
                $request->addMessage(new AssistantMessage($response->text, $calls));
                $request->addMessage(new ToolResultMessage($results));
                $request->resetToolChoice();

                return true;
            }

            #[\Override]
            protected function nextTextResponse(): ?TextResponse
            {
                $response = $this->nextQueued();

                return match (true) {
                    $response === null, $response instanceof TextResponse => $response,
                    is_string($response) => TextResponseFake::make()->withText($response),
                    default => throw new \LogicException('Llm::fake(): a text request got a queued '.get_debug_type($response).'; queue a string or a TextResponse.'),
                };
            }

            #[\Override]
            protected function nextStructuredResponse(): ?StructuredResponse
            {
                $response = $this->nextQueued();

                return match (true) {
                    $response === null, $response instanceof StructuredResponse => $response,
                    is_array($response) => StructuredResponseFake::make()->withStructured($response)->withText(json_encode($response, JSON_THROW_ON_ERROR)),
                    is_string($response) => StructuredResponseFake::make()->withStructured(is_array($decoded = json_decode($response, true)) ? $decoded : null)->withText($response),
                    default => throw new \LogicException('Llm::fake(): a structured request got a queued '.get_debug_type($response).'; queue an array, a string or a StructuredResponse.'),
                };
            }

            private function nextQueued(): mixed
            {
                if ($this->responses === []) {
                    return null;
                }
                if (! array_key_exists($this->responseSequence, $this->responses)) {
                    throw new \LogicException('Llm::fake(): no queued response left for request #'.($this->responseSequence + 1).'.');
                }
                $response = $this->responses[$this->responseSequence++];
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            }
        };

        app()->instance(PrismManager::class, new class($prism) extends PrismManager
        {
            public function __construct(private readonly PrismFake $fake) {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): PrismFake
            {
                $this->fake->setProviderConfig($providerConfig);

                return $this->fake;
            }
        });

        $fake = new LlmFake($prism);
        app()->instance(LlmFake::class, $fake);

        return $fake;
    }
}
