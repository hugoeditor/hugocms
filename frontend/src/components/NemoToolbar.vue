<script setup>
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'

const { t } = useI18n()
const files = useFilesStore()

function crumb(item) {
  files.openDir(item.id)
}
</script>

<template>
  <div class="nemo-toolbar nemo-noselect">
    <!-- Navigationsschaltflächen -->
    <div class="nemo-navgroup">
      <v-tooltip :text="t('nav.back')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :disabled="!files.canBack"
            @click="files.goBack()"
          >
            <v-icon icon="mdi-arrow-left" size="20" />
          </button>
        </template>
      </v-tooltip>
      <v-tooltip :text="t('nav.forward')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :disabled="!files.canForward"
            @click="files.goForward()"
          >
            <v-icon icon="mdi-arrow-right" size="20" />
          </button>
        </template>
      </v-tooltip>
      <v-tooltip :text="t('nav.up')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :disabled="!files.canUp"
            @click="files.goUp()"
          >
            <v-icon icon="mdi-arrow-up" size="20" />
          </button>
        </template>
      </v-tooltip>
      <v-tooltip :text="t('nav.refresh')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :disabled="!files.cwd"
            @click="files.refresh()"
          >
            <v-icon icon="mdi-refresh" size="20" />
          </button>
        </template>
      </v-tooltip>
    </div>

    <!-- Pfadleiste (GTK-Pathbar) -->
    <div class="nemo-pathbar">
      <button
        v-for="(item, i) in files.breadcrumb"
        :key="item.id"
        class="nemo-crumb"
        :class="{ current: i === files.breadcrumb.length - 1 }"
        @click="crumb(item)"
      >
        <v-icon v-if="i === 0" icon="mdi-folder-network-outline" size="16" class="mr-1" />
        {{ item.name }}
      </button>
    </div>

    <div class="nemo-spacer" />

    <!-- Schnellfilter -->
    <div v-if="files.cwd" class="nemo-filter">
      <v-icon icon="mdi-magnify" size="18" class="nemo-filter-icon" />
      <input
        :value="files.filter"
        :placeholder="t('toolbar.filter')"
        class="nemo-filter-input"
        @input="files.setFilter($event.target.value)"
      />
    </div>

    <!-- Ansichtsumschalter -->
    <div class="nemo-viewtoggle">
      <v-tooltip :text="t('view.list')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :class="{ active: files.view === 'list' }"
            @click="files.setView('list')"
          >
            <v-icon icon="mdi-view-list" size="20" />
          </button>
        </template>
      </v-tooltip>
      <v-tooltip :text="t('view.icons')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :class="{ active: files.view === 'icons' }"
            @click="files.setView('icons')"
          >
            <v-icon icon="mdi-view-grid-outline" size="20" />
          </button>
        </template>
      </v-tooltip>
    </div>
  </div>
</template>

<style scoped>
.nemo-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 8px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  min-height: 44px;
}

.nemo-navgroup,
.nemo-viewtoggle {
  display: flex;
  gap: 2px;
}

/* GTK-artige flache Icon-Schaltfläche. */
.nemo-iconbtn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 1px solid transparent;
  border-radius: var(--mint-radius);
  color: var(--mint-text);
  background: transparent;
  cursor: pointer;
}
.nemo-iconbtn:hover:not(:disabled) {
  background: var(--mint-panel-hover);
  border-color: var(--mint-border);
}
.nemo-iconbtn:active:not(:disabled) {
  background: #e0e0dd;
}
.nemo-iconbtn:disabled {
  color: #b6b6b3;
  cursor: default;
}
.nemo-iconbtn.active {
  background: var(--mint-green-soft);
  border-color: #cfe0c5;
  color: var(--mint-green-soft-text);
}

/* Pfadleiste: zusammenhängende Schaltflächen. */
.nemo-pathbar {
  display: flex;
  align-items: center;
  margin-left: 4px;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  overflow: hidden;
  background: #fff;
  max-width: 50%;
}
.nemo-crumb {
  display: inline-flex;
  align-items: center;
  height: 30px;
  padding: 0 10px;
  border: none;
  border-right: 1px solid var(--mint-border);
  background: #fbfbfa;
  color: var(--mint-text);
  font-size: 0.86rem;
  white-space: nowrap;
  cursor: pointer;
}
.nemo-crumb:last-child {
  border-right: none;
}
.nemo-crumb:hover {
  background: var(--mint-panel-hover);
}
.nemo-crumb.current {
  background: var(--mint-green-soft);
  color: var(--mint-green-soft-text);
  font-weight: 600;
}

.nemo-spacer {
  flex: 1 1 auto;
}

/* Filterfeld. */
.nemo-filter {
  display: flex;
  align-items: center;
  height: 32px;
  padding: 0 8px;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  min-width: 180px;
}
.nemo-filter-icon {
  color: var(--mint-text-muted);
  margin-right: 4px;
}
.nemo-filter-input {
  border: none;
  outline: none;
  background: transparent;
  font-size: 0.86rem;
  color: var(--mint-text);
  width: 100%;
}
.nemo-filter-input::placeholder {
  color: #a3a39f;
}
</style>
