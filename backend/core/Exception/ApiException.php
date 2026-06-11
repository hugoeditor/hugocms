<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Exception;

use RuntimeException;

/**
 * Fehler mit maschinenlesbarem Code und passendem HTTP-Status.
 * Wird vom Connector abgefangen und als JSON-Fehler ausgeliefert.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'EUNKNOWN',
        private readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public static function notFound(string $message = 'Nicht gefunden.'): self
    {
        return new self($message, 'ENOENT', 404);
    }

    public static function denied(string $message = 'Zugriff verweigert.'): self
    {
        return new self($message, 'EACCES', 403);
    }

    public static function unauthorized(string $message = 'Anmeldung erforderlich.'): self
    {
        return new self($message, 'EAUTH', 401);
    }

    public static function badRequest(string $message = 'Ungültige Anfrage.'): self
    {
        return new self($message, 'EINVAL', 400);
    }
}
