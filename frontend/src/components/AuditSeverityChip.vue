<script setup>
// Farbiger Schweregrad-Chip für Audit-Funde. Optional mit Zähler.
defineProps({
  severity: { type: String, required: true }, // error | warning | hint
  count: { type: Number, default: null },
  size: { type: String, default: 'small' },
})

const COLOR = { error: 'error', warning: 'warning', hint: 'info' }
const ICON = {
  error: 'mdi-alert-circle-outline',
  warning: 'mdi-alert-outline',
  hint: 'mdi-information-outline',
}
</script>

<template>
  <v-chip :color="COLOR[severity] || 'default'" :size="size" variant="flat" label>
    <v-icon :icon="ICON[severity]" size="14" start />
    {{ $t('audit.severity.' + severity) }}<template v-if="count !== null"> · {{ count }}</template>
  </v-chip>
</template>
