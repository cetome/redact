<?php

namespace App\Sbom;

use RuntimeException;

/** A file that cannot be imported; the message is translated and safe to show. */
class SbomException extends RuntimeException
{
    /** @param  array<string, mixed>  $replace */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('sbom.errors.'.$key, $replace));
    }
}
