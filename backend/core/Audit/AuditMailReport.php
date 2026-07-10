<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\HelpService;

/**
 * Formt einen SEO-Audit-Bericht zu Betreff und Reintext-Rumpf einer
 * Benachrichtigungs-E-Mail. Zustandslos (rein statisch), damit ohne Netz
 * testbar.
 *
 * Die Fund-Texte sind sonst clientseitig lokalisiert (ruleId + params). Im
 * Cron/CLI gibt es keinen Client, daher werden die deutschen Texte serverseitig
 * aus den Regel-Hilfen ({@see HelpService}, backend/help/audit/<ruleId>.de.md)
 * gezogen; fehlt die Hilfe, wird die rohe ruleId samt Parametern aufgeführt.
 */
final class AuditMailReport
{
    /** Höchstzahl einzeln aufgeführter Funde; darüber „… und N weitere“. */
    private const int MAX_ISSUES = 50;

    /**
     * @param array<string, mixed> $report Bericht aus {@see AuditRunner::run()}
     * @return array{0: string, 1: string} [Betreff, Rumpf]
     */
    public static function format(array $report, string $helpDir, string $locale, string $host): array
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $errors = (int) ($summary['error'] ?? 0);
        $warnings = (int) ($summary['warning'] ?? 0);
        $hints = (int) ($summary['hint'] ?? 0);

        $subject = sprintf(
            'SEO-Gesundheitscheck %s: %d Fehler, %d Warnungen',
            $host,
            $errors,
            $warnings,
        );

        $help = new HelpService($helpDir);
        $lines = [];
        $lines[] = sprintf('Gesundheitscheck für %s', $host);
        $lines[] = str_repeat('=', 60);
        $lines[] = '';
        $lines[] = sprintf('Zeitpunkt:        %s', (string) ($report['startedAt'] ?? '—'));
        $lines[] = sprintf('Geprüfte Seiten:  %d', (int) ($report['pagesScanned'] ?? 0));
        $lines[] = sprintf('Dauer:            %ss', (string) ($report['seconds'] ?? '0'));
        $lines[] = sprintf('Bericht-ID:       %s', (string) ($report['id'] ?? '—'));
        $lines[] = '';
        $lines[] = sprintf('Zusammenfassung:  %d Fehler / %d Warnungen / %d Hinweise', $errors, $warnings, $hints);
        $lines[] = '';

        // Nur Fehler und Warnungen auflisten — Hinweise werden nur gezählt.
        $issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
        $relevant = array_values(array_filter(
            $issues,
            static fn ($i): bool => is_array($i)
                && in_array($i['severity'] ?? '', ['error', 'warning'], true),
        ));

        // Sortierung: Fehler vor Warnungen, dann nach Kategorie.
        usort($relevant, static function (array $a, array $b): int {
            $rank = static fn (string $s): int => $s === 'error' ? 0 : 1;
            $sa = $rank((string) ($a['severity'] ?? ''));
            $sb = $rank((string) ($b['severity'] ?? ''));

            return $sa <=> $sb ?: strcmp((string) ($a['category'] ?? ''), (string) ($b['category'] ?? ''));
        });

        if ($relevant === []) {
            $lines[] = 'Keine Fehler oder Warnungen.';
        } else {
            $shown = array_slice($relevant, 0, self::MAX_ISSUES);
            foreach ($shown as $issue) {
                $lines[] = self::formatIssue($help, $locale, $issue);
            }
            $rest = count($relevant) - count($shown);
            if ($rest > 0) {
                $lines[] = '';
                $lines[] = sprintf('… und %d weitere.', $rest);
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'Diese Nachricht wurde automatisch vom HugoCMS-Gesundheitscheck erzeugt.';

        return [$subject, implode("\n", $lines)];
    }

    /**
     * Einen einzelnen Fund als Textblock (zwei Zeilen: Kopf + Erläuterung).
     *
     * @param array<string, mixed> $issue
     */
    private static function formatIssue(HelpService $help, string $locale, array $issue): string
    {
        $ruleId = (string) ($issue['ruleId'] ?? '');
        $severity = ($issue['severity'] ?? '') === 'error' ? 'FEHLER' : 'WARNUNG';
        $ort = (string) ($issue['url'] ?? '') ?: (string) ($issue['sourceFile'] ?? '') ?: '—';

        try {
            $topic = $help->topic('audit', $ruleId, $locale);
            $titel = (string) $topic['title'];
            $erklaerung = (string) ($topic['summary'] ?? '');
        } catch (ApiException) {
            // Keine Hilfedatei: rohe Regel-ID samt Parametern nennen.
            $titel = $ruleId !== '' ? $ruleId : 'Unbekannte Regel';
            $params = is_array($issue['params'] ?? null) ? $issue['params'] : [];
            $erklaerung = $params === [] ? '' : 'Parameter: ' . implode(', ', array_map(
                static fn ($p): string => is_scalar($p) ? (string) $p : json_encode($p, JSON_UNESCAPED_UNICODE),
                $params,
            ));
        }

        $block = sprintf('[%s] %s — %s', $severity, $titel, $ort);
        if ($erklaerung !== '') {
            $block .= "\n        " . $erklaerung;
        }

        return $block;
    }
}
