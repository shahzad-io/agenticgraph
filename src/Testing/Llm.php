<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Testing;

use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Response as TextResponse;

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
 */
final class Llm
{
    /**
     * @param  list<string|array<mixed>|\Throwable|TextResponse|StructuredResponse>  $responses
     */
    public static function fake(array $responses = []): LlmFake
    {
        $prism = new class($responses) extends PrismFake
        {
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
