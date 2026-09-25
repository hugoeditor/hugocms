<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Shop;

/**
 * Zugangsschlüssel der Shop-Anbindung (OpensourceERP).
 *
 * OpensourceERP erzeugt die Inhaltsdateien eines Webshops und stößt danach den
 * Bau dieser Webseite an. Dafür ruft es einzelne Befehle ohne Sitzung auf und
 * weist sich mit einem Schlüssel aus. Jede Webseite hat ihren eigenen — er
 * steht als Hash in der [shop]-Sektion ihrer Mount-Datei. Der Schlüssel gilt
 * damit genau für die Webseite, deren Adresse aufgerufen wird: HugoCMS bestimmt
 * sie aus Host und Endpunkt, bevor es irgendeinen Befehl ausführt.
 *
 * Bewusst KEIN Treiber der {@see \HugoCMS\FileManager\Auth\AuthInterface}: Die
 * beschreibt die Anmeldung von Benutzern (Passwort, Sitzung, Konten). Ein
 * Schlüssel für Maschinenaufrufe hat nichts davon und ersetzt auch nicht die
 * Anmeldung der Redakteure, sondern steht daneben — und nur für die
 * shop*-Befehle.
 *
 * Gespeichert wird nur der Hash. Ein schneller Hash genügt: Der Schlüssel
 * besteht aus 32 Zufallsbytes, es gibt nichts zu erraten, wogegen ein langsamer
 * Hash wie bei Passwörtern schützen müsste.
 */
final class ShopKey
{
    /** Vorsilbe, an der sich ein Schlüssel in Konfigurationen wiedererkennen lässt. */
    private const PREFIX = 'hcs_';

    private const HASH_PREFIX = 'sha256:';

    /** Erzeugt einen neuen Schlüssel. Er wird nur einmal angezeigt. */
    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(32));
    }

    /** Hash, wie er in der Mount-Datei steht. */
    public static function hash(string $key): string
    {
        return self::HASH_PREFIX . hash('sha256', $key);
    }

    /** Letzte vier Zeichen — genug, um zwei Schlüssel auseinanderzuhalten. */
    public static function hint(string $key): string
    {
        return substr($key, -4);
    }

    /**
     * Vergleicht den vorgelegten Schlüssel mit dem hinterlegten Hash, in
     * konstanter Zeit.
     */
    public static function verify(?string $storedHash, string $presented): bool
    {
        if ($storedHash === null || $storedHash === '' || $presented === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($presented));
    }

    /**
     * Holt den Schlüssel aus der Anfrage.
     *
     * Vorgesehen ist `Authorization: Bearer <Schlüssel>`. Manche Webserver
     * reichen diesen Kopf nicht an PHP weiter (Apache mit CGI/FPM ohne
     * `CGIPassAuth On`); dann gilt `X-HugoCMS-Key` als Ersatz.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function fromRequest(array $server): string
    {
        $authorization = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m) === 1) {
            return $m[1];
        }

        return trim((string) ($server['HTTP_X_HUGOCMS_KEY'] ?? ''));
    }

    /**
     * Kam die Anfrage verschlüsselt an?
     *
     * Der Schlüssel darf nicht im Klartext über das Netz. Ausnahme ist die
     * Loopback-Adresse — dort verlässt nichts den Rechner (Entwicklung, oder
     * OSERP und HugoCMS auf demselben Server). Hinter einem Proxy, der TLS
     * beendet, zählt dessen X-Forwarded-Proto.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function transportSecure(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        $remote = (string) ($server['REMOTE_ADDR'] ?? '');

        return $remote === '127.0.0.1' || $remote === '::1';
    }
}
