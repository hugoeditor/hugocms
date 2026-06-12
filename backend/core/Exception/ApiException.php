<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Exception;

use RuntimeException;

/**
 * Fehler mit maschinenlesbarem Code und passendem HTTP-Status.
 * Wird vom Connector abgefangen und als JSON-Fehler ausgeliefert.
 *
 * Für die Mehrsprachigkeit trägt der Fehler KEINEN deutschen Text mehr,
 * sondern:
 *   - errorCode:   maschinenlesbare Fehlerklasse (z. B. ECONFIG, EAUTH).
 *   - messageKey:  optionaler Übersetzungsschlüssel für die genaue Meldung
 *                  (Form GROSS-MIT-BINDESTRICH). Entfällt, wenn der Code die
 *                  Meldung bereits eindeutig bestimmt — dann übersetzt der
 *                  Client allein über den Code.
 *   - params:      dynamische Bestandteile (Hostname, Pfad, …), die der
 *                  Client in die übersetzte Meldung einsetzt.
 *
 * Die geerbte getMessage() liefert eine knappe, NICHT übersetzte Kurzform
 * (Schlüssel + Parameter) ausschließlich fürs Server-Log.
 */
class ApiException extends RuntimeException
{
    /** @var list<mixed> */
    private readonly array $params;

    /**
     * @param array<int, mixed> $params
     */
    public function __construct(
        private readonly string $errorCode = 'EUNKNOWN',
        private readonly int $httpStatus = 400,
        private readonly ?string $messageKey = null,
        array $params = [],
    ) {
        $this->params = array_values($params);

        // Lesbare Kurzform fürs Server-Log (Diagnose, keine i18n).
        $label = $messageKey ?? $errorCode;
        if ($this->params !== []) {
            $label .= ' [' . implode(', ', array_map(
                static fn (mixed $p): string => is_scalar($p)
                    ? (string) $p
                    : (string) json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->params,
            )) . ']';
        }
        parent::__construct($label);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function messageKey(): ?string
    {
        return $this->messageKey;
    }

    /** @return list<mixed> */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @param array<int, mixed> $params
     */
    public static function notFound(?string $messageKey = null, array $params = []): self
    {
        return new self('ENOENT', 404, $messageKey, $params);
    }

    /**
     * @param array<int, mixed> $params
     */
    public static function denied(?string $messageKey = null, array $params = []): self
    {
        return new self('EACCES', 403, $messageKey, $params);
    }

    /**
     * @param array<int, mixed> $params
     */
    public static function unauthorized(?string $messageKey = null, array $params = []): self
    {
        return new self('EAUTH', 401, $messageKey, $params);
    }

    /**
     * @param array<int, mixed> $params
     */
    public static function badRequest(?string $messageKey = null, array $params = []): self
    {
        return new self('EINVAL', 400, $messageKey, $params);
    }
}
