<script setup>
// Ein einzelnes, typisiertes Konfigurationsfeld. Der Typ wird aus dem Wert
// abgeleitet (Schalter/Zahl/Chips/Text). Beschriftung kommt aus der i18n
// (settings.fields.<i18nKey>.label) mit Rückfall auf camelToLabel.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { camelToLabel } from '../../config/hugoFieldMeta'

const props = defineProps({
  fieldKey: { type: String, required: true }, // Blattname, z. B. 'logoURL'
  i18nKey: { type: String, default: '' }, // Schlüssel unter settings.fields, leer = unbekannt
  modelValue: { default: undefined },
  removable: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'remove'])
const { t, te } = useI18n()

const label = computed(() => {
  if (props.i18nKey && te(`settings.fields.${props.i18nKey}.label`)) {
    return t(`settings.fields.${props.i18nKey}.label`)
  }
  return camelToLabel(props.fieldKey)
})
const hint = computed(() => {
  if (props.i18nKey && te(`settings.fields.${props.i18nKey}.hint`)) {
    return t(`settings.fields.${props.i18nKey}.hint`)
  }
  return ''
})

const fieldType = computed(() => {
  const v = props.modelValue
  if (typeof v === 'boolean') return 'boolean'
  if (typeof v === 'number') return 'number'
  if (Array.isArray(v)) return 'array'
  return 'string'
})

function onNumber(val) {
  const n = Number(val)
  emit('update:modelValue', Number.isFinite(n) ? n : 0)
}
</script>

<template>
  <div class="d-flex align-start ga-2 mb-3">
    <div class="flex-grow-1">
      <v-switch
        v-if="fieldType === 'boolean'"
        :model-value="modelValue"
        :label="label"
        :messages="hint ? [hint] : []"
        color="primary"
        density="comfortable"
        hide-details="auto"
        @update:model-value="emit('update:modelValue', $event)"
      />
      <v-text-field
        v-else-if="fieldType === 'number'"
        :model-value="String(modelValue)"
        :label="label"
        :hint="hint"
        :persistent-hint="!!hint"
        type="number"
        variant="outlined"
        density="comfortable"
        hide-details="auto"
        @update:model-value="onNumber"
      />
      <v-combobox
        v-else-if="fieldType === 'array'"
        :model-value="modelValue"
        :label="label"
        :hint="hint"
        :persistent-hint="!!hint"
        chips
        closable-chips
        multiple
        variant="outlined"
        density="comfortable"
        hide-details="auto"
        @update:model-value="emit('update:modelValue', $event)"
      />
      <v-text-field
        v-else
        :model-value="modelValue"
        :label="label"
        :hint="hint"
        :persistent-hint="!!hint"
        variant="outlined"
        density="comfortable"
        hide-details="auto"
        @update:model-value="emit('update:modelValue', $event)"
      />
    </div>

    <v-btn
      v-if="removable"
      icon="mdi-delete-outline"
      size="small"
      variant="text"
      color="error"
      class="mt-2"
      :title="t('common.remove')"
      @click="emit('remove')"
    />
  </div>
</template>
