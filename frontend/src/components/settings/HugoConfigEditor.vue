<script setup>
// Visueller Editor für die Hugo-Konfiguration (hugo.json/config.json).
//
// Eingebettet als „Visuell“-Modus des EditorPanels: erhält den rohen Text über
// modelValue und meldet Änderungen als neuen Text zurück (wie JsonEditor zuvor),
// damit Speichern/Konflikt/KI-Assistent unverändert greifen. „Formatierung
// beibehalten“ über den jsonFormat-Serialisierer (erkannte Einrückung +
// Abschluss-Zeilenumbruch). Eigene Meldungen werden über Textgleichheit erkannt.
//
// Aufbau (Akkordeon): bekannte Skalar-Felder in Sektionen → params (flach) →
// Menüs → „Weitere Einstellungen“ (rekursiver Baum für strukturierte/unbekannte
// Blöcke) → „Gesamtes JSON (Roh)“ als Auffangebene. Nichts geht verloren.
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  PARAMS_KEY,
  MENU_KEYS,
  KNOWN_SCALAR_KEYS,
  STRUCTURED_KEYS,
  sortedSections,
  sortedStructured,
  sortedFields,
  camelToLabel,
  getByPath,
  setByPath,
  deleteByPath,
  isComplexValue,
} from '../../config/hugoFieldMeta'
import { detectIndent, serializeJson } from '../../util/jsonFormat'
import ConfigField from './ConfigField.vue'
import JsonTreeField from './JsonTreeField.vue'
import MenuEditor from './MenuEditor.vue'
import AddFieldDialog from './AddFieldDialog.vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  saveDisabled: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'save'])
const { t } = useI18n()

const draft = ref({})
let indent = 2
let trailingNl = true
let lastEmitted = null

function load(text) {
  indent = detectIndent(text)
  trailingNl = text.endsWith('\n')
  try {
    const parsed = JSON.parse(text)
    draft.value = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
  } catch {
    draft.value = {}
  }
}

// --- Undo/Redo -------------------------------------------------------------
// Verlauf als Folge serialisierter Textzustände (der Quelltext ist die
// kanonische Form). Schnell aufeinanderfolgende Tipp-Änderungen werden zu einem
// Schritt zusammengefasst, damit Rückgängig nicht zeichenweise springt.
const MAX_HISTORY = 100
const COALESCE_MS = 500
const history = ref([])
const histIndex = ref(-1)
let lastRecordTime = 0

function resetHistory(text) {
  history.value = [text]
  histIndex.value = 0
  lastRecordTime = 0
}

function record(text) {
  if (text === history.value[histIndex.value]) return
  const now = Date.now()
  const coalesce = now - lastRecordTime < COALESCE_MS && histIndex.value >= 0
  lastRecordTime = now
  if (coalesce) {
    history.value[histIndex.value] = text
    return
  }
  // Etwaigen Redo-Zweig verwerfen, neuen Zustand anhängen.
  history.value = history.value.slice(0, histIndex.value + 1)
  history.value.push(text)
  if (history.value.length > MAX_HISTORY) history.value.shift()
  histIndex.value = history.value.length - 1
}

const canUndo = computed(() => histIndex.value > 0)
const canRedo = computed(() => histIndex.value < history.value.length - 1)

function applyState(text) {
  load(text)
  lastEmitted = text
  emit('update:modelValue', text)
}
function undo() {
  if (!canUndo.value) return
  histIndex.value -= 1
  lastRecordTime = 0 // nächste Eingabe beginnt einen neuen Schritt
  applyState(history.value[histIndex.value])
}
function redo() {
  if (!canRedo.value) return
  histIndex.value += 1
  lastRecordTime = 0
  applyState(history.value[histIndex.value])
}

load(props.modelValue)
resetHistory(props.modelValue)

watch(
  () => props.modelValue,
  (v) => {
    if (v === lastEmitted) return
    load(v)
    resetHistory(v) // externe Änderung (z. B. Neuladen) beginnt einen frischen Verlauf
  },
)

function emitChange() {
  const text = serializeJson(draft.value, indent, trailingNl)
  lastEmitted = text
  record(text)
  emit('update:modelValue', text)
}

