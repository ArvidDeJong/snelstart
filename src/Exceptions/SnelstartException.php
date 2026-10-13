<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Exceptions;

use Darvis\Snelstart\ErrorCodes;

/**
 * The one exception the client throws. The code is the HTTP status of the response that caused
 * it, or 0 when there was no response: an incomplete config, a timeout, a connection error.
 *
 * The response body is not kept as a property: it can hold data of the administration, and a
 * property ends up in error trackers that serialise exceptions. The message has it, keys redacted.
 * The SnelStart error codes in the response (REL-0007) are kept: they say what went wrong and
 * hold no data.
 */
class SnelstartException extends \RuntimeException
{
    /**
     * @param  list<string>  $errorCodes  the SnelStart error codes found in the response
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, private readonly array $errorCodes = [])
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The HTTP status of the response that caused this, or 0 when there was no response.
     */
    public function status(): int
    {
        return (int) $this->getCode();
    }

    /**
     * The SnelStart error codes in the response, such as ['REL-0007'], or [] when it had none.
     *
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return $this->errorCodes;
    }

    /**
     * Whether the response had one of these SnelStart error codes.
     */
    public function hasErrorCode(string ...$codes): bool
    {
        return array_intersect($codes, $this->errorCodes) !== [];
    }

    /**
     * The error codes with what SnelStart documents for each, in Dutch: ['REL-0007' => 'Naam moet
     * gevuld zijn']. A code the package does not know has null.
     *
     * @return array<string, string|null>
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->errorCodes as $code) {
            $errors[$code] = ErrorCodes::message($code);
        }

        return $errors;
    }
}
