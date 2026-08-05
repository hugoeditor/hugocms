<script setup>
// Hyperlink-Suche: findet eine Adresse in den Hugo-Quellen (content/) und in den
// veröffentlichten Seiten (public/) — und vor allem, was ihr ÄHNLICH sieht.
// Genau darum geht es: Links aufspüren, die sich verschrieben haben und deshalb
// ins Leere führen.
//
// Als Overlay-Ansicht wie AuditView/ReviewQueueView (nicht als v-dialog), mit
// derselben Kopfzeile aus Zurück-Pfeil und Titel.
//
// Der Lauf ist segmentiert (siehe stores/linkScan.js): Die Ansicht zeigt den
// Fortschritt und die schon gefundenen Treffer, während die Suche weiterläuft.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLinkScanStore } from '../stores/linkScan'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'

const { t } = useI18n()
const store = useLinkScanStore()
const files = useFilesStore()

// Eingabefeld: lokal, damit Tippen den laufenden Lauf nicht berührt. Beim Öffnen
// die zuletzt gesuchte Adresse übernehmen.
const input = ref(store.query)
const inputRef = ref(null)

watch(
  () => store.open,
  (open) => {
    if (!open) return
    input.value = store.query
    // Das Feld ist der einzige Bedienpunkt der leeren Ansicht — dorthin den Fokus.
    requestAnimationFrame(() => inputRef.value?.focus?.())
  },
)

// Reihenfolge der Abschnitte: Tippfehler zuerst, denn sie sind der Zweck der
// Suche. Was exakt so geschrieben ist, steht zuletzt — es ist ja in Ordnung.
const SECTIONS = [
  { kind: 'similar', icon: 'mdi-alert-circle-outline', color: 'warning' },
  { kind: 'normalized', icon: 'mdi-format-letter-case', color: 'info' },
  { kind: 'exact', icon: 'mdi-check-circle-outline', color: 'success' },
]

const area = ref('all') // 'all' | 'content' | 'public'

// Zähler für den Bereichsfilter — einmal je Trefferänderung statt bei jedem
// Neuzeichnen: Während des Laufs wächst die Liste im Sekundentakt.
const counts = computed(() => {
  let content = 0
  for (const m of store.matches) if (m.area === 'content') content++
  return { all: store.matches.length, content, public: store.matches.length - content }
})

const groups = computed(() => store.groups(area.value))
const sections = computed(() =>
  SECTIONS.map((s) => ({ ...s, groups: groups.value.filter((g) => g.kind === s.kind) })).filter(
    (s) => s.groups.length,
  ),
)

// Zahl der Fundstellen und der betroffenen Dateien im gewählten Bereich.
const summary = computed(() => {
  const hits = groups.value.reduce((n, g) => n + g.hits.length, 0)
  const filesTouched = new Set()
  for (const g of groups.value) for (const h of g.hits) filesTouched.add(h.file)
  return { hits, files: filesTouched.size }
})