// Tastatur: Strg/Cmd+Z rückgängig, Strg/Cmd+Y bzw. Strg/Cmd+Umschalt+Z wiederholen.
function onKeydown(e) {
  if (!(e.ctrlKey || e.metaKey)) return
  const key = e.key.toLowerCase()
  if (key === 'z' && !e.shiftKey) {
    e.preventDefault()
    undo()
  } else if (key === 'y' || (key === 'z' && e.shiftKey)) {
    e.preventDefault()
    redo()
  }
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

// --- Bekannte Sektionen ----------------------------------------------------
const visibleSections = computed(() =>
  sortedSections().filter(([, section]) =>
    sortedFields(section).some(([key]) => key in draft.value),
  ),
)
function sectionFields(section) {
  return sortedFields(section).filter(([key]) => key in draft.value)
}

// --- Strukturierte Standard-Blöcke (ganzer Block als Baum) -----------------
const structuredSections = computed(() =>
  sortedStructured().filter(([key]) => key in draft.value),
)

// --- params ----------------------------------------------------------------
const hasParams = computed(() => {
  const p = draft.value[PARAMS_KEY]
  return p && typeof p === 'object' && !Array.isArray(p)
})
const paramKeys = computed(() => (hasParams.value ? Object.keys(draft.value[PARAMS_KEY]) : []))

// --- Menüs -----------------------------------------------------------------
const menuRootKey = computed(() =>
  MENU_KEYS.find((k) => draft.value[k] && typeof draft.value[k] === 'object' && !Array.isArray(draft.value[k])),
)
const menuKeys = computed(() => (menuRootKey.value ? Object.keys(draft.value[menuRootKey.value]) : []))
function menuItems(key) {
  const items = draft.value[menuRootKey.value]?.[key]
  return Array.isArray(items) ? items : []
}
function updateMenu(key, items) {
  const root = menuRootKey.value || 'menu'
  if (!draft.value[root] || typeof draft.value[root] !== 'object') draft.value[root] = {}
  draft.value[root][key] = items
  emitChange()
}

// --- Weitere (strukturierte/unbekannte) Wurzelschlüssel --------------------
const otherKeys = computed(() =>
  Object.keys(draft.value).filter(
    (k) =>
      !KNOWN_SCALAR_KEYS.has(k) &&
      !STRUCTURED_KEYS.has(k) &&
      k !== PARAMS_KEY &&
      k !== menuRootKey.value,
  ),
)

// --- Bearbeiten ------------------------------------------------------------
function updateField(path, value) {
  setByPath(draft.value, path, value)
  emitChange()
}
function removeField(path) {
  deleteByPath(draft.value, path)
  emitChange()
}

// --- Feld hinzufügen -------------------------------------------------------
const addDialogOpen = ref(false)
const addPrefix = ref('')
function openAdd(prefix) {
  addPrefix.value = prefix
  addDialogOpen.value = true
}
function onAddField(key, type) {
  const defaults = { string: '', number: 0, boolean: false, array: [] }
  setByPath(draft.value, `${addPrefix.value}${key}`, defaults[type])
  emitChange()
}

const expandedPanels = ref(['siteBasics'])
</script>

<template>
  <div class="hugo-config d-flex flex-column">
    <div class="d-flex align-center border-b px-2 py-1 ga-2">
      <v-btn
        color="primary"
        size="small"
        variant="flat"
        prepend-icon="mdi-content-save"
        :disabled="saveDisabled"
        :loading="saving"
        @click="emit('save')"
      >
        {{ t('editor.save') }}
      </v-btn>
      <v-divider vertical class="mx-1 align-self-center" style="height: 20px" />
      <v-btn icon="mdi-undo" size="small" variant="text" density="comfortable" :disabled="!canUndo" :title="t('editor.undo')" @click="undo" />
      <v-btn icon="mdi-redo" size="small" variant="text" density="comfortable" :disabled="!canRedo" :title="t('editor.redo')" @click="redo" />
      <v-spacer />
      <span class="text-caption text-medium-emphasis">{{ t('settings.hugoConfig') }}</span>
    </div>

    <div class="hugo-scroll flex-grow-1 pa-3 nemo-scroll">
      <v-expansion-panels v-model="expandedPanels" multiple>
        <!-- Bekannte Skalar-Felder in Sektionen -->
        <v-expansion-panel
          v-for="[sectionKey, section] in visibleSections"
          :key="sectionKey"
          :value="sectionKey"
        >
          <v-expansion-panel-title>{{ t(`settings.sections.${section.i18nKey}`) }}</v-expansion-panel-title>
          <v-expansion-panel-text>
            <ConfigField
              v-for="[fkey, meta] in sectionFields(section)"
              :key="fkey"
              :field-key="fkey"
              :i18n-key="meta.i18nKey"
              :model-value="getByPath(draft, fkey)"
              removable
              @update:model-value="updateField(fkey, $event)"
              @remove="removeField(fkey)"
            />
            <v-btn size="small" variant="text" color="primary" prepend-icon="mdi-plus" class="mt-1" @click="openAdd('')">
              {{ t('settings.addField') }}
            </v-btn>
          </v-expansion-panel-text>
        </v-expansion-panel>

        <!-- params: flache Schlüssel/Wert-Liste -->
        <v-expansion-panel v-if="hasParams" :value="PARAMS_KEY">
          <v-expansion-panel-title>{{ t('settings.sections.params') }}</v-expansion-panel-title>
          <v-expansion-panel-text>
            <template v-for="k in paramKeys" :key="k">
              <div v-if="isComplexValue(getByPath(draft, `params.${k}`))" class="mb-4">
                <div class="d-flex align-center mb-1">
                  <span class="text-subtitle-2">{{ k }}</span>
                  <v-spacer />
                  <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" :title="t('common.remove')" @click="removeField(`params.${k}`)" />
                </div>
                <div class="json-block">
                  <JsonTreeField :model-value="getByPath(draft, `params.${k}`)" @update:model-value="updateField(`params.${k}`, $event)" />
                </div>
              </div>
              <ConfigField
                v-else
                :field-key="k"
                i18n-key=""
                :model-value="getByPath(draft, `params.${k}`)"
                removable
                @update:model-value="updateField(`params.${k}`, $event)"
                @remove="removeField(`params.${k}`)"
              />
            </template>
            <v-btn size="small" variant="text" color="primary" prepend-icon="mdi-plus" class="mt-1" @click="openAdd('params.')">
              {{ t('settings.addField') }}
            </v-btn>
          </v-expansion-panel-text>
        </v-expansion-panel>

        <!-- Strukturierte Standard-Blöcke (markup, redirects, taxonomies …) als Baum -->
        <v-expansion-panel
          v-for="[skey, smeta] in structuredSections"
          :key="`struct:${skey}`"
          :value="`struct:${skey}`"
        >
          <v-expansion-panel-title>{{ t(`settings.structured.${smeta.i18nKey}`) }}</v-expansion-panel-title>
          <v-expansion-panel-text>
            <div class="json-block">
              <JsonTreeField :model-value="getByPath(draft, skey)" @update:model-value="updateField(skey, $event)" />
            </div>
          </v-expansion-panel-text>
        </v-expansion-panel>

        <!-- Menüs -->
        <v-expansion-panel v-if="menuKeys.length > 0" value="menus">
          <v-expansion-panel-title>{{ t('settings.sections.menus') }}</v-expansion-panel-title>
          <v-expansion-panel-text>
            <MenuEditor
              v-for="mk in menuKeys"
              :key="mk"
              :menu-name="camelToLabel(mk)"
              :model-value="menuItems(mk)"
              class="mb-4"
              @update:model-value="updateMenu(mk, $event)"
            />
          </v-expansion-panel-text>
        </v-expansion-panel>

        <!-- Weitere Einstellungen: strukturierte/unbekannte Blöcke als Baum -->
        <v-expansion-panel v-if="otherKeys.length > 0" value="other">
          <v-expansion-panel-title>{{ t('common.other') }}</v-expansion-panel-title>
          <v-expansion-panel-text>
            <template v-for="k in otherKeys" :key="k">
              <div v-if="isComplexValue(getByPath(draft, k))" class="mb-4">
                <div class="d-flex align-center mb-1">
                  <span class="text-subtitle-2">{{ k }}</span>
                  <v-spacer />
                  <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" :title="t('common.remove')" @click="removeField(k)" />
                </div>
                <div class="json-block">
                  <JsonTreeField :model-value="getByPath(draft, k)" @update:model-value="updateField(k, $event)" />
                </div>
              </div>
              <ConfigField
                v-else
                :field-key="k"
                i18n-key=""
                :model-value="getByPath(draft, k)"
                removable
                @update:model-value="updateField(k, $event)"
                @remove="removeField(k)"
              />
            </template>
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>
    </div>

    <AddFieldDialog v-model="addDialogOpen" @add="onAddField" />
  </div>
</template>

<style scoped>
.hugo-config {
  height: 100%;
  min-height: 0;
}
.hugo-scroll {
  overflow: auto;
}
/* Eingebetteter Baum etwas abgesetzt. */
.json-block {
  border-left: 2px solid var(--mint-border);
  padding-left: 8px;
}
</style>
