<?php

namespace App\Vulnerabilities\Sources;

use RuntimeException;

/** A public database did not answer usefully (network, HTTP error, rate limit, bad JSON). */
class SourceException extends RuntimeException
{
    public function __construct(public readonly string $source, string $message, public readonly bool $rateLimited = false)
    {
        parent::__construct($message);
    }
}
