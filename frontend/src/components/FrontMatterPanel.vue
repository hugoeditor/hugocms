<script setup>
// Strukturierter Editor für das Front-Matter (YAML) einer Markdown-Datei.
//
// Vorbild ist das alte HugoCMS, das die Metadaten als reine Key-Value-Tabelle
// aus Text-Inputs zeigte. Diese Fassung verbessert die Bedienung deutlich:
// jede Variable bekommt ein zu ihrem Typ passendes Eingabefeld (Schalter für
// Wahrheitswerte, Chips für Listen, Datumswähler, Zahlenfeld), und der robuste
// yaml-Parser ersetzt das fehleranfällige Zerlegen nach Doppelpunkten.
//
// Die Komponente erhält den rohen Front-Matter-Block samt --- Zeilen über
// modelValue und meldet jede Änderung als neuen rohen Block zurück. Das
// Zusammensetzen von Body und Block bleibt damit Sache des EditorPanels.
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { parse, stringify } from 'yaml'

const props = defineProps({
  modelValue: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

// --- Feldmodell ------------------------------------------------------------
// Jede Variable ist ein Feld { id, key, type, value }. Die Reihenfolge bleibt
// erhalten; neue Felder werden angehängt.
const TYPES = ['text', 'multiline', 'number', 'boolean', 'date', 'list', 'raw']

const TYPE_ICON = {
  text: 'mdi-format-text',
  multiline: 'mdi-text-long',
  number: 'mdi-numeric',
  boolean: 'mdi-toggle-switch-outline',
  date: 'mdi-calendar',
  list: 'mdi-tag-multiple-outline',
  raw: 'mdi-code-braces',
}

// Schlüssel, deren Wert als Datum behandelt wird, auch ohne erkennbares Muster.
const DATE_KEYS = new Set(['date', 'lastmod', 'publishdate', 'expirydate'])
const ISO_DATE = /^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2})?/

// Gängige Hugo-Variablen für das Hinzufügen-Menü (ersetzt die serverseitige
// Vorlage des alten HugoCMS — diese Schlüssel sind in Hugo universell).
const COMMON = [
  { key: 'title', type: 'text' },
  { key: 'description', type: 'multiline' },
  { key: 'date', type: 'date' },
  { key: 'lastmod', type: 'date' },
  { key: 'draft', type: 'boolean' },
  { key: 'weight', type: 'number' },
  { key: 'tags', type: 'list' },
  { key: 'categories', type: 'list' },
  { key: 'keywords', type: 'list' },
  { key: 'aliases', type: 'list' },
  { key: 'slug', type: 'text' },
  { key: 'author', type: 'text' },
  { key: 'summary', type: 'multiline' },
]

let _uid = 0
const uid = () => `fm${++_uid}`

const fields = ref([])
const bodyRef = ref(null) // Scroll-Container des Panel-Inhalts
const rawMode = ref(false)
const rawDraft = ref('') // innerer YAML-Text (ohne --- Zeilen)
const rawError = ref(false)
const open = ref(readOpen())

// Letzter selbst gemeldeter Block — verhindert, dass die eigene Meldung als
// externe Änderung neu eingelesen wird (und dabei den Fokus verliert).
let lastEmitted = null

const OPEN_KEY = 'hugocms_fm_open'
function readOpen() {
  try {
    return localStorage.getItem(OPEN_KEY) !== 'false'
  } catch {
    return true
  }
}
watch(open, (v) => {
  try {
    localStorage.setItem(OPEN_KEY, String(v))
  } catch {
    // ohne lokalen Speicher unkritisch
  }
})

// --- Datum -----------------------------------------------------------------
function hugoNow() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  const tz = -d.getTimezoneOffset()
  const sign = tz >= 0 ? '+' : '-'
  const abs = Math.abs(tz)
  return (
    `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}` +
    `T${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}` +
    `${sign}${p(Math.floor(abs / 60))}:${p(abs % 60)}`
  )
}

// --- Parsen ----------------------------------------------------------------
function innerOf(block) {
  const m = block.match(/^---\r?\n([\s\S]*?)\r?\n---(\r?\n|$)/)
  return m ? m[1] : block.replace(/^---\r?\n?/, '').replace(/\r?\n---\r?\n?$/, '')
}