// Aufgeklappte Gruppen (Schlüssel: Art + Link).
const expanded = ref(new Set())
function toggle(group) {
  const key = `${group.kind} ${group.link}`
  const next = new Set(expanded.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  expanded.value = next
}
function isExpanded(group) {
  return expanded.value.has(`${group.kind} ${group.link}`)
}

function start() {
  if (input.value.trim().length < 2 || store.running) return
  expanded.value = new Set()
  store.start(input.value)
}

// Zur Fundstelle springen: Overlay schließen, Datei im Editor öffnen. Nur
// möglich, wenn die Datei in einem Mount liegt (sonst fehlt die fileId — das
// betrifft vor allem den gebauten Ordner, der nicht eingebunden sein muss).
async function openFile(id) {
  if (!id) return
  store.close()
  await files.openFileById(id)
}
</script>

<template>
  <div v-if="store.open" class="ls-overlay">
    <header class="ls-head nemo-noselect">
      <button class="ls-back" :title="$t('common.close')" @click="store.close()">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-link-variant" size="18" class="ls-head-icon" />
      <span class="ls-head-title text-truncate">{{ $t('linkScan.title') }}</span>
    </header>

    <!-- Suchleiste: bleibt beim Blättern durch die Treffer oben stehen. -->
    <div class="ls-bar">
      <v-text-field
        ref="inputRef"
        v-model="input"
        :placeholder="$t('linkScan.placeholder')"
        prepend-inner-icon="mdi-link-variant"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        :disabled="store.running"
        @keyup.enter="start"
      />
      <v-btn
        v-if="store.running"
        variant="tonal"
        color="warning"
        prepend-icon="mdi-stop"
        @click="store.cancel()"
      >
        {{ $t('linkScan.cancel') }}
      </v-btn>
      <v-btn
        v-else
        variant="flat"
        color="primary"
        prepend-icon="mdi-magnify"
        :disabled="input.trim().length < 2"
        @click="start"
      >
        {{ $t('linkScan.search') }}
      </v-btn>
    </div>

    <v-progress-linear
      v-if="store.running"
      :model-value="store.progress"
      color="primary"
      height="3"
    />

    <div class="ls-content nemo-scroll">
      <div class="ls-inner">
        <v-alert v-if="store.error" type="error" density="compact" class="mb-3 nemo-alert">
          {{ errorText(t, store.error) }}
        </v-alert>

        <!-- Fortschritt des laufenden Segmentlaufs. Die schon gefundenen Treffer
             stehen darunter bereits zur Verfügung. -->
        <p v-if="store.running" class="ls-status">
          <v-progress-circular indeterminate size="14" width="2" class="mr-2" />
          {{ $t('linkScan.scanning', [store.cursor, store.total]) }}
        </p>

        <template v-if="store.query && !store.running">
          <v-alert
            v-if="store.cancelled"
            type="info"
            density="compact"
            variant="tonal"
            class="mb-3 nemo-alert"
          >
            {{ $t('linkScan.cancelled') }}
          </v-alert>
          <v-alert
            v-else-if="store.truncated"
            type="warning"
            density="compact"
            variant="tonal"
            class="mb-3 nemo-alert"
          >
            {{ $t('linkScan.truncated') }}
          </v-alert>
        </template>

        <!-- Noch nichts gesucht -->
        <div v-if="!store.query" class="nemo-empty">
          <v-icon icon="mdi-link-variant-plus" size="56" class="nemo-empty-icon" />
          <p>{{ $t('linkScan.empty') }}</p>
          <p class="ls-hint">{{ $t('linkScan.hint') }}</p>
        </div>

        <!-- Gesucht, aber nichts gefunden -->
        <div v-else-if="!store.matches.length && !store.running" class="nemo-empty">
          <v-icon icon="mdi-link-variant-off" size="56" class="nemo-empty-icon" />
          <p>{{ $t('linkScan.noMatches', [store.query]) }}</p>
        </div>

        <template v-else-if="store.matches.length">
          <!-- Bereichsfilter: Quellen sind das, was man bearbeitet; die
               veröffentlichten Seiten zeigen, was der Besucher wirklich sieht. -->
          <div class="ls-filter">
            <v-btn-toggle v-model="area" mandatory density="compact" variant="outlined" divided>
              <v-btn value="all" size="small">
                {{ $t('linkScan.areaAll') }} · {{ counts.all }}
              </v-btn>
              <v-btn value="content" size="small">
                {{ $t('linkScan.areaContent') }} · {{ counts.content }}
              </v-btn>
              <v-btn value="public" size="small">
                {{ $t('linkScan.areaPublic') }} · {{ counts.public }}
              </v-btn>
            </v-btn-toggle>
            <span class="ls-summary">{{ $t('linkScan.summary', [summary.hits, summary.files]) }}</span>
          </div>

          <div v-if="!sections.length" class="text-body-2 text-medium-emphasis">
            {{ $t('linkScan.noMatchesInArea') }}
          </div>

          <section v-for="s in sections" :key="s.kind" class="ls-section">
            <div class="ls-section-head">
              <v-icon :icon="s.icon" size="18" :color="s.color" class="mr-2" />
              <span class="ls-section-title">{{ $t('linkScan.kind.' + s.kind) }}</span>
              <v-chip size="x-small" variant="tonal" label class="ml-2">{{ s.groups.length }}</v-chip>
            </div>
            <p class="ls-section-hint">{{ $t('linkScan.kindHint.' + s.kind) }}</p>

            <div v-for="g in s.groups" :key="g.kind + g.link" class="ls-group">
              <button type="button" class="ls-group-head" @click="toggle(g)">
                <v-icon
                  :icon="isExpanded(g) ? 'mdi-chevron-down' : 'mdi-chevron-right'"
                  size="18"
                  class="ls-chevron"
                />
                <code class="ls-link">{{ g.link }}</code>
                <v-chip
                  v-if="g.kind === 'similar'"
                  color="warning"
                  size="x-small"
                  variant="tonal"
                  label
                  class="ml-2"
                >
                  {{ $t('linkScan.distance', [g.distance]) }}
                </v-chip>
                <v-spacer />
                <span class="ls-count">{{ $t('linkScan.hits', [g.hits.length]) }}</span>
              </button>

              <ul v-if="isExpanded(g)" class="ls-hits">
                <li v-for="(h, i) in g.hits" :key="i" class="ls-hit">
                  <v-icon
                    :icon="h.area === 'public' ? 'mdi-web' : 'mdi-file-document-outline'"
                    size="14"
                    class="mr-2 text-medium-emphasis"
                  />
                  <span class="ls-hit-file text-truncate">{{ h.file }}</span>
                  <span class="ls-hit-line">{{ $t('linkScan.line', [h.line]) }}</span>
                  <v-btn
                    v-if="h.fileId"
                    icon="mdi-file-edit-outline"
                    size="x-small"
                    variant="text"
                    density="comfortable"
                    :title="h.area === 'public' ? $t('linkScan.openBuilt') : $t('linkScan.openSource')"
                    @click="openFile(h.fileId)"
                  />
                  <!-- Fundstelle im gebauten Ordner: zusätzlich der Sprung zur
                       Hugo-Quelle — dort wird der Link wirklich korrigiert, die
                       gebaute Datei überschreibt der nächste Hugo-Lauf. Wie beim
                       SEO-Check, der ebenfalls zur Quelle führt. -->
                  <v-btn
                    v-if="h.sourceFileId"
                    icon="mdi-file-document-edit-outline"
                    size="x-small"
                    variant="text"
                    density="comfortable"
                    color="primary"
                    :title="$t('linkScan.openSourceOf', [h.sourceFile])"
                    @click="openFile(h.sourceFileId)"
                  />
                </li>
              </ul>
            </div>
          </section>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Overlay über dem Arbeitsbereich, z-index wie SEO-Audit und Warteschlange. */
.ls-overlay {
  position: absolute;
  inset: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}

.ls-head {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.ls-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 3px 6px;
  color: var(--mint-text);
  cursor: pointer;
}
.ls-back:hover { background: var(--mint-panel-hover); }
.ls-head-icon { color: var(--mint-green); }
.ls-head-title { font-weight: 600; font-size: 0.95rem; min-width: 0; }

.ls-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
}

