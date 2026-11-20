<script setup>
// Tabelle der Audit-Funde. Übersetzt jede Regel-ID in eine lesbare Meldung
// (Schlüssel audit.rules.<ruleId mit Unterstrichen>, Parameter aus dem Fund).
// Die URL öffnet die veröffentlichte Webseite (das gebaute HTML) in einem neuen
// Tab; hat ein Fund eine fileId, öffnet der Knopf rechts die Quelldatei im Editor.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { issueKey } from '../util/auditIssue'

const props = defineProps({
  issues: { type: Array, required: true },
  // Suchbegriff (URL/Quelle). Das Eingabefeld sitzt im Berichtskopf der
  // AuditView; der bereits entprellte Wert kommt als Prop herein. Leer = kein
  // Filter.
  search: { type: String, default: '' },
  // Funde, für die in dieser Sitzung bereits ein KI-Micro-Auftrag lief
  // (Schlüssel aus issueKey). Sie bleiben stehen — der Bericht ist ein
  // Schnappschuss —, werden aber als erledigt gekennzeichnet.
  fixedKeys: { type: Array, default: () => [] },
  // Läuft gerade ein Auftrag? Dann keinen zweiten anstoßen.
  busy: { type: Boolean, default: false },
  // Ausgewählte Funde (Schlüssel aus issueKey). Die Auswahl selbst hält die
  // AuditView — sie baut daraus die Sammelaktionen; hier werden nur die
  // Kästchen daraus abgeleitet und Änderungen nach oben gemeldet.
  selected: { type: Array, default: () => [] },
})
const emit = defineEmits([
  'open-source',
  'open-help',
  'fix-issue',
  'diagnose-issue',
  'toggle-ignore',
  'update:row-count',
  'update:selected',
])

const { t } = useI18n()

function ruleMessage(issue) {
  return t('audit.rules.' + issue.ruleId.replaceAll('.', '_'), issue.params || [])
}

const fixedSet = computed(() => new Set(props.fixedKeys))
function isFixed(issue) {
  return fixedSet.value.has(issueKey(issue))
}

// --- Auswahl -----------------------------------------------------------
// Die Auswahl arbeitet auf Fund-Schlüsseln, nicht auf Zeilen: Eine
// zusammengefasste Duplikat-Gruppe ist EINE Zeile, aber viele Funde — und
// ignoriert oder bearbeitet wird immer der einzelne Fund.
const selectedSet = computed(() => new Set(props.selected))

function isSelected(issue) {
  return selectedSet.value.has(issueKey(issue))
}

// Setzt die Auswahl für eine Menge Funde und meldet die neue Liste nach oben.
function setSelected(issues, on) {
  const next = new Set(props.selected)
  for (const issue of issues) {
    if (on) {
      next.add(issueKey(issue))
    } else {
      next.delete(issueKey(issue))
    }
  }
  emit('update:selected', [...next])
}

function toggleIssue(issue) {
  setSelected([issue], !isSelected(issue))
}

// Kopfzeile einer Duplikat-Gruppe: wählt alle ihre Funde gemeinsam. Sind nur
// einige gewählt, ergänzt der Klick die fehlenden (statt alles abzuwählen) —
// das ist die Erwartung an ein Kästchen im Zwischenzustand.
function groupState(row) {
  const chosen = row.issues.filter(isSelected).length
  return chosen === 0 ? 'none' : chosen === row.issues.length ? 'all' : 'some'
}

function toggleGroup(row) {
  setSelected(row.issues, groupState(row) !== 'all')
}

// Die veröffentlichte Webseite liegt auf derselben Domain wie das CMS (wie der
// „Webseite ansehen"-Knopf in App.vue). issue.url ist der Request-Pfad, z. B.
// "/about/"; daraus die aufrufbare Adresse des gebauten HTML bilden.
const siteOrigin = window.location.origin
function pageUrl(issue) {
  const path = issue.url || '/'
  return siteOrigin + (path.startsWith('/') ? path : '/' + path)
}

