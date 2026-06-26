<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

/**
 * Prüft die Pro-Lizenz und schaltet die Pro-Funktionen frei (derzeit Git).
 *
 * Die Lizenz ist ein domain-gebundener, Ed25519-signierter Schlüssel im Format
 *
 *   HUGOCMS-{base64(json{licensee,domain,issued_at})}-{hex(ed25519_signatur)}
 *
 * Signiert wird über die Base64-Zeichenkette. Der zugehörige PRIVATE Schlüssel
 * liegt ausschließlich im Keygen-Werkzeug (scripts/keygen/, nicht ausgeliefert);
 * hier steht nur der ÖFFENTLICHE Schlüssel zum Prüfen. Damit lässt sich ein
 * gültiger Schlüssel nicht ohne den privaten Schlüssel erzeugen.
 *
 * Bewusste Eigenschaften (so gewollt):
 *   • KEIN Ablaufdatum — eine Lizenz gilt unbefristet.
 *   • STARRE Domain-Bindung — die Domain im Schlüssel muss dem normalisierten
 *     Host der Anfrage entsprechen ({@see SiteKey::host}: klein, ohne Port und
 *     ohne Endpunkt-Pfad). Ein Schlüssel für eine Domain funktioniert auf keiner
 *     anderen; ein Umzug des API-Endpunkts (/cms-api → /hugocms-api) lässt ihn
 *     dagegen gültig. HugoCMS verwaltet mehrere Webseiten — eine Lizenz je Domain.
 *
 * Der Schlüssel steht pro Webseite in der [license]-Sektion der
 * mounts/<hash>.ini (wo auch [hugo] liegt); die Persistenz läuft über {@see Config}.
 */
final class License
{
    /**
     * Öffentlicher Ed25519-Schlüssel (hex, 32 Byte). Der private Gegenpart
     * liegt nur im Keygen-Werkzeug (scripts/keygen/private.key).
     */
    private const string PUBLIC_KEY = 'b1c6b98bbb1913e992c66df9ffbf71e570e642b5cba01be42180724de81bb209';

    private const string KEY_PREFIX = 'HUGOCMS-';

    /** Geprüfte Nutzdaten des Schlüssels für diese Anfrage; false = noch nicht geprüft. */
    private array|false|null $decoded = false;

    /**
     * @param ?string $key    Roher Lizenzschlüssel aus [license] key, oder null.
     * @param string $domain  Normalisierter Host der Anfrage ({@see SiteKey::host}).
     */
    public function __construct(
        private readonly ?string $key,
        private readonly string $domain,
    ) {
    }

    /** Ist eine gültige, auf diese Domain ausgestellte Pro-Lizenz vorhanden? */
    public function isPro(): bool
    {
        return $this->validate() !== null;
    }

    /**
     * Lizenzstatus für den Client (enthält keine Geheimnisse — der Schlüssel
     * selbst wird nie zurückgegeben).
     *
     * @return array{edition: string, licensee: ?string, domain: string, configured: bool}
     */
    public function info(): array
    {
        $data = $this->validate();

        return [
            'edition' => $data !== null ? 'pro' : 'community',
            'licensee' => $data['licensee'] ?? null,
            'domain' => $this->domain,
            // Ein Schlüssel ist hinterlegt (ggf. ungültig/falsche Domain).
            'configured' => $this->key !== null && $this->key !== '',
        ];
    }

    /** Geprüfte Nutzdaten des hinterlegten Schlüssels (einmal je Anfrage). */
    private function validate(): ?array
    {
        if ($this->decoded === false) {
            $this->decoded = ($this->key === null || $this->key === '')
                ? null
                : self::decode($this->key, $this->domain);
        }

        return $this->decoded;
    }

    /**
     * Prüft einen Schlüssel gegen eine Domain: Format, Ed25519-Signatur und
     * Domain-Bindung. Liefert die Nutzdaten oder null bei jedem Fehlschlag.
     * Statisch, damit die Aktivierung denselben Pfad nutzt wie die Laufzeit.
     *
     * @return array{licensee: string, domain: string}|null
     */
    public static function decode(string $key, string $domain): ?array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        $body = substr($key, strlen(self::KEY_PREFIX));
        $lastDash = strrpos($body, '-');
        if ($lastDash === false || $lastDash === 0) {
            return null;
        }
        $dataB64 = substr($body, 0, $lastDash);
        $signatureHex = substr($body, $lastDash + 1);
        if ($dataB64 === '' || $signatureHex === '') {
            return null;
        }

        $json = base64_decode($dataB64, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['licensee'], $data['domain'])) {
            return null;
        }

        // Signatur über die Base64-Zeichenkette prüfen.
        try {
            $signature = sodium_hex2bin($signatureHex);
            $publicKey = sodium_hex2bin(self::PUBLIC_KEY);
        } catch (\SodiumException) {
            return null;
        }
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        if (!sodium_crypto_sign_verify_detached($signature, $dataB64, $publicKey)) {
            return null;
        }

        // Starre Domain-Bindung (Domains sind ohne Belang der Groß-/Kleinschreibung).
        if (strcasecmp(trim((string) $data['domain']), trim($domain)) !== 0) {
            return null;
        }

        return ['licensee' => (string) $data['licensee'], 'domain' => (string) $data['domain']];
    }
}
