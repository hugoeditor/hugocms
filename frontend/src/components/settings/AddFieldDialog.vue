<script setup>
// Dialog zum Hinzufügen eines neuen Feldes (Schlüssel + Typ). Der Aufrufer legt
// das Feld am passenden Pfad an.
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'add'])
const { t } = useI18n()

const fieldKey = ref('')
const fieldType = ref('string')

const typeOptions = computed(() => [
  { title: t('settings.fieldTypes.string'), value: 'string' },
  { title: t('settings.fieldTypes.number'), value: 'number' },
  { title: t('settings.fieldTypes.boolean'), value: 'boolean' },
  { title: t('settings.fieldTypes.array'), value: 'array' },
])

function reset() {
  fieldKey.value = ''
  fieldType.value = 'string'
}
function onAdd() {
  if (!fieldKey.value.trim()) return
  emit('add', fieldKey.value.trim(), fieldType.value)
  reset()
  emit('update:modelValue', false)
}
function onCancel() {
  reset()
  emit('update:modelValue', false)
}
watch(() => props.modelValue, (open) => { if (!open) reset() })
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="440" @update:model-value="emit('update:modelValue', $event)">
    <v-card>
      <v-card-title>{{ t('settings.addField') }}</v-card-title>
      <v-card-text>
        <v-text-field
          v-model="fieldKey"
          :label="t('settings.fieldKey')"
          variant="outlined"
          density="comfortable"
          autofocus
          class="mb-3"
          @keydown.enter="onAdd"
        />
        <v-select
          v-model="fieldType"
          :items="typeOptions"
          :label="t('settings.fieldType')"
          variant="outlined"
          density="comfortable"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="onCancel">{{ t('common.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :disabled="!fieldKey.trim()" @click="onAdd">
          {{ t('common.create') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
