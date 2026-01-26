<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Adapters;

use Prism\Prism\Facades\Prism;
use Shahzad\AgenticGraph\Contracts\PrismClient;

final class PrismFacadeClient implements PrismClient
{
    public function text(): mixed
    {
        return Prism::text();
    }

    public function embeddings(): mixed
    {
        return Prism::embeddings();
    }

    public function image(): mixed
    {
        return Prism::image();
    }

    public function audio(): mixed
    {
        return Prism::audio();
    }

    public function structured(): mixed
    {
        return Prism::structured();
    }
}
