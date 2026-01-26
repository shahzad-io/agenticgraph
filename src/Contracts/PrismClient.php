<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface PrismClient
{
    public function text(): mixed;

    public function embeddings(): mixed;

    public function image(): mixed;

    public function audio(): mixed;

    public function structured(): mixed;
}