// Filter ausschließlich über URL und Quelldatei.
const filtered = computed(() => {
  const q = props.search.trim().toLowerCase()
  if (!q) return props.issues
  return props.issues.filter((i) =>
    [i.url, i.sourceFile].some((v) => String(v ?? '').toLowerCase().includes(q)),
  )
})

// Kopf-Kästchen: greift auf ALLE Funde der aktuellen Ansicht — nach Filter und
// Suche, nicht nur auf die gerenderten 300 Zeilen. Wer „alles auswählen"
// drückt, meint, was die Liste gerade zeigt.
const allSelected = computed(() => filtered.value.length > 0 && filtered.value.every(isSelected))
const someSelected = computed(() => !allSelected.value && filtered.value.some(isSelected))

function toggleAll() {
  setSelected(filtered.value, !allSelected.value)
}

// Zeilen bilden: Funde, die derselbe Text auf vielen Seiten erzeugt (doppelte
// Meta-Description, doppelter Titel), kommen vom Server mit einer gemeinsamen
// Kennung in `context.group`. Sie werden zu EINER aufklappbaren Zeile
// zusammengefasst — die Funde selbst bleiben einzeln erhalten (Zähler,
// Quelldatei-Sprung). Funde ohne Kennung (auch alle aus älteren Berichten)
// bleiben eigenständige Zeilen.
const rows = computed(() => {
  const out = []
  const groups = new Map()
  for (const issue of filtered.value) {
    const g = issue.context?.group
    if (!g) {
      out.push({ group: false, issue })
      continue
    }
    const key = issue.ruleId + '|' + g
    const existing = groups.get(key)
    if (existing) {
      existing.issues.push(issue)
      continue
    }
    const row = { group: true, key, lead: issue, issues: [issue] }
    groups.set(key, row)
    out.push(row)
  }
  // Blieb von einer Gruppe nur ein Fund übrig (z. B. durch die Suche), ist die
  // Zusammenfassung überflüssig — dann wieder als gewöhnliche Zeile zeigen.
  return out.map((r) => (r.group && r.issues.length < 2 ? { group: false, issue: r.lead } : r))
})

// Aufgeklappte Gruppen (nach Zeilenschlüssel).
const expanded = ref(new Set())
function toggle(key) {
  const next = new Set(expanded.value)
  if (!next.delete(key)) next.add(key)
  expanded.value = next
}

// Nur einen Ausschnitt rendern: Tausende Zeilen auf einmal blockieren den
// Hauptthread und lassen das Öffnen „lange dauern". Weitere kommen auf Klick.
const STEP = 300
const limit = ref(STEP)
const visibleRows = computed(() => rows.value.slice(0, limit.value))
const hasMore = computed(() => rows.value.length > limit.value)

// Bei Filterwechsel (neue Fundmenge oder Suchbegriff) wieder von vorn begrenzen
// und alle Gruppen schließen.
watch([() => props.issues, () => props.search], () => {
  limit.value = STEP
  expanded.value = new Set()
})

// Die Statuszeile der Ansicht nennt neben der Fundzahl die Zeilenzahl, sobald
// zusammengefasst wurde.
watch(rows, (r) => emit('update:row-count', r.length), { immediate: true })

function showMore() {
  limit.value += STEP
}
</script>

