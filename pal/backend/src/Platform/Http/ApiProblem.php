<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An expected, client-facing API error. Its code and message are safe to return
 * to the caller; anything else is reported as a generic internal error.
 */
final class ApiProblem extends HttpException
{
    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($statusCode, $message);
    }

    /**
     * @param array<string, string> $fieldErrors
     */
    public static function validation(array $fieldErrors): self
    {
        return new self(422, 'validation_failed', 'One or more fields are invalid.', $fieldErrors);
    }
}
