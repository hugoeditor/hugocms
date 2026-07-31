<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

use HugoCMS\FileManager\Config;
use HugoCMS\FileManager\Exception\ApiException;

/**
 * Persistenz der Benutzerkonten für den Mehrbenutzer-Treiber: EINE INI-Datei je
 * Konto unter backend/users/, nach dem Muster der Mount-Konfigurationen
 * (mounts/<hash>.ini).
 *
 *   users/
 *     3f2a…c1.ini   ; „redakteur"
 *     9b71…04.ini   ; „lektorin"
 *
 * Der Dateiname ist der SHA-256 des kleingeschriebenen Namens. Das hat zwei
 * Gründe: Die Anmeldung findet das Konto mit EINEM Zugriff statt über einen
 * Verzeichnis-Durchlauf, und ein Name mit Sonderzeichen kann keinen Pfad
 * aufbrechen — der Dateiname besteht immer nur aus [0-9a-f].
 *
 * Eine Datei je Konto (statt einer gemeinsamen users.ini) hält gleichzeitige
 * Schreibvorgänge verschiedener Benutzer voneinander fern: Wer seine
 * Einstellungen speichert, fasst nur seine eigene Datei an.
 *
 * Aufbau einer Datei:
 *
 *   [account]
 *   username = "redakteur"
 *   password_hash = "$2y$10$…"
 *   role = "editor"          ; admin | editor
 *   disabled = "false"
 *   sites = "kunde-a.example.com/cms-api"   ; Kommaliste oder "*"
 *
 *   [user]
 *   session_lifetime = "8"   ; dieselben Schlüssel wie die [user]-Sektion der
 *   content_width = "1200"   ; hugocms.ini — nur eben je Benutzer
 */
final class UserStore
{
    /**
     * Erlaubte Benutzernamen: 1 bis 64 Zeichen ohne Steuerzeichen und ohne die
     * Zeichen, die eine INI-Zeile aufbrechen würden.
     */
    private const NAME_PATTERN = '/^[^\x00-\x1F"\[\]=]{1,64}$/u';

    public function __construct(private readonly string $dir)
    {
    }

    /** Verzeichnis der Benutzerdateien (für Diagnose und Einrichtung). */
    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * Prüft einen Benutzernamen und liefert ihn getrimmt zurück.
     *
     * @throws ApiException bei ungültigem Namen
     */
    public static function normalizeName(string $username): string
    {
        $username = trim($username);
        if (preg_match(self::NAME_PATTERN, $username) !== 1) {
            throw ApiException::badRequest('USER-NAME-INVALID', [$username]);
        }

        return $username;
    }