<template>
  <div v-if="issues.length === 0" class="nemo-empty">
    <v-icon icon="mdi-check-circle-outline" size="56" class="nemo-empty-icon" />
    <p>{{ $t('audit.noIssues') }}</p>
  </div>

  <template v-else>
    <div v-if="!filtered.length" class="nemo-empty">
      <v-icon icon="mdi-file-search-outline" size="56" class="nemo-empty-icon" />
      <p>{{ $t('audit.noMatches') }}</p>
    </div>

    <table v-else class="nemo-list audit-list">
    <thead>
      <tr>
        <th class="col-pick">
          <input
            type="checkbox"
            class="audit-check"
            :checked="allSelected"
            :indeterminate.prop="someSelected"
            :title="$t('audit.selectAll')"
            @change="toggleAll"
          />
        </th>
        <th class="col-sev">{{ $t('audit.colSeverity') }}</th>
        <th class="col-msg">{{ $t('audit.colIssue') }}</th>
        <th class="col-url">{{ $t('audit.colUrl') }}</th>
        <th class="col-act"></th>
      </tr>
    </thead>
    <tbody>
      <template v-for="(row, idx) in visibleRows" :key="row.group ? row.key : 'i' + idx">
        <!-- Gewöhnlicher Einzelfund -->
        <tr v-if="!row.group" class="nemo-row" :class="{ 'audit-ignored': row.issue.ignored }">
          <td class="col-pick">
            <input
              type="checkbox"
              class="audit-check"
              :checked="isSelected(row.issue)"
              @change="toggleIssue(row.issue)"
            />
          </td>
          <td class="col-sev">
            <span class="sev-chip" :class="'sev-chip--' + row.issue.severity">{{ $t('audit.severity.' + row.issue.severity) }}</span>
            <!-- Ignoriert: zählt in keiner Zusammenfassung mehr mit; das Zeichen
                 sagt, warum die Zeile blass am Ende der Liste steht. -->
            <v-icon
              v-if="row.issue.ignored"
              icon="mdi-bell-off-outline"
              size="13"
              class="audit-ignored-mark"
              :title="$t('audit.ignoredMark')"
            />
          </td>
          <td class="col-msg">
            <button
              class="audit-msg-help"
              :title="$t('audit.showHelp')"
              @click="emit('open-help', row.issue.ruleId)"
            >
              <span class="audit-msg">{{ ruleMessage(row.issue) }}</span>
              <v-icon icon="mdi-help-circle-outline" size="14" class="audit-msg-hint" />
            </button>
            <code class="audit-ruleid">{{ row.issue.ruleId }}</code>
          </td>
          <td class="col-url">
            <!-- URL öffnet die veröffentlichte Webseite (das gebaute HTML) in einem
                 neuen Tab; die Quelldatei erreicht man über den Knopf rechts. -->
            <a
              v-if="row.issue.url"
              class="audit-url-link"
              :href="pageUrl(row.issue)"
              target="_blank"
              rel="noopener noreferrer"
              :title="$t('audit.openPage')"
            >
              <code>{{ row.issue.url }}</code>
            </a>
            <span
              v-else-if="row.issue.sourceFile"
              class="audit-src"
              :class="{ 'col-url-clickable': row.issue.fileId }"
              :title="row.issue.fileId ? $t('audit.openSource') : null"
              @click="row.issue.fileId && emit('open-source', row.issue)"
            >{{ row.issue.sourceFile }}</span>
            <span v-else>—</span>
          </td>
          <td class="col-act">
            <!-- KI-Micro-Auftrag: behebt genau diesen Fund. Nur bei Regeln, die
                 der Server als über die Content-Datei behebbar meldet. -->
            <button
              v-if="row.issue.fixable"
              class="audit-jump audit-fix"
              :class="{ done: isFixed(row.issue) }"
              :disabled="busy"
              :title="isFixed(row.issue) ? $t('audit.fixDone') : $t('audit.fixWithAi')"
              @click="emit('fix-issue', row.issue)"
            >
              <v-icon :icon="isFixed(row.issue) ? 'mdi-check' : 'mdi-auto-fix'" size="18" />
            </button>
            <!-- Nicht über die Content-Datei behebbar (Theme, Konfiguration,
                 Struktur): Statt einer Behebung gibt es eine Diagnose. -->
            <button
              v-else-if="row.issue.diagnosable"
              class="audit-jump audit-diagnose"
              :disabled="busy"
              :title="$t('audit.diagnoseWithAi')"
              @click="emit('diagnose-issue', row.issue)"
            >
              <v-icon icon="mdi-stethoscope" size="18" />
            </button>
            <button
              v-if="row.issue.fileId"
              class="audit-jump"
              :title="$t('audit.openSource')"
              @click="emit('open-source', row.issue)"
            >
              <v-icon icon="mdi-file-edit-outline" size="18" />
            </button>
            <!-- Dauerhaft ignorieren bzw. wieder aufnehmen. Gilt je Webseite,
                 nicht je Lauf — auch der nächste Bericht zählt den Fund dann
                 nicht mehr mit. -->
            <button
              class="audit-jump audit-ignore"
              :title="row.issue.ignored ? $t('audit.unignore') : $t('audit.ignore')"
              @click="emit('toggle-ignore', row.issue)"
            >
              <v-icon :icon="row.issue.ignored ? 'mdi-bell-outline' : 'mdi-bell-off-outline'" size="18" />
            </button>
          </td>
        </tr>

        <!-- Zusammengefasste Duplikate: eine Kopfzeile, die betroffenen Seiten
             erscheinen aufgeklappt darunter. -->
        <template v-else>
          <tr class="nemo-row audit-grouprow" :class="{ 'audit-ignored': row.lead.ignored }" @click="toggle(row.key)">
            <td class="col-pick">
              <!-- Wählt alle Funde der Gruppe; teilweise gewählt = Zwischenzustand. -->
              <input
                type="checkbox"
                class="audit-check"
                :checked="groupState(row) === 'all'"
                :indeterminate.prop="groupState(row) === 'some'"
                @click.stop
                @change="toggleGroup(row)"
              />
            </td>
            <td class="col-sev">
              <span class="sev-chip" :class="'sev-chip--' + row.lead.severity">{{ $t('audit.severity.' + row.lead.severity) }}</span>
              <v-icon
                v-if="row.lead.ignored"
                icon="mdi-bell-off-outline"
                size="13"
                class="audit-ignored-mark"
                :title="$t('audit.ignoredMark')"
              />
            </td>
            <td class="col-msg">
              <button
                class="audit-msg-help"
                :title="$t('audit.showHelp')"
                @click.stop="emit('open-help', row.lead.ruleId)"
              >
                <span class="audit-msg">{{ ruleMessage(row.lead) }}</span>
                <v-icon icon="mdi-help-circle-outline" size="14" class="audit-msg-hint" />
              </button>
              <code class="audit-ruleid">{{ row.lead.ruleId }}</code>
              <div v-if="row.lead.context?.text" class="audit-dup-text">„{{ row.lead.context.text }}“</div>
            </td>
            <td class="col-url">
              <span class="audit-groupcount">{{ $t('audit.groupPages', [row.issues.length]) }}</span>
            </td>
            <td class="col-act">
              <button
                class="audit-jump"
                :title="expanded.has(row.key) ? $t('audit.groupCollapse') : $t('audit.groupExpand')"
                @click.stop="toggle(row.key)"
              >
                <v-icon :icon="expanded.has(row.key) ? 'mdi-chevron-up' : 'mdi-chevron-down'" size="18" />
              </button>
            </td>
          </tr>
          <tr
            v-for="(issue, i) in (expanded.has(row.key) ? row.issues : [])"
            :key="row.key + '#' + i"
            class="nemo-row audit-childrow"
            :class="{ 'audit-ignored': issue.ignored }"
          >
            <td class="col-pick">
              <input
                type="checkbox"
                class="audit-check"
                :checked="isSelected(issue)"
                @change="toggleIssue(issue)"
              />
            </td>
            <td class="col-sev"></td>
            <td class="col-msg">
              <span
                v-if="issue.sourceFile"
                class="audit-src"
                :class="{ 'col-url-clickable': issue.fileId }"
                :title="issue.fileId ? $t('audit.openSource') : null"
                @click="issue.fileId && emit('open-source', issue)"
              >{{ issue.sourceFile }}</span>
              <span v-else class="audit-src">—</span>
            </td>
            <td class="col-url">
              <a
                v-if="issue.url"
                class="audit-url-link"
                :href="pageUrl(issue)"
                target="_blank"
                rel="noopener noreferrer"
                :title="$t('audit.openPage')"
              >
                <code>{{ issue.url }}</code>
              </a>
              <span v-else>—</span>
            </td>
            <td class="col-act">
              <button
                v-if="issue.fixable"
                class="audit-jump audit-fix"
                :class="{ done: isFixed(issue) }"
                :disabled="busy"
                :title="isFixed(issue) ? $t('audit.fixDone') : $t('audit.fixWithAi')"
                @click="emit('fix-issue', issue)"
              >
                <v-icon :icon="isFixed(issue) ? 'mdi-check' : 'mdi-auto-fix'" size="18" />
              </button>
              <button
                v-else-if="issue.diagnosable"
                class="audit-jump audit-diagnose"
                :disabled="busy"
                :title="$t('audit.diagnoseWithAi')"
                @click="emit('diagnose-issue', issue)"
              >
                <v-icon icon="mdi-stethoscope" size="18" />
              </button>
              <button
                v-if="issue.fileId"
                class="audit-jump"
                :title="$t('audit.openSource')"
                @click="emit('open-source', issue)"
              >
                <v-icon icon="mdi-file-edit-outline" size="18" />
              </button>
              <button
                class="audit-jump audit-ignore"
                :title="issue.ignored ? $t('audit.unignore') : $t('audit.ignore')"
                @click="emit('toggle-ignore', issue)"
              >
                <v-icon :icon="issue.ignored ? 'mdi-bell-outline' : 'mdi-bell-off-outline'" size="18" />
              </button>
            </td>
          </tr>
        </template>
      </template>
    </tbody>
    </table>

    <!-- Weitere Zeilen nachladen (Rendern bleibt so flüssig). -->
    <div v-if="hasMore" class="audit-more nemo-noselect">
      <span class="audit-more-count">{{ $t('audit.showingOf', [visibleRows.length, rows.length]) }}</span>
      <button class="audit-btn" @click="showMore">{{ $t('audit.showMore') }}</button>
    </div>
  </template>
