<script setup>
// Tabelle der Audit-Funde. Übersetzt jede Regel-ID in eine lesbare Meldung
// (Schlüssel audit.rules.<ruleId mit Unterstrichen>, Parameter aus dem Fund).
// Hat ein Fund eine fileId, lässt sich die Quelldatei direkt im Editor öffnen.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  issues: { type: Array, required: true },
})
const emit = defineEmits(['open-source', 'open-help'])

const { t } = useI18n()

function ruleMessage(issue) {
  return t('audit.rules.' + issue.ruleId.replaceAll('.', '_'), issue.params || [])
}

// Filter ausschließlich über URL und Quelldatei. Die Eingabe wird entprellt,
// damit nicht bei jedem Tastendruck die (bis zu Tausende) Funde neu gefiltert
// und die Tabelle neu gerendert wird.
const searchInput = ref('') // an das Eingabefeld gebunden
const search = ref('') // entprellt, treibt den Filter
let searchTimer = null
watch(searchInput, (v) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { search.value = v }, 200)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
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
watch([() => props.issues, search], () => { limit.value = STEP })

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
    <div class="audit-search">
      <v-text-field
        v-model="searchInput"
        :placeholder="$t('audit.searchPlaceholder')"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        variant="outlined"
        hide-details
        clearable
      />
    </div>

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
        <td
          class="col-url"
          :class="{ 'col-url-clickable': issue.fileId }"
          :title="issue.fileId ? $t('audit.openSource') : null"
          @click="issue.fileId && emit('open-source', issue)"
        >
          <code v-if="issue.url">{{ issue.url }}</code>
          <span v-else-if="issue.sourceFile" class="audit-src">{{ issue.sourceFile }}</span>
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
/* Klebriges Suchfeld über der Tabelle; der Tabellenkopf klebt darunter. */
.audit-search {
  position: sticky;
  top: 0;
  z-index: 2;
  padding: 8px 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
}
.audit-list { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.audit-list thead th {
  position: sticky;
  top: 56px;
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
/* Zugeordnete Zeile: gesamte URL-/Quelldatei-Zelle öffnet die Datei im Editor
   (wie der Springen-Button). */
.col-url-clickable { cursor: pointer; }
.col-url-clickable:hover code,
.col-url-clickable:hover .audit-src {
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
