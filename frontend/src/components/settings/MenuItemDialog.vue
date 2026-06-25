<script setup>
// Dialog zum Anlegen/Bearbeiten eines Menüeintrags (identifier/name/url/weight).
// Etwaige weitere Felder eines Eintrags bleiben beim Speichern erhalten.
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  item: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'save'])
const { t } = useI18n()

const form = ref({ identifier: '', name: '', url: '', weight: 0 })
let extra = {} // weitere Felder des Eintrags, die der Dialog nicht zeigt

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    if (props.item) {
      const { identifier = '', name = '', url = '', weight = 0, ...rest } = props.item
      form.value = { identifier, name, url, weight }
      extra = rest
    } else {
      form.value = { identifier: '', name: '', url: '', weight: 0 }
      extra = {}
    }
  },
)

function onSave() {
  if (!form.value.identifier.trim() || !form.value.name.trim()) return
  emit('save', { ...extra, ...form.value })
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="500" @update:model-value="emit('update:modelValue', $event)">
    <v-card>
      <v-card-title>{{ item ? t('settings.menu.editItem') : t('settings.menu.addItem') }}</v-card-title>
      <v-card-text>
        <v-text-field
          v-model="form.identifier"
          :label="t('settings.menu.identifier')"
          variant="outlined"
          density="comfortable"
          class="mb-3"
        />
        <v-text-field
          v-model="form.name"
          :label="t('settings.menu.name')"
          variant="outlined"
          density="comfortable"
          class="mb-3"
        />
        <v-text-field
          v-model="form.url"
          :label="t('settings.menu.url')"
          variant="outlined"
          density="comfortable"
          class="mb-3"
        />
        <v-text-field
          v-model.number="form.weight"
          :label="t('settings.menu.weight')"
          type="number"
          variant="outlined"
          density="comfortable"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!form.identifier.trim() || !form.name.trim()"
          @click="onSave"
        >
          {{ t('common.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
