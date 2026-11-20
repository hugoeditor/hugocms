// Kennung eines Audit-Funds. Regel plus betroffene Seite (ersatzweise die
// Quelldatei) identifizieren ihn eindeutig genug, um ihn über Läufe hinweg
// wiederzuerkennen — genau das braucht die dauerhafte Ignorierliste.
//
// Diese Zusammensetzung ist ein Vertrag zwischen Client und Server: Der
// Connector findet einen Fund im gespeicherten Bericht auf demselben Weg
// wieder (Connector::findIssue), und IgnoreStore::keyFor bildet sie in PHP
// nach. Wird hier etwas geändert, verlieren alle bestehenden Ignorierungen
// ihren Bezug.
export function issueKey(issue) {
  return issue.ruleId + '|' + (issue.url || issue.sourceFile || '')
}

// Ausgewählte Funde nach Quelldatei gruppieren — der Zuschnitt eines
// gebündelten KI-Auftrags: EINE Datei je Auftrag, alle ihre Funde zusammen.
// Nur Funde, die der Server als über die Content-Datei behebbar meldet
// (`fixable`), kommen hinein; alles andere (Theme, Konfiguration, Struktur)
// bliebe im Auftrag ohnehin liegen.
//
// `limit` teilt große Gruppen auf, damit ein Auftrag die Obergrenze des
// Servers (Connector::FIX_MANY_MAX) nicht überschreitet.
export function groupFixableBySource(issues, limit = 25) {
  const bySource = new Map()
  for (const issue of issues) {
    // Ignorierte Funde bleiben außen vor: Sie wurden bewusst abgehakt, und ein
    // Sammelauftrag, der sie doch anfasst, arbeitet gegen diese Entscheidung.
    // Einzeln lässt sich ein ignorierter Fund weiterhin bearbeiten.
    if (issue.ignored || !issue.fixable || !issue.sourceFile) continue
    const list = bySource.get(issue.sourceFile) ?? []
    list.push({ ruleId: issue.ruleId, url: issue.url ?? null, key: issueKey(issue) })
    bySource.set(issue.sourceFile, list)
  }

  const groups = []
  for (const [sourceFile, list] of bySource) {
    for (let i = 0; i < list.length; i += limit) {
      groups.push({ sourceFile, issues: list.slice(i, i + limit) })
    }
  }

  return groups
}