function detectType(key, value) {
  if (typeof value === 'boolean') return 'boolean'
  if (typeof value === 'number') return 'number'
  if (Array.isArray(value)) {
    return value.every((x) => typeof x === 'string' || typeof x === 'number') ? 'list' : 'raw'
  }
  if (value && typeof value === 'object') return 'raw'
  if (typeof value === 'string') {
    if (value.includes('\n')) return 'multiline'
    if (DATE_KEYS.has(key.toLowerCase()) || ISO_DATE.test(value)) return 'date'
    return 'text'
  }
  return 'text'
}

function toField(key, value) {
  const type = detectType(key, value)
  let v
  if (type === 'list') v = value.map(String)
  else if (type === 'raw') v = stringify(value, { lineWidth: 0 }).trimEnd()
  else if (type === 'boolean') v = !!value
  else if (type === 'number') v = value
  else v = value == null ? '' : String(value)
  return { id: uid(), key, type, value: v }
}

function loadFrom(block) {
  const inner = innerOf(block ?? '')
  rawDraft.value = inner
  if (!inner.trim()) {
    fields.value = []
    rawMode.value = false
    rawError.value = false
    return
  }
  try {
    const data = parse(inner)
    if (data == null || typeof data !== 'object' || Array.isArray(data)) {
      throw new Error('kein Objekt')
    }
    fields.value = Object.entries(data).map(([k, v]) => toField(k, v))
    rawMode.value = false
    rawError.value = false
  } catch {
    // Unlesbares oder verschachteltes Front-Matter nicht strukturiert
    // zerlegen, sondern als Rohtext anbieten — so kann nichts beschädigt
    // werden, was wir nicht sauber wieder zusammensetzen können.
    rawMode.value = true
    rawError.value = false
  }
}

loadFrom(props.modelValue)

watch(
  () => props.modelValue,
  (val) => {
    if (val === lastEmitted) return // eigene Meldung
    loadFrom(val)
  },
)

// --- Zusammensetzen --------------------------------------------------------
function fieldToValue(f) {
  switch (f.type) {
    case 'boolean':
      return !!f.value
    case 'number': {
      const n = Number(f.value)
      return Number.isFinite(n) ? n : 0
    }
    case 'list':
      return Array.isArray(f.value) ? f.value.filter((x) => x !== '' && x != null) : []
    case 'raw':
      try {
        return parse(String(f.value))
      } catch {
        return String(f.value)
      }
    default:
      return f.value == null ? '' : f.value
  }
}

function buildInner(list) {
  const obj = {}
  for (const f of list) {
    const key = f.key.trim()
    if (!key) continue
    obj[key] = fieldToValue(f)
  }
  if (!Object.keys(obj).length) return ''
  return stringify(obj, { lineWidth: 0 })
}

function emitBlock(inner) {
  const block = inner.trim() ? `---\n${inner.replace(/\n*$/, '\n')}---\n` : ''
  lastEmitted = block
  emit('update:modelValue', block)
}

// Nach jeder strukturierten Änderung aufrufen.
function commit() {
  emitBlock(buildInner(fields.value))
}

// --- Bedienung -------------------------------------------------------------
const duplicateKeys = computed(() => {
  const seen = new Set()
  const dup = new Set()
  for (const f of fields.value) {
    const k = f.key.trim()
    if (!k) continue
    if (seen.has(k)) dup.add(k)
    seen.add(k)
  }
  return dup
})

const presentKeys = computed(() => new Set(fields.value.map((f) => f.key.trim())))
const availableCommon = computed(() => COMMON.filter((c) => !presentKeys.value.has(c.key)))

const summary = computed(() => {
  const keys = fields.value.map((f) => f.key.trim()).filter(Boolean)
  if (!keys.length) return ''
  const shown = keys.slice(0, 4).join(', ')
  return keys.length > 4 ? `${shown} +${keys.length - 4}` : shown
})

function defaultValue(type) {
  switch (type) {
    case 'boolean':
      return false
    case 'number':
      return 0
    case 'list':
      return []
    case 'date':
      return hugoNow()
    default:
      return ''
  }
}

