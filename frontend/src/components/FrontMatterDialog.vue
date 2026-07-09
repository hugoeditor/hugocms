<script setup>
// Dialog beim Öffnen einer Hugo-Content-Datei mit unvollständigem Front Matter:
// zeigt die fehlenden Felder mit Vorschlagswerten und lässt sie bewusst
// übernehmen oder ignorieren. So bleibt insbesondere „draft" sichtbar (als
// Schalter) und wird nicht still gesetzt und übersehen.
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  fields: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue', 'apply'])
const { t } = useI18n()

// Eigene Kopie, damit die Bearbeitung im Dialog den Aufrufer nicht verändert.
const local = ref([])
watch(
  () => props.fields,
  (f) => {
    local.value = f.map((x) => ({ ...x }))
  },
  { immediate: true },
)

function apply() {
  emit('apply', local.value.map((x) => ({ ...x })))
  emit('update:modelValue', false)
}

// Ignorieren ist ein reiner No-op: nur schließen, Inhalt unverändert lassen.
function close() {
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="560"
    @update:model-value="(v) => { if (!v) close() }"
  >
    <v-card>
      <v-card-title class="text-h6">{{ t('frontMatterDialog.title') }}</v-card-title>
      <v-card-text>
        <p class="text-body-2 mb-4">{{ t('frontMatterDialog.hint') }}</p>

        <template v-for="field in local" :key="field.key">
          <div v-if="field.type === 'boolean'" class="fm-bool mb-3">
            <span class="fm-bool-label">{{ field.key }}</span>
            <v-switch
              v-model="field.value"
              :label="field.value ? 'true' : 'false'"
              color="primary"
              density="comfortable"
              inset
              hide-details
              class="fm-bool-switch"
            />
          </div>
          <v-textarea
            v-else-if="field.type === 'multiline'"
            v-model="field.value"
            :label="field.key"
            variant="outlined"
            density="compact"
            rows="2"
            auto-grow
            hide-details
            class="mb-3"
          />
          <v-text-field
            v-else
            v-model="field.value"
            :label="field.key"
            variant="outlined"
            density="compact"
            spellcheck="false"
            hide-details
            class="mb-3"
          />
        </template>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="close">{{ t('frontMatterDialog.ignore') }}</v-btn>
        <v-btn color="primary" variant="flat" @click="apply">{{ t('frontMatterDialog.apply') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
/* Wahrheitswert wie die Textfelder umrandet, damit der Schalter auch im
   Aus-Zustand (false) klar zu erkennen ist. */
.fm-bool {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 4px 14px;
  border: 1px solid var(--mint-border);
  border-radius: 4px;
}
.fm-bool-label {
  font-size: 0.95rem;
}
/* Die Spur des Switches im Aus-Zustand sichtbar halten (sonst nahezu weiß auf
   hellem Dialoggrund). */
.fm-bool-switch :deep(.v-switch__track) {
  opacity: 1;
  background-color: rgba(var(--v-theme-on-surface), 0.5);
}
.fm-bool-switch :deep(.v-selection-control--dirty .v-switch__track) {
  background-color: rgb(var(--v-theme-primary));
}
</style>
