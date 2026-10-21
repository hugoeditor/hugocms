<script setup>
// Gemeinsame Hülle der Einstellungs-Ansichten (Konto, Projekteinstellungen,
// Orte, Konfiguration): Overlay über dem Arbeitsbereich mit Kopfzeile
// (Zurück-Knopf, Symbol, Titel, Aktionen rechts), optionaler fester
// Zwischenzeile (Meldungen, Einleitung, Sprungnavigation), scrollendem Inhalt
// und optionaler Fußzeile (Abbrechen/Speichern). Aufbau und Stil folgen der
// Benutzerverwaltung (UsersView), damit alle Verwaltungsansichten gleich
// aussehen und sich gleich bedienen lassen.
//
//   <OverlayView v-if="open" icon="mdi-cog-outline" :title="…" @close="open = false">
//     <template #actions>…</template>    <!-- rechts in der Kopfzeile -->
//     <template #subheader>…</template>  <!-- fest, scrollt nicht -->
//     …                                   <!-- scrollender Inhalt -->
//     <template #footer>…</template>     <!-- feste Fußzeile -->
//   </OverlayView>
defineProps({
  icon: { type: String, required: true },
  title: { type: String, required: true },
  // Zurück-Knopf gesperrt, solange eine Aktion läuft (z. B. Speichern) —
  // das Gegenstück zum „persistent“ der früheren Dialoge.
  busy: { type: Boolean, default: false },
  // Volle Breite statt der auf Formulare abgestimmten Lesebreite.
  wide: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])
</script>

<template>
  <div class="ov-overlay">
    <header class="ov-head nemo-noselect">
      <button type="button" class="ov-back" :title="$t('common.close')" :disabled="busy" @click="emit('close')">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon :icon="icon" size="18" class="ov-head-icon" />
      <span class="ov-head-title text-truncate">{{ title }}</span>
      <v-spacer />
      <slot name="actions" />
    </header>

    <div v-if="$slots.subheader" class="ov-sub">
      <div class="ov-inner ov-inner--sub" :class="{ 'ov-inner--wide': wide }">
        <slot name="subheader" />
      </div>
    </div>

    <div class="ov-content">
      <div class="ov-inner" :class="{ 'ov-inner--wide': wide }">
        <slot />
      </div>
    </div>

    <footer v-if="$slots.footer" class="ov-foot nemo-noselect">
      <div class="ov-inner ov-inner--foot" :class="{ 'ov-inner--wide': wide }">
        <slot name="footer" />
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* Overlay über dem Arbeitsbereich — z-index wie Benutzerverwaltung und
   Systemstatus. */
.ov-overlay {
  position: absolute;
  inset: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}

.ov-head {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
  flex: 0 0 auto;
}
.ov-back {
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
.ov-back:hover:not(:disabled) { background: var(--mint-panel-hover); }
.ov-back:disabled { color: #b6b6b3; cursor: default; }
.ov-head-icon { color: var(--mint-green); }
.ov-head-title { font-weight: 600; font-size: 0.95rem; min-width: 0; }

/* Feste Zwischenzeile: bleibt beim Scrollen sichtbar. */
.ov-sub {
  flex: 0 0 auto;
  border-bottom: 1px solid var(--mint-border);
  background: var(--mint-content);
}

.ov-content { flex: 1 1 auto; overflow: auto; }

.ov-foot {
  flex: 0 0 auto;
  background: var(--mint-panel);
  border-top: 1px solid var(--mint-border);
}

/* Lesebreite für Formulare; „wide“ gibt die volle Breite frei. */
.ov-inner { max-width: 820px; margin: 0; padding: 12px 12px 24px; }
.ov-inner--wide { max-width: none; }
.ov-inner--sub { padding: 10px 12px 8px; }
.ov-inner--foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 8px 12px;
}
</style>
