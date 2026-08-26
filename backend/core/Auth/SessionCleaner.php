<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Räumt abgelaufene Sitzungsdateien aus dem eigenen Sitzungsverzeichnis.
 *
 * Warum überhaupt: HugoCMS legt die Sitzungen in `backend/var/sessions` statt im
 * Standardpfad von PHP. Die Müllabfuhr von PHP läuft dort nicht — auf Debian und
 * Ubuntu steht `session.gc_probability` auf 0, weil ein System-Cron das
 * Standardverzeichnis putzt. Das eigene kennt der nicht, also bleiben die
 * Dateien jedes Benutzers liegen, der sich nicht abmeldet.
 *
 * Warum nicht einfach nach Alter: Die Sitzungsdauer ist je Konto einstellbar
 * (Mehrbenutzer: `prefs.session_lifetime`, sonst `[user] session_lifetime`) und
 * nach oben offen. Eine feste Schwelle würde Sitzungen mit langer Dauer aus dem
 * Verzeichnis werfen und die Betreffenden abmelden — schlimmer als ein volles
 * Verzeichnis. Deshalb trägt jede Sitzung ihren Verfallszeitpunkt selbst
 * ({@see SessionHandling::EXPIRES_KEY}); gelöscht wird nur, was laut eigener
 * Angabe abgelaufen ist.
 *
 * Dateien ohne diesen Eintrag — Altbestände aus der Zeit vor dieser Änderung —
 * verschwinden über eine bewusst großzügige Rückfall-Frist.
 *
 * Der Aufruf erfolgt aus dem Web-Request heraus (siehe SessionHandling): Dort
 * läuft er unter dem Benutzer, dem die Dateien gehören. Ein Cron-Lauf unter
 * einem anderen Konto dürfte sie unter Umständen gar nicht löschen.
 */
final class SessionCleaner
{
    /** Nur die eigenen Dateien anfassen — PHP legt sie mit diesem Präfix an. */
    private const PREFIX = 'sess_';

    /** So viel vom Dateianfang genügt; Sitzungen hier sind wenige hundert Byte. */
    private const READ_BYTES = 8192;

    /**
     * Puffer auf den Verfallszeitpunkt. Deckt Uhrabweichungen ab und lässt eine
     * gerade abgelaufene Sitzung noch einen Moment stehen.
     */
    private const GRACE = 3600;

    /**
     * Rückfall für Dateien OHNE Verfallszeitpunkt (Altbestand): erst nach dieser
     * Zeit ohne Zugriff löschen. Bewusst großzügig — die Dauer der betreffenden
     * Sitzung ist unbekannt.
     */
    private const FALLBACK_AGE = 2_592_000; // 30 Tage

    /** Obergrenze je Lauf, damit ein Request nicht an einem Riesenordner hängt. */
    private const MAX_DELETIONS = 500;

    /** Wie viele Dateien höchstens betrachtet werden (Schutz vor Endlosläufen). */
    private const MAX_VISITS = 20_000;

    /**
     * Löscht abgelaufene Sitzungsdateien und liefert deren Anzahl.
     *
     * Fehler (fehlende Rechte, verschwundene Datei) werden übergangen: Aufräumen
     * ist Beiwerk und darf einen Request nie stören.
     */
    public static function purge(string $dir, int $now = 0): int
    {
        $now = $now > 0 ? $now : time();
        $handle = @opendir($dir);
        if ($handle === false) {
            return 0;
        }

        $deleted = 0;
        $visited = 0;
        while (($name = readdir($handle)) !== false) {
            if ($deleted >= self::MAX_DELETIONS || $visited >= self::MAX_VISITS) {
                break;
            }
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            $visited++;
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            if (self::isExpired($path, $now) && @unlink($path)) {
                $deleted++;
            }
        }
        closedir($handle);

        return $deleted;
    }

    /**
     * Ist diese Sitzungsdatei abgelaufen? Maßgeblich ist der Verfallszeitpunkt
     * IN der Datei; fehlt er, entscheidet das Alter (Rückfall).
     */
    private static function isExpired(string $path, int $now): bool
    {
        $expires = self::readExpiry($path);
        if ($expires !== null) {
            return $expires + self::GRACE < $now;
        }

        $mtime = @filemtime($path);

        return $mtime !== false && $mtime + self::FALLBACK_AGE < $now;
    }

    /**
     * Liest den Verfallszeitpunkt aus der Sitzungsdatei.
     *
     * Bewusst ohne session_decode(): Das verlangt eine laufende Sitzung und
     * würde die des aufrufenden Benutzers überschreiben. Der Ausdruck deckt
     * beide Serialisierungsarten ab — `key|i:123;` (Standard `php`) und
     * `s:18:"key";i:123;` (`php_serialize`).
     */
    private static function readExpiry(string $path): ?int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, self::READ_BYTES);
        fclose($handle);

        if (preg_match('/hugocms_fm_expires.{0,6}?i:(\d+)/s', $head, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
