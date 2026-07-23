<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Minimaler SMTP-Client über Sockets — ohne externe Abhängigkeit (kein Composer,
 * kein PHPMailer, kein mail()). Verschickt eine Reintext-Mail (UTF-8) an einen
 * Empfänger. Unterstützt STARTTLS (Port 587), implizites SSL (Port 465) und
 * unverschlüsseltes SMTP sowie AUTH LOGIN.
 *
 * Bewusst schlank: genau so viel SMTP, wie der Gesundheitscheck braucht.
 * Fehler werden als {@see ApiException} mit maschinenlesbarem Code geworfen
 * (MAIL-*), damit der Aufrufer sie ins Log schreiben kann.
 */
final class Mailer
{
    /**
     * @param string      $host     SMTP-Server
     * @param int         $port     Port (587 STARTTLS, 465 SSL, 25 unverschlüsselt)
     * @param string      $security 'tls' (STARTTLS) | 'ssl' (implizit) | 'none'
     * @param string|null $user     Login; null = keine Authentifizierung
     * @param string|null $pass     Passwort
     * @param string      $from     Absenderadresse
     * @param int         $timeout  Zeitüberschreitung in Sekunden
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $security,
        private readonly ?string $user,
        private readonly ?string $pass,
        private readonly string $from,
        private readonly int $timeout = 20,
    ) {
    }

    /**
     * Versendet eine Reintext-Mail (UTF-8) an $to. Wirft bei jedem Problem eine
     * ApiException (MAIL-*).
     */
    public function send(string $to, string $subject, string $body): void
    {
        $fp = $this->connect();

        try {
            $this->command($fp, 'MAIL FROM:<' . self::addr($this->from) . '>', 250);
            // 251 = „nicht lokal, wird weitergeleitet" gilt ebenfalls als Erfolg.
            $this->command($fp, 'RCPT TO:<' . self::addr($to) . '>', [250, 251]);
            $this->command($fp, 'DATA', 354);

            $data = $this->buildMessage($to, $subject, $body);
            $this->write($fp, $data . "\r\n.\r\n");
            $this->expect($fp, 250);

            // QUIT tolerant: die Nachricht ist bereits angenommen.
            $this->write($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }

    /**
     * Prüft den SMTP-Zugang, ohne eine Nachricht zu verschicken: Verbindung,
     * Begrüßung, ggf. STARTTLS und Anmeldung, dann QUIT. Für den Systemstatus —
     * so fällt ein falsches Passwort auf, bevor der Gesundheitscheck etwas zu
     * melden hat. Wirft dieselben MAIL-*-Codes wie {@see send()}.
     */
    public function verify(): void
    {
        $fp = $this->connect();
        // QUIT tolerant: Ob der Server ihn quittiert, ändert am Ergebnis nichts.
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
    }

    // --- SMTP-Ablauf -------------------------------------------------------

    /**
     * Baut die Verbindung bis einschließlich Anmeldung auf und liefert den
     * offenen Socket. Gemeinsame Vorstufe von {@see send()} und {@see verify()};
     * der Aufrufer schließt ihn.
     *
     * @return resource
     */
    private function connect()
    {
        $remote = ($this->security === 'ssl' ? 'ssl://' : '') . $this->host;
        $fp = @fsockopen($remote, $this->port, $errno, $errstr, (float) $this->timeout);
        if ($fp === false) {
            throw new ApiException('EIO', 502, 'MAIL-CONNECT-FAILED', [$this->host, $this->port]);
        }
        stream_set_timeout($fp, $this->timeout);

        try {
            $this->expect($fp, 220);
            $this->ehlo($fp);

            if ($this->security === 'tls') {
                $this->command($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new ApiException('EIO', 502, 'MAIL-STARTTLS-FAILED');
                }
                // Nach dem TLS-Aufbau ist ein erneutes EHLO Pflicht.
                $this->ehlo($fp);
            }

            if ($this->user !== null) {
                $this->authLogin($fp);
            }
        } catch (\Throwable $e) {
            @fclose($fp);

            throw $e;
        }

        return $fp;
    }

    /** EHLO mit lokalem Hostnamen; verlangt eine 250er-Antwort. */
    private function ehlo($fp): void
    {
        $name = gethostname() ?: 'localhost';
        $this->command($fp, 'EHLO ' . $name, 250);
    }

    /** Anmeldung per AUTH LOGIN (base64-kodierter Benutzer/Passwort). */
    private function authLogin($fp): void
    {
        try {
            $this->command($fp, 'AUTH LOGIN', 334);
            $this->command($fp, base64_encode((string) $this->user), 334);
            $this->command($fp, base64_encode((string) $this->pass), 235);
        } catch (ApiException) {
            // Einheitlicher Code — verschleiert nicht, ob Benutzer oder Passwort.
            throw new ApiException('EAUTH', 502, 'MAIL-AUTH-FAILED');
        }
    }

    /**
     * Baut die vollständige Nachricht (Kopfzeilen + Rumpf) mit CRLF und
     * Dot-Stuffing. Der Betreff wird bei Nicht-ASCII als MIME-encoded-word
     * kodiert.
     */
    private function buildMessage(string $to, string $subject, string $body): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->from,
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // Zeilenenden auf CRLF vereinheitlichen, dann Dot-Stuffing: eine Zeile,
        // die nur aus „.“ besteht bzw. mit „.“ beginnt, würde sonst das
        // Nachrichtenende signalisieren.
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    // --- Socket-Hilfen -----------------------------------------------------

    /** Sendet ein Kommando und prüft den erwarteten Antwortcode. */
    private function command($fp, string $line, int|array $expected): void
    {
        $this->write($fp, $line . "\r\n");
        $this->expect($fp, $expected);
    }

    private function write($fp, string $data): void
    {
        if (@fwrite($fp, $data) === false) {
            throw new ApiException('EIO', 502, 'MAIL-CONNECT-FAILED', [$this->host, $this->port]);
        }
    }

    /** Liest die (ggf. mehrzeilige) Antwort und vergleicht den Code. */
    private function expect($fp, int|array $expected): void
    {
        [$code, $text] = $this->readResponse($fp);
        $ok = is_array($expected) ? in_array($code, $expected, true) : $code === $expected;
        if (!$ok) {
            throw new ApiException('EIO', 502, 'MAIL-SMTP-ERROR', [$code, $text]);
        }
    }

    /**
     * Liest eine SMTP-Antwort. Mehrzeilige Antworten haben in allen Zeilen außer
     * der letzten ein „-“ an 4. Stelle (z. B. „250-…“, Abschluss „250 …“).
     *
     * @return array{0: int, 1: string}
     */
    private function readResponse($fp): array
    {
        $code = 0;
        $lines = [];
        do {
            $line = fgets($fp);
            if ($line === false) {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new ApiException('EIO', 504, 'MAIL-TIMEOUT');
                }
                throw new ApiException('EIO', 502, 'MAIL-SMTP-ERROR', [0, 'Verbindung abgebrochen']);
            }
            $code = (int) substr($line, 0, 3);
            $lines[] = trim(substr($line, 4));
            $more = strlen($line) >= 4 && $line[3] === '-';
        } while ($more);

        return [$code, implode(' ', $lines)];
    }

    /** Extrahiert die reine Adresse aus „Name <adresse>“ für die SMTP-Hülle. */
    private static function addr(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m) === 1) {
            return trim($m[1]);
        }

        return trim($value);
    }

    /** Kodiert eine Kopfzeile als MIME-encoded-word, wenn sie Nicht-ASCII enthält. */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xff]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
