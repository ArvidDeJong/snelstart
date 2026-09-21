<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Exceptions;

/**
 * What both clients throw. It is a RuntimeException with the message it always had, so an existing
 * catch or string match keeps working; the code is the HTTP status of the response that caused it,
 * or 0 when there was no response (incomplete config, a cURL error).
 *
 * Plain PHP on purpose: the standalone client throws it too, and that one runs without Laravel.
 * The response body is not kept as a property: it can hold data of the administration, and the
 * message already has the part that was always there, with the keys redacted.
 */
class SnelstartException extends \RuntimeException
{
    /**
     * The HTTP status of the response that caused this, or 0 when there was no response.
     */
    public function status(): int
    {
        return (int) $this->getCode();
    }
}