function addField(key = '', type = 'text') {
  fields.value.push({ id: uid(), key, type, value: defaultValue(type) })
  open.value = true
  commit()
  // Neue Zeile sichtbar machen: bei vielen Feldern liegt sie sonst außerhalb
  // des scrollbaren Bereichs. Nach dem Rendern ans Ende scrollen und passend
  // fokussieren: bei vorbelegtem Namen (gängiges Feld) direkt ins Wertfeld,
  // sofern der Typ ein Textfeld hat; sonst ins Schlüsselfeld (eigene Variable).
  nextTick(() => {
    const el = bodyRef.value
    if (!el) return
    el.scrollTop = el.scrollHeight
    const row = el.querySelector('.fm-row:last-child')
    if (!row) return
    const target =
      key !== '' && ['text', 'multiline', 'number', 'date'].includes(type)
        ? row.querySelector('.fm-value input, .fm-value textarea')
        : row.querySelector('.fm-key input')
    target?.focus()
  })
}

function removeField(id) {
  fields.value = fields.value.filter((f) => f.id !== id)
  commit()
}

function changeType(field, type) {
  if (field.type === type) return
  field.value = convertValue(field.value, field.type, type)
  field.type = type
  commit()
}

function convertValue(value, from, to) {
  if (to === 'boolean') return value === true || value === 'true' || value === 1
  if (to === 'number') {
    const n = Number(Array.isArray(value) ? value[0] : value)
    return Number.isFinite(n) ? n : 0
  }
  if (to === 'list') {
    if (Array.isArray(value)) return value.map(String)
    const s = String(value ?? '').trim()
    return s ? s.split(',').map((x) => x.trim()).filter(Boolean) : []
  }
  if (to === 'date') {
    const s = String(value ?? '').trim()
    return ISO_DATE.test(s) ? s : hugoNow()
  }
  // text / multiline / raw
  if (Array.isArray(value)) return value.join(', ')
  if (value && typeof value === 'object') return stringify(value, { lineWidth: 0 }).trimEnd()
  return String(value ?? '')
}

// Datum aus dem Kalender übernehmen, vorhandene Uhrzeit beibehalten.
function applyPickedDate(field, picked) {
  if (!picked) return
  const d = Array.isArray(picked) ? picked[0] : picked
  const p = (n) => String(n).padStart(2, '0')
  const datePart = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
  const time = String(field.value || '').match(/[T ](\d{2}:\d{2}\S*)/)
  field.value = time ? `${datePart}T${time[1]}` : datePart
  commit()
}

// Nur für die beiden suchmaschinenrelevanten Felder einen Zähler einblenden.
function seoLimit(key) {
  const k = key.trim().toLowerCase()
  if (k === 'title') return 60
  if (k === 'description') return 160
  return undefined
}

// --- Roh-Modus -------------------------------------------------------------
function toggleRaw() {
  if (rawMode.value) {
    // Zurück zur strukturierten Ansicht — nur wenn der Rohtext gültig ist.
    try {
      const data = rawDraft.value.trim() ? parse(rawDraft.value) : {}
      if (data == null || typeof data !== 'object' || Array.isArray(data)) {
        throw new Error('kein Objekt')
      }
      fields.value = Object.entries(data).map(([k, v]) => toField(k, v))
      rawMode.value = false
      rawError.value = false
    } catch {
      rawError.value = true
    }
  } else {
    rawDraft.value = buildInner(fields.value)
    rawMode.value = true
  }
}

function onRawInput(text) {
  rawDraft.value = text
  try {
    if (text.trim()) parse(text)
    rawError.value = false
  } catch {
    rawError.value = true
  }
  emitBlock(text)
}
</script>

