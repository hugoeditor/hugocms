<script setup>
// Global eingebundener Bestätigungsdialog (einmal in App.vue). Wird über das
// useConfirm()-Composable gesteuert und ersetzt window.confirm() durch einen
// Vuetify-Dialog. Schließen über Escape/Klick daneben gilt als Abbruch.
import { useI18n } from 'vue-i18n'
import { confirmState, settleConfirm } from '../util/confirm'

const { t } = useI18n()
const state = confirmState()
</script>

<template>
  <v-dialog
    :model-value="state.open"
    width="440"
    @update:model-value="settleConfirm(false)"
    @keydown.enter="settleConfirm(true)"
  >
    <v-card>
      <v-card-title v-if="state.title" class="text-subtitle-1">{{ state.title }}</v-card-title>
      <v-card-text>{{ state.message }}</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="settleConfirm(false)">
          {{ state.cancelText || t('dialog.cancel') }}
        </v-btn>
        <v-btn :color="state.color" variant="flat" @click="settleConfirm(true)">
          {{ state.confirmText || t('dialog.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
