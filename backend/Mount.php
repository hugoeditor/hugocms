<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Ein benannter Einhängepunkt: eine Wurzel im Dateisystem mit eigenen
 * Rechten und erlaubten Endungen. Pfade werden stets mount-relativ
 * adressiert; der echte Serverpfad verlässt das Backend nie.
 */
final class Mount
{
    public const ALL_PERMISSIONS = ['read', 'write', 'upload', 'delete', 'mkdir', 'rename', 'copy', 'move'];

    /** @var list<string> */
    private array $permissions;

    /** @var list<string> Kleingeschriebene Endungen ohne Punkt; leer = alle erlaubt. */
    private array $accept;

    private readonly string $root;

    public function __construct(
        private readonly string $name,
        string $path,
        private readonly string $label,
        ?array $permissions = null,
        ?array $accept = null,
        bool $readonly = false,
    ) {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw ApiException::badRequest("Mount-Pfad existiert nicht oder ist kein Verzeichnis: {$path}");
        }
        $this->root = $real;

        if ($readonly) {
            $this->permissions = ['read'];
        } else {
            $this->permissions = $permissions ?? self::ALL_PERMISSIONS;
        }

        $this->accept = array_map(
            static fn (string $e): string => strtolower(ltrim($e, '.')),
            $accept ?? [],
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Prüft, ob ein Dateiname nach der accept-Liste erlaubt ist.
     * Verzeichnisse sind immer erlaubt.
     */
    public function accepts(string $filename): bool
    {
        if ($this->accept === []) {
            return true;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->accept, true);
    }

    /**
     * Mount-Beschreibung für das Frontend (ohne echten Serverpfad).
     */
    public function describe(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'permissions' => array_values($this->permissions),
            'accept' => $this->accept,
        ];
    }
}
