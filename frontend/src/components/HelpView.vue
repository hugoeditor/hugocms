<script setup>
// Hilfe-Ansicht als Überlagerung (analog zum Editor, mit Zurück-Button). Zeigt
// ein Thema der Wissensdatenbank: Titel, Kurzfassung, gerenderter Markdown-Rumpf
// und anklickbare Verweise (see_also). Schließt zurück zur darunterliegenden
// Ansicht (z. B. dem SEO-Audit-Bericht), sodass sich Aufgaben ohne Unterbrechung
// abarbeiten lassen.
import { computed } from 'vue'
import { marked } from 'marked'
import { useI18n } from 'vue-i18n'
import { useHelpStore } from '../stores/help'
import { errorText } from '../i18n/apiMessage'

const { t, locale } = useI18n()
const help = useHelpStore()

// Markdown-Rumpf zu HTML. Die Hilfe-Dateien werden mit der App ausgeliefert
// (vertrauenswürdig, in Git), daher genügt marked ohne zusätzliche Bereinigung.
const bodyHtml = computed(() =>
  help.data?.body ? marked.parse(help.data.body, { gfm: true, breaks: false }) : '',
)

function openRelated(id) {
  help.open(help.data.section, id, locale.value)
}
</script>

<template>
  <div v-if="help.topic" class="help-overlay">
    <header class="help-head nemo-noselect">
      <button class="help-back" :title="$t('help.back')" @click="help.close()">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-lifebuoy" size="18" class="help-head-icon" />
      <span class="help-head-title">{{ help.data?.title || $t('help.title') }}</span>
    </header>

    <div class="help-content nemo-scroll">
      <!-- Lädt -->
      <div v-if="help.loading" class="help-state">
        <v-progress-circular indeterminate size="34" width="3" color="primary" />
      </div>

      <!-- Nicht gefunden / Fehler -->
      <div v-else-if="help.error" class="help-state">
        <v-icon icon="mdi-book-remove-outline" size="48" class="help-state-icon" />
        <p>{{ errorText(t, help.error) }}</p>
        <p class="help-state-hint">{{ $t('help.notFoundHint') }}</p>
      </div>

      <!-- Thema -->
      <article v-else-if="help.data" class="help-article">
        <p v-if="help.data.summary" class="help-summary">{{ help.data.summary }}</p>

        <v-alert
          v-if="help.data.fallback"
          type="info"
          density="compact"
          class="mb-3"
          :text="$t('help.fallbackNote')"
        />

        <!-- eslint-disable-next-line vue/no-v-html — vertrauenswürdiger, mit der App ausgelieferter Inhalt -->
        <div class="help-body" v-html="bodyHtml" />

        <div v-if="help.data.seeAlso?.length" class="help-seealso">
          <div class="help-seealso-label">{{ $t('help.seeAlso') }}</div>
          <button
            v-for="id in help.data.seeAlso"
            :key="id"
            class="help-chip"
            @click="openRelated(id)"
          >
            {{ id }}
          </button>
        </div>
      </article>
    </div>
  </div>
</template>

<style scoped>
/* Überlagerung über dem Arbeitsbereich (wie der Editor), eine Ebene darüber. */
.help-overlay {
  position: absolute;
  inset: 0;
  z-index: 15;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}

.help-head {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.help-back {
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
.help-back:hover { background: var(--mint-panel-hover); }
.help-head-icon { color: var(--mint-green); }
.help-head-title { font-weight: 600; font-size: 0.95rem; }

.help-content { flex: 1 1 auto; overflow: auto; }
.help-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  gap: 12px;
  color: var(--mint-text-muted);
}
.help-state-icon { color: #c4c4c0; }
.help-state-hint { font-size: 0.82rem; }

.help-article {
  max-width: 760px;
  margin: 0 auto;
  padding: 20px 24px 48px;
}
.help-summary {
  font-size: 1.02rem;
  color: var(--mint-text);
  border-left: 3px solid var(--mint-green);
  padding: 4px 0 4px 12px;
  margin-bottom: 18px;
}

/* Gerenderter Markdown-Rumpf. */
.help-body :deep(h2) {
  font-size: 1.08rem;
  font-weight: 600;
  margin: 22px 0 8px;
  color: var(--mint-text);
}
.help-body :deep(h3) { font-size: 0.98rem; font-weight: 600; margin: 16px 0 6px; }
.help-body :deep(p) { margin: 8px 0; line-height: 1.6; }
.help-body :deep(ul),
.help-body :deep(ol) { margin: 8px 0 8px 22px; line-height: 1.6; }
.help-body :deep(li) { margin: 3px 0; }
.help-body :deep(a) { color: var(--mint-green); }
.help-body :deep(code) {
  background: var(--mint-panel);
  border: 1px solid var(--mint-border);
  border-radius: 4px;
  padding: 1px 5px;
  font-size: 0.86em;
}
.help-body :deep(pre) {
  background: #f4f4f3;
  border: 1px solid #d3d3d1;
  border-radius: 4px;
  padding: 10px 12px;
  overflow: auto;
  font-size: 0.82rem;
}
.help-body :deep(pre code) { background: none; border: 0; padding: 0; }
.help-body :deep(table) { border-collapse: collapse; margin: 10px 0; }
.help-body :deep(th),
.help-body :deep(td) { border: 1px solid var(--mint-border); padding: 4px 10px; text-align: left; }

.help-seealso {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--mint-border);
}
.help-seealso-label { font-size: 0.82rem; color: var(--mint-text-muted); }
.help-chip {
  border: 1px solid var(--mint-border);
  border-radius: 999px;
  background: #fff;
  padding: 2px 12px;
  font-size: 0.8rem;
  font-family: monospace;
  color: var(--mint-green);
  cursor: pointer;
}
.help-chip:hover { background: var(--mint-panel-hover); }
</style>
