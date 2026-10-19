<?php

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

/**
 * Prism 0.102 sends json_schema structured output to every OpenAI model it does not know, which includes custom names
 * on an OpenAI-compatible gateway. Prism has a per-request switch (StructuredMode::Json); LlmRequest has no setter for
 * it, so a node reaches it through the raw client.
 */
class GatewayStructuredNode extends PrismNode
{
    protected ?string $model = 'openai:gateway-custom-model';

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }

    public function viaLlm(): array
    {
        return $this->llm()->prompt('hi')->schema(gatewaySchema())->structured();
    }

    public function viaRawJsonMode(): array
    {
        return $this->prismClient()->structured()->using('openai', 'gateway-custom-model')
            ->usingStructuredMode(StructuredMode::Json)->withSchema(gatewaySchema())->withPrompt('hi')->asStructured()->structured;
    }
}

function gatewaySchema(): ObjectSchema
{
    return new ObjectSchema('out', 'out', [new StringSchema('label', 'label')], ['label']);
}

function gatewayReply(): array
{
    return [
        'id' => 'resp_1', 'model' => 'gateway-custom-model', 'status' => 'completed',
        'output' => [['type' => 'message', 'id' => 'm', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => '{"label":"ok"}', 'annotations' => []]]]],
        'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
    ];
}

beforeEach(function () {
    config()->set('prism.providers.openai.api_key', 'k');
    config()->set('agenticgraph.prism.cache_enabled', false);
    Http::fake(['api.openai.com/*' => Http::response(gatewayReply())]);
});

it('sends json_schema for an unknown OpenAI model name through llm()', function () {
    expect((new GatewayStructuredNode)->viaLlm())->toBe(['label' => 'ok']);

    Http::assertSent(fn ($request) => ($request->data()['text']['format']['type'] ?? null) === 'json_schema');
});

it('sends json_object when the node asks Prism for StructuredMode::Json through the raw client', function () {
    expect((new GatewayStructuredNode)->viaRawJsonMode())->toBe(['label' => 'ok']);

    Http::assertSent(fn ($request) => ($request->data()['text']['format']['type'] ?? null) === 'json_object');
});