<template>
  <section class="fm-panel">
    <header class="fm-head nemo-noselect">
      <!-- Nur der Titelbereich klappt ein/aus — so lösen Fehlklicks neben den
           Knöpfen rechts nicht versehentlich das Einklappen aus. -->
      <button type="button" class="fm-head-toggle" @click="open = !open">
        <v-icon :icon="open ? 'mdi-menu-down' : 'mdi-menu-right'" size="20" class="fm-caret" />
        <v-icon icon="mdi-tune-variant" size="16" class="mr-1" />
        <span class="fm-title">{{ t('frontMatter.title') }}</span>
        <span v-if="!open && summary" class="fm-summary">{{ summary }}</span>
      </button>

      <v-spacer />

      <v-tooltip :text="rawMode ? t('frontMatter.structuredMode') : t('frontMatter.rawMode')" location="bottom">
        <template #activator="{ props: tip }">
          <v-btn
            v-bind="tip"
            :icon="rawMode ? 'mdi-table' : 'mdi-code-braces'"
            size="small"
            variant="text"
            density="comfortable"
            :disabled="!open"
            @click.stop="toggleRaw"
          />
        </template>
      </v-tooltip>

      <v-menu v-if="!rawMode" :disabled="!open">
        <template #activator="{ props: menu }">
          <v-btn
            v-bind="menu"
            size="small"
            variant="tonal"
            color="primary"
            density="comfortable"
            prepend-icon="mdi-plus"
            class="ml-1"
            :disabled="!open"
            @click.stop
          >
            {{ t('frontMatter.add') }}
          </v-btn>
        </template>
        <v-list density="compact" min-width="200">
          <v-list-subheader>{{ t('frontMatter.commonFields') }}</v-list-subheader>
          <v-list-item
            v-for="c in availableCommon"
            :key="c.key"
            :prepend-icon="TYPE_ICON[c.type]"
            :title="c.key"
            @click="addField(c.key, c.type)"
          />
          <v-divider class="my-1" />
          <v-list-item
            prepend-icon="mdi-pencil-plus"
            :title="t('frontMatter.customField')"
            @click="addField('', 'text')"
          />
        </v-list>
      </v-menu>
    </header>

    <div v-show="open" ref="bodyRef" class="fm-body nemo-scroll">
      <!-- Roh-Modus: ganzer Block als YAML-Text (Notausgang / verschachtelte Daten) -->
      <template v-if="rawMode">
        <v-textarea
          :model-value="rawDraft"
          :label="t('frontMatter.rawLabel')"
          :error="rawError"
          :messages="rawError ? t('frontMatter.rawInvalid') : ''"
          variant="outlined"
          density="compact"
          rows="5"
          auto-grow
          spellcheck="false"
          class="fm-mono"
          hide-details="auto"
          @update:model-value="onRawInput"
        />
      </template>

      <!-- Strukturierte Ansicht -->
      <template v-else>
        <p v-if="!fields.length" class="fm-empty">{{ t('frontMatter.empty') }}</p>

        <div v-for="field in fields" :key="field.id" class="fm-row">
          <!-- Typ wählen -->
          <v-menu>
            <template #activator="{ props: menu }">
              <v-btn
                v-bind="menu"
                :icon="TYPE_ICON[field.type]"
                size="small"
                variant="text"
                density="comfortable"
                class="fm-type"
              />
            </template>
            <v-list density="compact" min-width="170">
              <v-list-subheader>{{ t('frontMatter.fieldType') }}</v-list-subheader>
              <v-list-item
                v-for="ty in TYPES"
                :key="ty"
                :prepend-icon="TYPE_ICON[ty]"
                :title="t(`frontMatter.types.${ty}`)"
                :active="field.type === ty"
                @click="changeType(field, ty)"
              />
            </v-list>
          </v-menu>

          <!-- Schlüssel -->
          <v-text-field
            v-model="field.key"
            :placeholder="t('frontMatter.keyPlaceholder')"
            :error="duplicateKeys.has(field.key.trim())"
            variant="plain"
            density="compact"
            hide-details
            class="fm-key"
            spellcheck="false"
            @update:model-value="commit"
          />

          <!-- Wert: passend zum Typ -->
          <div class="fm-value">
            <v-switch
              v-if="field.type === 'boolean'"
              :model-value="field.value"
              :label="field.value ? 'true' : 'false'"
              color="primary"
              density="compact"
              inset
              hide-details
              class="fm-switch"
              @update:model-value="(v) => { field.value = !!v; commit() }"
            />

            <v-combobox
              v-else-if="field.type === 'list'"
              :model-value="field.value"
              :placeholder="t('frontMatter.listPlaceholder')"
              multiple
              chips
              closable-chips
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="(v) => { field.value = v; commit() }"
            />

            <v-text-field
              v-else-if="field.type === 'number'"
              :model-value="field.value"
              type="number"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="(v) => { field.value = v; commit() }"
            />

            <v-text-field
              v-else-if="field.type === 'date'"
              :model-value="field.value"
              variant="outlined"
              density="compact"
              hide-details
              spellcheck="false"
              @update:model-value="(v) => { field.value = v; commit() }"
            >
              <template #append-inner>
                <v-tooltip :text="t('frontMatter.now')" location="bottom">
                  <template #activator="{ props: tip }">
                    <v-icon
                      v-bind="tip"
                      icon="mdi-clock-outline"
                      size="18"
                      class="fm-date-icon"
                      @click="() => { field.value = hugoNow(); commit() }"
                    />
                  </template>
                </v-tooltip>
                <v-menu :close-on-content-click="false">
                  <template #activator="{ props: menu }">
                    <v-icon v-bind="menu" icon="mdi-calendar" size="18" class="fm-date-icon ml-1" />
                  </template>
                  <v-date-picker
                    hide-header
                    @update:model-value="(d) => applyPickedDate(field, d)"
                  />
                </v-menu>
              </template>
            </v-text-field>

            <v-textarea
              v-else-if="field.type === 'multiline' || field.type === 'raw'"
              :model-value="field.value"
              :class="{ 'fm-mono': field.type === 'raw' }"
              variant="outlined"
              density="compact"
              rows="2"
              auto-grow
              spellcheck="false"
              :counter="seoLimit(field.key)"
              hide-details="auto"
              @update:model-value="(v) => { field.value = v; commit() }"
            />

            <v-text-field
              v-else
              :model-value="field.value"
              variant="outlined"
              density="compact"
              spellcheck="false"
              :counter="seoLimit(field.key)"
              hide-details="auto"
              @update:model-value="(v) => { field.value = v; commit() }"
            />
          </div>

          <!-- Entfernen -->
          <v-tooltip :text="t('frontMatter.remove')" location="bottom">
            <template #activator="{ props: tip }">
              <v-btn
                v-bind="tip"
                icon="mdi-close"
                size="x-small"
                variant="text"
                density="comfortable"
                class="fm-remove"
                @click="removeField(field.id)"
              />
            </template>
          </v-tooltip>
        </div>
      </template>
    </div>
  </section>