    /** Schlüssel für den Vergleich zweier Namen (ohne Belang der Schreibweise). */
    public static function key(string $username): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($username), 'UTF-8')
            : strtolower(trim($username));
    }

    /** Pfad der Datei eines Kontos — auch für noch nicht angelegte Konten. */
    public function path(string $username): string
    {
        return $this->dir . '/' . hash('sha256', self::key($username)) . '.ini';
    }

    public function exists(string $username): bool
    {
        return is_file($this->path($username));
    }

    /**
     * Lädt ein Konto. null, wenn es keines gibt.
     *
     * @return ?array{name: string, hash: string, role: string, sites: list<string>, disabled: bool, prefs: array<string, string>}
     */
    public function load(string $username): ?array
    {
        $path = $this->path($username);
        if (!is_file($path)) {
            return null;
        }
        $raw = Config::raw($path);
        $account = $raw['account'] ?? [];
        $name = isset($account['username']) ? (string) $account['username'] : '';
        $hash = isset($account['password_hash']) ? (string) $account['password_hash'] : '';
        if ($name === '' || $hash === '') {
            return null; // unbrauchbare Datei — wie ein fehlendes Konto behandeln
        }

        return [
            'name' => $name,
            'hash' => $hash,
            'role' => self::normalizeRole($account['role'] ?? ''),
            'sites' => self::parseSites($account['sites'] ?? ''),
            'disabled' => filter_var($account['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'prefs' => array_map('strval', $raw['user'] ?? []),
        ];
    }

    /**
     * Alle Konten, nach Namen sortiert. Liest jede Datei im Verzeichnis — nur
     * für die Verwaltungsansicht gedacht, nicht für den Anmeldeweg.
     *
     * @return list<array{name: string, hash: string, role: string, sites: list<string>, disabled: bool, prefs: array<string, string>}>
     */
    public function all(): array
    {
        $users = [];
        foreach (glob($this->dir . '/*.ini') ?: [] as $path) {
            $raw = Config::raw($path);
            $account = $raw['account'] ?? [];
            $name = isset($account['username']) ? (string) $account['username'] : '';
            if ($name === '') {
                continue;
            }
            $users[] = [
                'name' => $name,
                'hash' => isset($account['password_hash']) ? (string) $account['password_hash'] : '',
                'role' => self::normalizeRole($account['role'] ?? ''),
                'sites' => self::parseSites($account['sites'] ?? ''),
                'disabled' => filter_var($account['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'prefs' => array_map('strval', $raw['user'] ?? []),
            ];
        }
        usort($users, static fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        return $users;
    }

    /** Anzahl der Konten mit der Rolle „admin", die nicht gesperrt sind. */
    public function activeAdminCount(): int
    {
        $count = 0;
        foreach ($this->all() as $user) {
            if ($user['role'] === UserAdminInterface::ROLE_ADMIN && !$user['disabled']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Legt ein Konto an oder überschreibt dessen [account]-Sektion vollständig.
     * Die [user]-Sektion bleibt erhalten (updateSections fasst sie nicht an).
     *
     * @param list<string> $sites
     */
    public function write(string $username, string $passwordHash, string $role, array $sites, bool $disabled): void
    {
        $username = self::normalizeName($username);
        $this->ensureDirectory();

        Config::updateSections(
            $this->path($username),
            [
                'account' => [
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'role' => self::normalizeRole($role),
                    'sites' => implode(', ', self::normalizeSites($sites)),
                    'disabled' => $disabled ? 'true' : 'false',
                ],
            ],
            $this->header($username),
        );
    }

    /**
     * Ändert einzelne Felder der [account]-Sektion; nicht genannte bleiben, wie
     * sie sind.
     *
     * @param array<string, string> $changes
     */
    public function updateAccount(string $username, array $changes): void
    {
        $path = $this->path($username);
        if (!is_file($path)) {
            throw new ApiException('ENOENT', 404, 'USER-NOT-FOUND', [$username]);
        }
        $account = Config::raw($path)['account'] ?? [];
        Config::updateSections($path, ['account' => array_merge($account, $changes)]);
    }

    /**
     * Schreibt einzelne [user]-Schlüssel eines Kontos; der Wert null entfernt
     * einen Schlüssel.
     *
     * @param array<string, string|null> $changes
     */
    public function savePreferences(string $username, array $changes): void
    {
        $path = $this->path($username);
        if (!is_file($path)) {
            throw new ApiException('ENOENT', 404, 'USER-NOT-FOUND', [$username]);
        }
        $prefs = Config::raw($path)['user'] ?? [];
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($prefs[$key]);
            } else {
                $prefs[$key] = $value;
            }
        }
        Config::updateSections($path, ['user' => $prefs]);
    }

    /**
     * Benennt ein Konto um: Da der Dateiname aus dem Namen entsteht, entsteht
     * eine neue Datei und die alte fällt weg. Die [user]-Einstellungen ziehen
     * mit um.
     */
    public function rename(string $oldUsername, string $newUsername): void
    {
        $newUsername = self::normalizeName($newUsername);
        $oldPath = $this->path($oldUsername);
        if (!is_file($oldPath)) {
            throw new ApiException('ENOENT', 404, 'USER-NOT-FOUND', [$oldUsername]);
        }
        if (self::key($oldUsername) === self::key($newUsername)) {
            // Nur die Schreibweise ändert sich — dieselbe Datei, neuer Wert.
            $this->updateAccount($oldUsername, ['username' => $newUsername]);

            return;
        }
        if ($this->exists($newUsername)) {
            throw new ApiException('ECONFLICT', 409, 'USER-EXISTS', [$newUsername]);
        }

        $raw = Config::raw($oldPath);
        $account = array_merge($raw['account'] ?? [], ['username' => $newUsername]);
        $this->ensureDirectory();
        Config::updateSections(
            $this->path($newUsername),
            ['account' => $account, 'user' => $raw['user'] ?? []],
            $this->header($newUsername),
        );
        @unlink($oldPath);
    }

    public function delete(string $username): void
    {
        $path = $this->path($username);
        if (!is_file($path)) {
            throw new ApiException('ENOENT', 404, 'USER-NOT-FOUND', [$username]);
        }
        if (!@unlink($path)) {
            throw new ApiException('EIO', 500, 'USER-DELETE-FAILED', [$username]);
        }
    }

    /**
     * Darf dieses Konto die Webseite bedienen? Administratoren und Konten mit
     * „*" dürfen überall; sonst muss die Kennung in der Liste stehen.
     *
     * @param array{role: string, sites: list<string>} $user
     */
    public static function mayAccessSite(array $user, string $siteKey): bool
    {
        if ($user['role'] === UserAdminInterface::ROLE_ADMIN) {
            return true;
        }
        foreach ($user['sites'] as $site) {
            if ($site === UserAdminInterface::ALL_SITES || strcasecmp($site, $siteKey) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Unbekannte Rollen gelten als „editor" — die Rolle mit weniger Rechten. */
    public static function normalizeRole(mixed $role): string
    {
        return strtolower(trim((string) $role)) === UserAdminInterface::ROLE_ADMIN
            ? UserAdminInterface::ROLE_ADMIN
            : UserAdminInterface::ROLE_EDITOR;
    }

    /**
     * Kommaliste der Webseiten-Kennungen in ein Feld zerlegen.
     *
     * @return list<string>
     */
    public static function parseSites(mixed $sites): array
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $sites)), static fn ($s) => $s !== '');

        return array_values(array_unique($parts));
    }

    /**
     * Prüft die Webseiten-Zuordnung: „*" schluckt alles andere, Kennungen
     * dürfen die INI-Zeile nicht sprengen.
     *
     * @param list<string> $sites
     *
     * @return list<string>
     */
    public static function normalizeSites(array $sites): array
    {
        $clean = [];
        foreach ($sites as $site) {
            $site = trim((string) $site);
            if ($site === '') {
                continue;
            }
            if ($site === UserAdminInterface::ALL_SITES) {
                return [UserAdminInterface::ALL_SITES];
            }
            if (preg_match('/^[^\x00-\x1F",\[\]=]{1,255}$/u', $site) !== 1) {
                throw ApiException::badRequest('USER-SITE-INVALID', [$site]);
            }
            $clean[] = $site;
        }

        return array_values(array_unique($clean));
    }

    private function header(string $username): string
    {
        return "; HugoCMS – Benutzer „{$username}\"\n"
            . '; Von HugoCMS erzeugt. Der Dateiname ist der SHA-256 des klein'
            . "geschriebenen Namens.\n"
            . '; Das Passwort steht ausschließlich als Hash hier — niemals im Klartext.';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!@mkdir($this->dir, 0o770, true) && !is_dir($this->dir)) {
            throw new ApiException('EIO', 500, 'USER-DIR-FAILED', [$this->dir]);
        }
    }
}
