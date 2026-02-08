<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Adapters;

use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingsRequest;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Shahzad\AgenticGraph\Contracts\PrismClient;

final class PrismFacadeClient implements PrismClient
{
    public function text(): PendingTextRequest
    {
        return Prism::text();
    }

    public function embeddings(): PendingEmbeddingsRequest
    {
        return Prism::embeddings();
    }

    public function image(): PendingImageRequest
    {
        return Prism::image();
    }

    public function audio(): PendingAudioRequest
    {
        return Prism::audio();
    }

    public function structured(): PendingStructuredRequest
    {
        return Prism::structured();
    }
}
