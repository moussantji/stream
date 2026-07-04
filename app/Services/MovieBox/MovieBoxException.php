<?php

namespace App\Services\MovieBox;

use RuntimeException;

/**
 * Thrown when the upstream MovieBox (aoneroom) backend returns an error,
 * an empty body, or an unexpected payload. Rendered as HTTP 502 by the
 * application's exception handler.
 */
class MovieBoxException extends RuntimeException
{
    public static function upstream(string $message, int $code = 0): self
    {
        return new self($message, $code);
    }
}
