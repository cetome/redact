<?php

namespace App\Ai;

use RuntimeException;

/**
 * An AI call that cannot go ahead or did not succeed. The message is always
 * friendly and safe to show (it never names the provider or the model).
 */
class AiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 503)
    {
        parent::__construct($message);
    }

    public static function quota(string $message): self
    {
        return new self($message, 429);
    }

    public static function unavailable(): self
    {
        return new self(__('ai.errors.unavailable'), 503);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }
}