</template>

<style scoped>
.audit-list { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
/* Der Tabellenkopf klebt am oberen Rand des scrollenden Bereichs. Das Suchfeld
   ist in den (nicht scrollenden) Berichtskopf der AuditView gewandert. */
.audit-list thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  text-align: left;
  font-weight: 600;
  color: var(--mint-text-muted);
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  padding: 6px 10px;
  white-space: nowrap;
}
/* Auswahlspalte: schmal, damit sie der Meldung keinen Platz nimmt. */
.col-pick { width: 34px; text-align: center; }
.audit-check { cursor: pointer; accent-color: var(--mint-green); }
.col-sev { width: 120px; }
/* Leichter Schweregrad-Chip (statt v-chip) — spart je Zeile eine Vuetify-
   Komponente, was bei hunderten Zeilen spürbar rendert. */
.sev-chip {
  display: inline-block;
  padding: 1px 8px;
  border-radius: 4px;
  font-size: 0.72rem;
  font-weight: 600;
  white-space: nowrap;
  color: #fff;
}
.sev-chip--error { background: #c0392b; }
.sev-chip--warning { background: #d98613; }
.sev-chip--hint { background: #4a7bab; }
.col-url { width: 28%; }
/* Drei Knöpfe nebeneinander: KI-Auftrag, Sprung zur Quelle, Ignorieren. */
.col-act { width: 116px; text-align: center; white-space: nowrap; }

.nemo-row td {
  padding: 6px 10px;
  border-bottom: 1px solid #f0f0ee;
  vertical-align: top;
}
.nemo-row:hover { background: var(--mint-row-hover); }

/* Kopfzeile einer zusammengefassten Duplikat-Gruppe: die ganze Zeile klappt
   auf und zu (der Hilfe-Knopf darin stoppt das Ereignis). */
.audit-grouprow { cursor: pointer; }
/* Der doppelte Text selbst (z. B. die mehrfach verwendete Meta-Description). */
.audit-dup-text {
  margin-top: 2px;
  font-size: 0.78rem;
  color: var(--mint-text-muted);
  font-style: italic;
}
.audit-groupcount { font-size: 0.8rem; color: var(--mint-text-muted); }
/* Ignorierter Fund: steht am Ende der Liste und zählt nirgends mehr mit. Blass
   statt versteckt — eine Entscheidung, die man nicht mehr sieht, lässt sich
   auch nicht mehr zurücknehmen. */
.audit-ignored td { opacity: 0.55; }
.audit-ignored:hover td { opacity: 0.8; }
.audit-ignored .sev-chip { background: #9a9a97; }
.audit-ignored-mark { color: var(--mint-text-muted); margin-left: 4px; vertical-align: text-bottom; }
/* Betroffene Seite innerhalb einer aufgeklappten Gruppe: eingerückt und
   zurückgenommen, damit die Zugehörigkeit sichtbar bleibt. */
.audit-childrow td { background: var(--mint-panel); }
.audit-childrow .col-msg { padding-left: 26px; }

.col-msg { color: var(--mint-text); }
/* Problembeschreibung als Schaltfläche: öffnet die ausführliche Hilfe. */
.audit-msg-help {
  display: inline-flex;
  align-items: flex-start;
  gap: 4px;
  border: 0;
  background: none;
  padding: 0;
  text-align: left;
  color: var(--mint-text);
  cursor: help;
  font: inherit;
}
.audit-msg-help:hover .audit-msg { text-decoration: underline; }
.audit-msg-help:hover .audit-msg-hint { opacity: 1; }
.audit-msg { display: inline; }
.audit-msg-hint { color: var(--mint-green); opacity: 0.45; flex: 0 0 auto; margin-top: 2px; }
.audit-ruleid {
  display: inline-block;
  margin-top: 2px;
  font-size: 0.72rem;
  color: var(--mint-text-muted);
}
.col-url code, .audit-src {
  font-size: 0.8rem;
  color: var(--mint-text-muted);
  word-break: break-all;
}
/* URL-Link: öffnet die veröffentlichte Webseite in einem neuen Tab. */
.audit-url-link { text-decoration: none; }
.audit-url-link:hover code {
  color: var(--mint-green);
  text-decoration: underline;
}
/* Quelldatei-Zelle (ohne URL): öffnet die Datei im Editor (wie der Springen-
   Knopf rechts). */
.audit-src.col-url-clickable { cursor: pointer; }
.audit-src.col-url-clickable:hover {
  color: var(--mint-green);
  text-decoration: underline;
}
.audit-jump {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 3px;
  color: var(--mint-text);
  cursor: pointer;
}
.audit-jump:hover:not(:disabled) { background: var(--mint-panel-hover); }
.audit-jump:disabled { color: #b6b6b3; cursor: default; }
/* KI-Micro-Auftrag: hebt sich vom Sprung-Knopf ab; nach getaner Arbeit grün.
   Der Fund bleibt in der Liste — der Bericht ist ein Schnappschuss und wird
   erst durch einen neuen Lauf aktuell. */
.audit-fix { color: var(--mint-green); margin-right: 4px; }
.audit-fix.done { color: #fff; background: var(--mint-green); border-color: var(--mint-green); }
/* Diagnose: erklärt nur, ändert nichts — deshalb zurückhaltender als der
   Behebungs-Knopf, mit dem er nie zusammen auftritt. */
.audit-diagnose { color: #4a7bab; margin-right: 4px; }
/* Ignorieren: zurückhaltend — die Entscheidung ist jederzeit umkehrbar. */
.audit-ignore { color: var(--mint-text-muted); margin-left: 4px; }

.nemo-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 0;
  color: var(--mint-text-muted);
  gap: 10px;
}
.nemo-empty-icon { color: #9fc08f; }

/* „Weitere anzeigen"-Leiste unter der begrenzten Tabelle. */
.audit-more {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 12px;
}
.audit-more-count { font-size: 0.82rem; color: var(--mint-text-muted); }
.audit-more .audit-btn {
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 4px 14px;
  font-size: 0.85rem;
  color: var(--mint-text);
  cursor: pointer;
}
.audit-more .audit-btn:hover { background: var(--mint-panel-hover); }
</style>