</template>

<style scoped>
.fm-panel {
  flex: 0 0 auto;
  border-bottom: 1px solid var(--mint-border);
  background: var(--mint-panel);
}

.fm-head {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 8px 4px 6px;
  color: var(--mint-text-muted);
}
/* Klickbarer Ein-/Ausklapp-Bereich: nur Titel und Zusammenfassung, damit der
   leere Raum neben den Knöpfen rechts nicht togglet. */
.fm-head-toggle {
  display: flex;
  align-items: center;
  gap: 2px;
  min-width: 0;
  padding: 2px 4px;
  margin: -2px -4px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.fm-head-toggle:hover .fm-title {
  color: var(--mint-green);
}
.fm-caret {
  color: var(--mint-text-muted);
}
.fm-title {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--mint-text);
}
.fm-summary {
  margin-left: 10px;
  font-size: 0.78rem;
  font-weight: 400;
  color: var(--mint-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 50%;
}

.fm-body {
  max-height: 38vh;
  overflow-y: auto;
  padding: 6px 10px 10px;
  background: var(--mint-content);
  border-top: 1px solid var(--mint-border);
}

.fm-empty {
  margin: 6px 4px;
  font-size: 0.82rem;
  color: var(--mint-text-muted);
}

.fm-row {
  display: flex;
  align-items: flex-start;
  gap: 4px;
  padding: 3px 0;
}
/* Handy: Schlüssel und Wert untereinander statt nebeneinander. */
@media (max-width: 599.98px) {
  .fm-row { flex-wrap: wrap; }
  .fm-key { flex: 1 1 auto; }
  .fm-value { flex: 1 1 100%; order: 3; }
}
.fm-type {
  flex: 0 0 auto;
  margin-top: 2px;
  color: var(--mint-text-muted);
}
.fm-key {
  flex: 0 0 168px;
  font-weight: 600;
}
.fm-key :deep(input) {
  font-weight: 600;
  font-size: 0.86rem;
}
.fm-value {
  flex: 1 1 auto;
  min-width: 0;
}
.fm-remove {
  flex: 0 0 auto;
  margin-top: 4px;
  color: var(--mint-text-muted);
}
.fm-date-icon {
  cursor: pointer;
  color: var(--mint-text-muted);
}
.fm-date-icon:hover {
  color: var(--mint-green);
}

.fm-mono :deep(textarea),
.fm-mono :deep(input) {
  font-family: monospace;
  font-size: 0.82rem;
}

/* Die Spur des Switches auch im Aus-Zustand (false) sichtbar halten — sonst
   nahezu weiß auf hellem Grund und damit unsichtbar. */
.fm-switch :deep(.v-switch__track) {
  opacity: 1;
  background-color: rgba(var(--v-theme-on-surface), 0.5);
}
.fm-switch :deep(.v-selection-control--dirty .v-switch__track) {
  background-color: rgb(var(--v-theme-primary));
}
</style>
