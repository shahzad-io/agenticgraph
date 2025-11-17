<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;

it('resolves prism with fake response', function () {
    $fake = TextResponseFake::make()->withText('hello')->withUsage(new Usage(1, 2))->withMeta(new Meta('id', 'model'));
    Prism::fake([$fake]);
    $response = Prism::text()->using('openai', 'test-model')->withPrompt('hi')->asText();
    expect($response->text)->toBe('hello');
});