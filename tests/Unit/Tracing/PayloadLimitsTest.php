<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\TracePayloadBuilder;

/** A payload whose encoded size is exactly $bytes. */
function payloadOfSize(int $bytes): array
{
    $make = fn (int $n): array => ['trace' => ['id' => 't'], 'llm_calls' => [['id' => 'c', 'response' => str_repeat('a', $n)]]];

    return $make($bytes - strlen(TracePayloadBuilder::encode($make(0))));
}

function prepare(array $payload): array
{
    return (new ReflectionMethod(TracePayloadBuilder::class, 'preparePayload'))->invoke(new TracePayloadBuilder, $payload);
}

it('sends an envelope of exactly 1 MiB as it is', function () {
    $payload = payloadOfSize(1_048_576);

    expect(strlen(TracePayloadBuilder::encode($payload)))->toBe(1_048_576)
        ->and(prepare($payload))->toBe($payload);
});

it('shrinks an envelope one byte above 1 MiB', function () {
    $out = prepare(payloadOfSize(1_048_577));

    expect($out['_meta']['truncated'])->toBeTrue()
        ->and($out['_meta']['truncation_applied'])->toBe(['responses']);
});

it('shrinks, not drops, an envelope of exactly 10 MiB', function () {
    $out = prepare(payloadOfSize(10_485_760));

    expect($out['_meta']['truncation_applied'])->not->toContain('complete_payload');
});

it('drops everything but the trace record one byte above 10 MiB', function () {
    $out = prepare(payloadOfSize(10_485_761));

    expect($out['_meta']['truncation_applied'])->toBe(['complete_payload']);
});
