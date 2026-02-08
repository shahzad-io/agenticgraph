<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingsRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;

interface PrismClient
{
    public function text(): PendingTextRequest;

    public function embeddings(): PendingEmbeddingsRequest;

    public function image(): PendingImageRequest;

    public function audio(): PendingAudioRequest;

    public function structured(): PendingStructuredRequest;
}
