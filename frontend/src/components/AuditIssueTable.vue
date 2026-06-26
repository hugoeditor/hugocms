<script setup>
// Tabelle der Audit-Funde. Übersetzt jede Regel-ID in eine lesbare Meldung
// (Schlüssel audit.rules.<ruleId mit Unterstrichen>, Parameter aus dem Fund).
// Hat ein Fund eine fileId, lässt sich die Quelldatei direkt im Editor öffnen.
import { useI18n } from 'vue-i18n'
import AuditSeverityChip from './AuditSeverityChip.vue'

defineProps({
  issues: { type: Array, required: true },
})
const emit = defineEmits(['open-source'])

const { t } = useI18n()

function ruleMessage(issue) {
  return t('audit.rules.' + issue.ruleId.replaceAll('.', '_'), issue.params || [])
}
</script>

<template>
  <div v-if="issues.length === 0" class="nemo-empty">
    <v-icon icon="mdi-check-circle-outline" size="56" class="nemo-empty-icon" />
    <p>{{ $t('audit.noIssues') }}</p>
  </div>

  <table v-else class="nemo-list audit-list">
    <thead>
      <tr>
        <th class="col-sev">{{ $t('audit.colSeverity') }}</th>
        <th class="col-msg">{{ $t('audit.colIssue') }}</th>
        <th class="col-url">{{ $t('audit.colUrl') }}</th>
        <th class="col-act"></th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(issue, idx) in issues" :key="idx" class="nemo-row">
        <td class="col-sev"><AuditSeverityChip :severity="issue.severity" /></td>
        <td class="col-msg">
          <span class="audit-msg">{{ ruleMessage(issue) }}</span>
          <code class="audit-ruleid">{{ issue.ruleId }}</code>
        </td>
        <td class="col-url">
          <code v-if="issue.url">{{ issue.url }}</code>
          <span v-else-if="issue.sourceFile" class="audit-src">{{ issue.sourceFile }}</span>
          <span v-else>—</span>
        </td>
        <td class="col-act">
          <v-tooltip v-if="issue.fileId" :text="$t('audit.openSource')" location="start">
            <template #activator="{ props }">
              <button v-bind="props" class="audit-jump" @click="emit('open-source', issue)">
                <v-icon icon="mdi-file-edit-outline" size="18" />
              </button>
            </template>
          </v-tooltip>
        </td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.audit-list { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.audit-list thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  text-align: left;
  font-weight: 600;
  color: var(--mint-text-muted);
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  padding: 6px 10px;
  white-space: nowrap;
}
.col-sev { width: 120px; }
.col-url { width: 28%; }
.col-act { width: 44px; text-align: center; }

.nemo-row td {
  padding: 6px 10px;
  border-bottom: 1px solid #f0f0ee;
  vertical-align: top;
}
.nemo-row:hover { background: var(--mint-row-hover); }

.col-msg { color: var(--mint-text); }
.audit-msg { display: block; }
.audit-ruleid {
  display: inline-block;
  margin-top: 2px;
  font-size: 0.72rem;
  color: var(--mint-text-muted);
}
.col-url code, .audit-src {
  font-size: 0.8rem;
  color: var(--mint-text-muted);
  word-break: break-all;
}
.audit-jump {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 3px;
  color: var(--mint-text);
  cursor: pointer;
}
.audit-jump:hover { background: var(--mint-panel-hover); }

.nemo-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 0;
  color: var(--mint-text-muted);
  gap: 10px;
}
.nemo-empty-icon { color: #9fc08f; }
</style>
