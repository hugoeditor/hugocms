<script setup>
// Hinweis anstelle einer gesperrten Pro-Funktion. Pro-Funktionen werden bewusst
// NICHT mehr ausgeblendet: Wer die Community-Edition nutzt, soll sehen, was die
// Pro-Variante kann — und was ihm zur Nutzung noch fehlt.
//
// Die Texte je Funktion stehen in der i18n unter `pro.feature.<id>`
// (title, intro, points[]). Diese Komponente rendert sie und nennt die offenen
// Voraussetzungen, die der Server in whoami.features meldet.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  // Funktionsname wie in whoami.features: git | audit | auditContent |
  // pagespeed | liveAnalysis | speech
  feature: { type: String, required: true },
  // Kompakte Darstellung ohne Rahmen — für Reiterinhalte, die schon in einer
  // Karte stecken.
  dense: { type: Boolean, default: false },
})

const emit = defineEmits(['activate'])

const { tm, rt } = useI18n()
const auth = useAuthStore()

const blockers = computed(() => auth.blockers(props.feature))

// Nutzenpunkte der Funktion. tm() liefert das rohe Array aus der i18n,
// rt() übersetzt den einzelnen Eintrag.
const points = computed(() => {
  const list = tm(`pro.feature.${props.feature}.points`)
  return Array.isArray(list) ? list.map((p) => rt(p)) : []
})

// Fehlt nur die Lizenz, ist die Aussage einfach: Schlüssel eintragen, fertig.
// Fehlt mehr, werden die übrigen Voraussetzungen einzeln genannt — sonst
// verspräche der Knopf etwas, das er nicht hält.
const otherBlockers = computed(() => blockers.value.filter((b) => b !== 'pro'))
const needsPro = computed(() => blockers.value.includes('pro'))
</script>

<template>
  <div class="pro-gate" :class="{ 'pro-gate--dense': dense }">
    <div class="pro-gate-head">
      <v-icon icon="mdi-lock-outline" size="20" />
      <h3 class="pro-gate-title">{{ $t(`pro.feature.${feature}.title`) }}</h3>
      <span class="pro-gate-badge">{{ $t('status.pro') }}</span>
    </div>

    <p class="pro-gate-intro">{{ $t(`pro.feature.${feature}.intro`) }}</p>

    <ul v-if="points.length" class="pro-gate-points">
      <li v-for="(p, n) in points" :key="n">
        <v-icon icon="mdi-check" size="16" />
        <span>{{ p }}</span>
      </li>
    </ul>

    <!-- Was noch fehlt. Die Lizenz steht zuerst, weil sie der Grund ist, aus
         dem dieser Hinweis überhaupt erscheint. -->
    <div class="pro-gate-needs">
      <template v-if="needsPro">
        <p class="pro-gate-need">
          <v-icon icon="mdi-key-outline" size="16" />
          {{ $t('pro.needsLicense') }}
        </p>
      </template>
      <p v-for="b in otherBlockers" :key="b" class="pro-gate-need">
        <v-icon icon="mdi-alert-circle-outline" size="16" />
        {{ $t(`pro.blocker.${b}`) }}
      </p>
    </div>

    <div class="pro-gate-actions">
      <v-btn
        v-if="needsPro && auth.licensable"
        color="primary"
        variant="flat"
        size="small"
        prepend-icon="mdi-license"
        @click="emit('activate')"
      >
        {{ $t('pro.activate') }}
      </v-btn>
      <span v-if="needsPro && !auth.licensable" class="pro-gate-note">
        {{ $t('license.notActivatable') }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.pro-gate {
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: var(--mint-panel);
  padding: 18px 20px;
  max-width: 640px;
  margin: 24px auto;
}
/* In Reiterinhalten sitzt der Hinweis schon in einem Rahmen. */
.pro-gate--dense {
  border: none;
  background: transparent;
  padding: 12px 0;
  margin: 8px 0;
}

.pro-gate-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.pro-gate-title {
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
}
.pro-gate-badge {
  padding: 0 5px;
  border-radius: 4px;
  background: var(--mint-green);
  color: #fff;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.pro-gate-intro {
  font-size: 0.88rem;
  margin: 0 0 10px;
}

.pro-gate-points {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  font-size: 0.85rem;
}
.pro-gate-points li {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  padding: 2px 0;
}
.pro-gate-points :deep(.v-icon) { color: var(--mint-green); margin-top: 1px; }

.pro-gate-needs {
  border-top: 1px solid var(--mint-border);
  padding-top: 10px;
  margin-bottom: 12px;
}
.pro-gate-need {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.82rem;
  opacity: 0.85;
  margin: 0 0 4px;
}
.pro-gate-need:last-child { margin-bottom: 0; }

.pro-gate-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.pro-gate-note { font-size: 0.8rem; opacity: 0.75; }
</style>