.ls-content { flex: 1 1 auto; overflow: auto; }
.ls-inner { max-width: 960px; margin: 0 auto; padding: 16px 20px 32px; }

.ls-status {
  display: flex;
  align-items: center;
  font-size: 0.82rem;
  color: var(--mint-text-muted);
  margin-bottom: 12px;
}
.ls-hint {
  max-width: 34rem;
  text-align: center;
  font-size: 0.82rem;
  opacity: 0.75;
}

.ls-filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 16px;
}
.ls-summary { font-size: 0.82rem; color: var(--mint-text-muted); }

.ls-section { margin-bottom: 22px; }
.ls-section-head { display: flex; align-items: center; }
.ls-section-title { font-weight: 600; font-size: 0.92rem; }
.ls-section-hint {
  font-size: 0.78rem;
  opacity: 0.7;
  margin: 2px 0 8px 26px;
}

.ls-group {
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  margin-bottom: 6px;
  background: #fff;
}
.ls-group-head {
  display: flex;
  align-items: center;
  width: 100%;
  gap: 4px;
  padding: 6px 10px;
  background: transparent;
  border: 0;
  cursor: pointer;
  text-align: left;
  color: var(--mint-text);
}
.ls-group-head:hover { background: var(--mint-panel-hover); }
.ls-chevron { flex: 0 0 auto; opacity: 0.6; }
.ls-link {
  font-size: 0.82rem;
  word-break: break-all;
}
.ls-count {
  flex: 0 0 auto;
  font-size: 0.76rem;
  color: var(--mint-text-muted);
  white-space: nowrap;
}

.ls-hits {
  list-style: none;
  margin: 0;
  padding: 0 10px 6px 32px;
}
.ls-hit {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 2px 0;
  font-size: 0.8rem;
  border-top: 1px solid var(--mint-border);
}
.ls-hit-file { min-width: 0; }
.ls-hit-line { flex: 0 0 auto; color: var(--mint-text-muted); font-size: 0.74rem; }

.nemo-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 0;
  color: var(--mint-text-muted);
  gap: 8px;
}
.nemo-empty-icon { color: #c4c4c0; }
</style>
