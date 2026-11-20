<script setup>
// Merkt ausgewählte Content-Dateien zur KI-Verbesserung vor, OHNE den
// kostenpflichtigen Qualitäts-Check. Der Benutzer kann der KI eine Anweisung
// mitgeben; sie ist mit einem sinnvollen Vorschlag vorausgefüllt und lässt sich
// über Vorschlags-Chips ergänzen. Bestätigen reicht die Anweisung nach oben.
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t, tm, rt } = useI18n()

// Sichtbarkeit als v-model. `count` = Anzahl der vorzumerkenden Dateien.
const model = defineModel({ type: Boolean, default: false })
const props = defineProps({ count: { type: Number, default: 0 } })
const emit = defineEmits(['confirm'])

const instruction = ref('')
const busy = ref(false)

// Vorschlags-Bausteine aus der i18n (Array). Klick fügt die Zeile an, falls noch
// nicht enthalten.
const presets = computed(() => {
  const list = tm('contentQuality.queuePresets')
  return Array.isArray(list) ? list.map((p) => rt(p)) : []
})

// Beim Öffnen mit dem vorgeschlagenen Standardtext vorbefüllen.
watch(model, (open) => {
  if (open) {
    instruction.value = t('contentQuality.queueDefaultInstruction')
    busy.value = false
  }
})

function addPreset(text) {
  const cur = instruction.value.trim()
  if (cur.includes(text)) return
  instruction.value = cur ? `${cur}\n${text}` : text
}

async function confirm() {
  busy.value = true
  // Die eigentliche Arbeit macht der Aufrufer; er schließt den Dialog.
  emit('confirm', instruction.value.trim())
}
</script>

<template>
  <v-dialog v-model="model" width="560" :persistent="busy">
    <v-card class="pa-2">
      <v-card-title class="d-flex align-center text-h6">
        <v-icon icon="mdi-playlist-plus" class="mr-2" />
        {{ $t('contentQuality.queueTitle') }}
      </v-card-title>
      <v-card-subtitle class="text-wrap">
        {{ $t('contentQuality.queueIntro', [count]) }}
      </v-card-subtitle>

      <v-card-text>
        <div class="text-subtitle-2 mb-1">{{ $t('contentQuality.instruction') }}</div>
        <div class="text-caption text-medium-emphasis mb-2">
          {{ $t('contentQuality.queueInstructionHint') }}
        </div>
        <v-textarea
          v-model="instruction"
          :rows="4"
          auto-grow
          density="comfortable"
          variant="outlined"
          hide-details
          :placeholder="$t('contentQuality.instructionHint')"
        />

        <div v-if="presets.length" class="mt-3">
          <div class="text-caption text-medium-emphasis mb-1">{{ $t('contentQuality.queuePresetLabel') }}</div>
          <div class="d-flex flex-wrap" style="gap: 6px">
            <v-chip
              v-for="(p, i) in presets"
              :key="i"
              size="small"
              variant="outlined"
              label
              @click="addPreset(p)"
            >
              <v-icon icon="mdi-plus" size="14" start />
              {{ p }}
            </v-chip>
          </div>
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="busy" @click="model = false">{{ $t('common.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :loading="busy" @click="confirm">
          {{ $t('contentQuality.queueConfirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
