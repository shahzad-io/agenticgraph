<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

/**
 * The result of `StreamToken::verify()`: `valid` (with the execution id the token was issued for), `invalid` or
 * `expired`.
 */
final class TokenResult
{
    private function __construct(
        public readonly string $state,
        public readonly ?string $executionId = null,
    ) {}

    public static function valid(?string $executionId): self
    {
        return new self('valid', $executionId);
    }

    public static function invalid(): self
    {
        return new self('invalid');
    }

    public static function expired(): self
    {
        return new self('expired');
    }

    public function isValid(): bool
    {
        return $this->state === 'valid';
    }
}
