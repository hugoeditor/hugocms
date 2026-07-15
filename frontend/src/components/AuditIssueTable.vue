<script setup>
// Tabelle der Audit-Funde. Übersetzt jede Regel-ID in eine lesbare Meldung
// (Schlüssel audit.rules.<ruleId mit Unterstrichen>, Parameter aus dem Fund).
// Die URL öffnet die veröffentlichte Webseite (das gebaute HTML) in einem neuen
// Tab; hat ein Fund eine fileId, öffnet der Knopf rechts die Quelldatei im Editor.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  issues: { type: Array, required: true },
  // Suchbegriff (URL/Quelle). Das Eingabefeld sitzt im Berichtskopf der
  // AuditView; der bereits entprellte Wert kommt als Prop herein. Leer = kein
  // Filter.
  search: { type: String, default: '' },
})
const emit = defineEmits(['open-source', 'open-help'])

const { t } = useI18n()

function ruleMessage(issue) {
  return t('audit.rules.' + issue.ruleId.replaceAll('.', '_'), issue.params || [])
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

// Nur einen Ausschnitt rendern: Tausende Zeilen auf einmal blockieren den
// Hauptthread und lassen das Öffnen „lange dauern". Weitere kommen auf Klick.
const STEP = 300
const limit = ref(STEP)
const visibleIssues = computed(() => filtered.value.slice(0, limit.value))
const hasMore = computed(() => filtered.value.length > limit.value)

// Bei Filterwechsel (neue Fundmenge oder Suchbegriff) wieder von vorn begrenzen.
watch([() => props.issues, () => props.search], () => { limit.value = STEP })

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
        <th class="col-sev">{{ $t('audit.colSeverity') }}</th>
        <th class="col-msg">{{ $t('audit.colIssue') }}</th>
        <th class="col-url">{{ $t('audit.colUrl') }}</th>
        <th class="col-act"></th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(issue, idx) in visibleIssues" :key="idx" class="nemo-row">
        <td class="col-sev"><span class="sev-chip" :class="'sev-chip--' + issue.severity">{{ $t('audit.severity.' + issue.severity) }}</span></td>
        <td class="col-msg">
          <button
            class="audit-msg-help"
            :title="$t('audit.showHelp')"
            @click="emit('open-help', issue.ruleId)"
          >
            <span class="audit-msg">{{ ruleMessage(issue) }}</span>
            <v-icon icon="mdi-help-circle-outline" size="14" class="audit-msg-hint" />
          </button>
          <code class="audit-ruleid">{{ issue.ruleId }}</code>
        </td>
        <td class="col-url">
          <!-- URL öffnet die veröffentlichte Webseite (das gebaute HTML) in einem
               neuen Tab; die Quelldatei erreicht man über den Knopf rechts. -->
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
          <span
            v-else-if="issue.sourceFile"
            class="audit-src"
            :class="{ 'col-url-clickable': issue.fileId }"
            :title="issue.fileId ? $t('audit.openSource') : null"
            @click="issue.fileId && emit('open-source', issue)"
          >{{ issue.sourceFile }}</span>
          <span v-else>—</span>
        </td>
        <td class="col-act">
          <button
            v-if="issue.fileId"
            class="audit-jump"
            :title="$t('audit.openSource')"
            @click="emit('open-source', issue)"
          >
            <v-icon icon="mdi-file-edit-outline" size="18" />
          </button>
        </td>
      </tr>
    </tbody>
    </table>

    <!-- Weitere Funde nachladen (Rendern bleibt so flüssig). -->
    <div v-if="hasMore" class="audit-more nemo-noselect">
      <span class="audit-more-count">{{ $t('audit.showingOf', [visibleIssues.length, filtered.length]) }}</span>
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
.col-act { width: 44px; text-align: center; }

.nemo-row td {
  padding: 6px 10px;
  border-bottom: 1px solid #f0f0ee;
  vertical-align: top;
}
.nemo-row:hover { background: var(--mint-row-hover); }

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
.audit-jump:hover { background: var(--mint-panel-hover); }

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
