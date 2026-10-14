<script setup>
import { nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'

const { t } = useI18n()
const files = useFilesStore()

function crumb(item) {
  files.openDir(item.id)
}

// Erweiterte Suche: eigene Leiste nach Nemo-Vorbild, getrennt vom
// Schnellfilter. Enter in einem ihrer Felder oder der Suchknopf starten die
// Serversuche; beim Öffnen bekommt das Namensfeld den Fokus.
const nameInput = ref(null)

watch(
  () => files.searchOpen,
  async (open) => {
    if (!open) return
    await nextTick()
    nameInput.value?.focus()
  },
)

function onSearch() {
  files.search()
}

// Entwurfsfilter umschalten: zeigt alle Seiten mit `draft: true` ab dem
// aktuellen Ordner, ein zweiter Klick führt zurück zur Ordneransicht.
function toggleDrafts() {
  if (files.draftFilter) {
    files.exitSearch()
  } else {
    files.searchDrafts()
  }
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

    <!-- Anlegen -->
    <div v-if="files.cwd" class="nemo-navgroup">
      <v-tooltip :text="t('ctx.newFolder')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :disabled="!files.can('mkdir')"
            @click="files.requestNew('folder')"
          >
            <v-icon icon="mdi-folder-plus-outline" size="20" />
          </button>
        </template>
      </v-tooltip>
      <v-tooltip :text="t('ctx.newFile')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :disabled="!files.can('write')"
            @click="files.requestNew('file')"
          >
            <v-icon icon="mdi-file-plus-outline" size="20" />
          </button>
        </template>
      </v-tooltip>
      <v-tooltip :text="t('ctx.upload')" location="bottom">
        <template #activator="{ props }">
          <button
            v-bind="props"
            class="nemo-iconbtn"
            :disabled="!files.can('upload') || files.uploading"
            @click="files.requestNew('upload')"
          >
            <v-icon :icon="files.uploading ? 'mdi-progress-upload' : 'mdi-upload-outline'" size="20" />
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
        <v-icon v-if="i === 0" icon="mdi-folder" size="16" class="mr-1" />
        {{ item.name }}
      </button>
    </div>

    <div class="nemo-spacer" />

    <!-- Schnellfilter: grenzt beim Tippen das angezeigte Verzeichnis ein -->
    <div v-if="files.cwd" class="nemo-filter">
      <v-icon icon="mdi-filter-outline" size="18" class="nemo-filter-icon" />
      <input
        :value="files.filter"
        :placeholder="t('search.placeholder')"
        class="nemo-filter-input"
        @input="files.setFilter($event.target.value)"
        @keydown.escape="files.setFilter('')"
      />
    </div>

    <!-- Erweiterte Suche ein-/ausblenden -->
    <v-tooltip v-if="files.cwd" :text="t('search.toggle')" location="bottom">
      <template #activator="{ props }">
        <button
          v-bind="props"
          class="nemo-iconbtn"
          :class="{ active: files.searchOpen }"
          :aria-pressed="files.searchOpen"
          @click="files.toggleSearchBar()"
        >
          <v-icon icon="mdi-magnify" size="20" />
        </button>
      </template>
    </v-tooltip>

    <!-- Entwurfsfilter: alle Seiten mit draft: true ab dem aktuellen Ordner -->
    <v-tooltip v-if="files.cwd" :text="t('search.draftsHint')" location="bottom">
      <template #activator="{ props }">
        <button
          v-bind="props"
          class="nemo-iconbtn"
          :class="{ active: files.draftFilter }"
          :disabled="files.draftBusy"
          :aria-pressed="files.draftFilter"
          @click="toggleDrafts"
        >
          <v-icon :icon="files.draftBusy ? 'mdi-timer-sand' : 'mdi-file-document-alert-outline'" size="18" />
        </button>
      </template>
    </v-tooltip>

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

  <!-- Erweiterte Suchleiste (ausklappbar, wie in Nemo unter der Werkzeugleiste) -->
  <div v-if="files.cwd && files.searchOpen" class="nemo-searchbar nemo-noselect">
    <div class="nemo-filter nemo-searchbar-field">
      <v-icon icon="mdi-file-search-outline" size="18" class="nemo-filter-icon" />
      <input
        ref="nameInput"
        :value="files.searchName"
        :placeholder="t('search.namePlaceholder')"
        class="nemo-filter-input"
        @input="files.setSearchName($event.target.value)"
        @keydown.enter="onSearch"
        @keydown.escape="files.exitSearch()"
      />
    </div>
    <div class="nemo-filter nemo-searchbar-field">
      <v-icon icon="mdi-text-search" size="18" class="nemo-filter-icon" />
      <input
        :value="files.contentText"
        :placeholder="t('search.contentPlaceholder')"
        class="nemo-filter-input"
        @input="files.setContentText($event.target.value)"
        @keydown.enter="onSearch"
        @keydown.escape="files.exitSearch()"
      />
    </div>
    <label class="nemo-searchbar-scope">
      <input
        type="checkbox"
        :checked="files.searchFromCwd"
        @change="files.setSearchFromCwd($event.target.checked)"
      />
      {{ t('search.fromCwd') }}
    </label>
    <button
      class="nemo-searchbar-run"
      :disabled="files.searchBusy || (!files.searchName.trim() && !files.contentText.trim())"
      @click="onSearch"
    >
      <v-icon :icon="files.searchBusy ? 'mdi-timer-sand' : 'mdi-magnify'" size="16" class="mr-1" />
      {{ t('search.run') }}
    </button>
    <v-tooltip :text="t('search.close')" location="bottom">
      <template #activator="{ props }">
        <button v-bind="props" class="nemo-iconbtn" @click="files.exitSearch()">
          <v-icon icon="mdi-close" size="18" />
        </button>
      </template>
    </v-tooltip>
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
  /* Nicht schrumpfen: bricht die Leiste auf schmalen Schirmen um, muss sie
     ihre volle (umgebrochene) Höhe behalten, sonst überdeckt die Listenansicht
     die zweite Zeile (Pfadleiste). */
  flex: 0 0 auto;
}

/* Schmale Schirme (Handy/Tablet): Werkzeugleiste umbrechen statt überlaufen.
   Die Pfadleiste rückt in eine eigene Zeile (voll breit, horizontal scrollbar),
   das Filterfeld füllt die verbleibende Breite. */
@media (max-width: 959.98px) {
  .nemo-toolbar {
    flex-wrap: wrap;
    row-gap: 6px;
  }
  .nemo-spacer {
    display: none;
  }
  .nemo-pathbar {
    order: 10;
    flex: 1 1 100%;
    max-width: 100%;
    overflow-x: auto;
  }
  .nemo-filter {
    flex: 1 1 160px;
    min-width: 0;
  }
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

/* Erweiterte Suchleiste: eigene Zeile unter der Werkzeugleiste. */
.nemo-searchbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 5px 8px;
  background: var(--mint-green-soft);
  border-bottom: 1px solid var(--mint-border);
  flex: 0 0 auto;
}
.nemo-searchbar-field {
  flex: 1 1 200px;
  min-width: 0;
}
.nemo-searchbar-scope {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.84rem;
  color: var(--mint-green-soft-text);
  white-space: nowrap;
  cursor: pointer;
}
.nemo-searchbar-run {
  display: inline-flex;
  align-items: center;
  height: 32px;
  padding: 0 12px;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  font-size: 0.84rem;
  color: var(--mint-text);
  cursor: pointer;
}
.nemo-searchbar-run:hover:not(:disabled) {
  background: var(--mint-panel-hover);
}
.nemo-searchbar-run:disabled {
  color: #b6b6b3;
  cursor: default;
}
</style>
