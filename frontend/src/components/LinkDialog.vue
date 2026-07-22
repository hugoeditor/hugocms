<script setup>
// Gemeinsamer Dialog zum Einfügen/Bearbeiten eines Links — genutzt vom
// visuellen Editor (TipTap) und vom Quelltext-Editor (CodeMirror). Der Dialog
// kennt die Zielsprache nicht; er sammelt nur die Werte und meldet sie zurück.
// Der Titel gilt für beide Formate: HTML schreibt ihn als title-Attribut,
// Markdown als [Text](Adresse "Titel") — Hugo macht daraus dasselbe Attribut.
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { suggestLinkTitle } from '../util/linkSnippet'

// Endungen, die per Knopfdruck an der Cursorposition landen. Die
// Länderendungen richten sich nach der Oberflächensprache — für die deutsche
// Fassung .de, für die englische .uk und .us; .com und .org sind überall
// gebräuchlich und stehen immer zur Verfügung.
const LOCALE_TLDS = { de: ['.de'], en: ['.uk', '.us'] }
const COMMON_TLDS = ['.com', '.org']

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // Vorbelegung: { href, text, title } — z. B. aus der aktuellen Auswahl.
  initial: { type: Object, default: () => ({}) },
  // Bestehenden Link entfernen anbieten (nur beim Bearbeiten sinnvoll).
  canRemove: { type: Boolean, default: false },
  // Externer Link: nur für die Überschrift des Dialogs; die Vorbelegung der
  // Felder bringt der Aufrufer mit.
  external: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'submit', 'remove'])
const { t, locale } = useI18n()

const tlds = computed(() => [...(LOCALE_TLDS[locale.value] ?? []), ...COMMON_TLDS])

const href = ref('')
const text = ref('')
const title = ref('')
const touched = ref(false)
const hrefField = ref(null)

// Setzt den Cursor im Adressfeld auf eine bestimmte Stelle. Das geht erst,
// wenn Vue den neuen Wert ins <input> geschrieben hat — daher nextTick.
function focusHref(position) {
  nextTick(() => {
    const input = hrefField.value
    input?.focus()
    input?.setSelectionRange?.(position, position)
  })
}

// Setzt „https://“ an den Anfang der Adresse — spart das Tippen des Präfixes
// bei einer aus der Adresszeile kopierten oder von Hand notierten Adresse. Ein
// bereits vorhandenes Schema wird ersetzt, nicht verdoppelt.
function prependHttps() {
  href.value = 'https://' + href.value.trim().replace(/^https?:\/\//i, '')
  focusHref(href.value.length)
}

// Fügt einen Baustein (z. B. „.de“) an der Cursorposition ein; eine markierte
// Stelle wird ersetzt. Ohne bekannte Cursorposition landet er am Ende.
function insertIntoHref(snippet) {
  const input = hrefField.value
  const start = typeof input?.selectionStart === 'number' ? input.selectionStart : href.value.length
  const end = typeof input?.selectionEnd === 'number' ? input.selectionEnd : start
  href.value = href.value.slice(0, start) + snippet + href.value.slice(end)
  focusHref(start + snippet.length)
}

// Beim Öffnen die Vorbelegung übernehmen; die Felder gehören dem Dialog.
watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    href.value = props.initial.href ?? ''
    text.value = props.initial.text ?? ''
    title.value = props.initial.title ?? ''
    touched.value = false
  },
  { immediate: true },
)

const hrefError = computed(() => (touched.value && href.value.trim() === '' ? t('link.urlRequired') : ''))

const heading = computed(() => {
  if (props.canRemove) return t('link.editTitle')
  return props.external ? t('link.insertExternalTitle') : t('link.insertTitle')
})

// Titelvorschlag aus der Adresse — bewusst nur auf Zuruf, damit ein selbst
// eingetragener Titel nicht überschrieben wird.
function suggestTitle() {
  const suggestion = suggestLinkTitle(href.value)
  if (suggestion !== '') title.value = suggestion
}

function close() {
  emit('update:modelValue', false)
}

function submit() {
  touched.value = true
  if (href.value.trim() === '') return
  emit('submit', {
    href: href.value.trim(),
    text: text.value.trim(),
    title: title.value.trim(),
  })
  close()
}

function remove() {
  emit('remove')
  close()
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    @update:model-value="(v) => { if (!v) close() }"
  >
    <v-card>
      <v-card-title class="text-h6">{{ heading }}</v-card-title>
      <v-card-text>
        <!-- Schnelleingabe: das Schema an den Anfang, die Endungen an die
             Cursorposition. -->
        <div class="d-flex flex-wrap ga-2 mb-5">
          <v-btn
            size="x-small"
            variant="tonal"
            prepend-icon="mdi-web"
            :title="t('link.httpsHint')"
            @click="prependHttps"
          >
            https://
          </v-btn>
          <v-btn
            v-for="tld in tlds"
            :key="tld"
            size="x-small"
            variant="tonal"
            :title="t('link.tldHint', [tld])"
            @click="insertIntoHref(tld)"
          >
            {{ tld }}
          </v-btn>
        </div>
        <v-text-field
          ref="hrefField"
          v-model="href"
          :label="t('link.url')"
          :error-messages="hrefError"
          placeholder="https://example.org/seite/"
          variant="outlined"
          density="compact"
          spellcheck="false"
          autofocus
          class="mb-3"
          @keyup.enter="submit"
        />
        <v-text-field
          v-model="text"
          :label="t('link.text')"
          :hint="t('link.textHint')"
          variant="outlined"
          density="compact"
          persistent-hint
          class="mb-3"
          @keyup.enter="submit"
        />
        <!-- Der Vorschlag stammt allein aus der Adresse (die Zielseite wird
             nicht abgerufen) und ist als Ausgangspunkt gedacht. -->
        <v-text-field
          v-model="title"
          :label="t('link.title')"
          :hint="t('link.titleHint')"
          variant="outlined"
          density="compact"
          persistent-hint
          @keyup.enter="submit"
        >
          <template #append-inner>
            <v-tooltip :text="t('link.titleSuggest')" location="bottom">
              <template #activator="{ props: tip }">
                <v-btn
                  v-bind="tip"
                  icon="mdi-lightbulb-auto-outline"
                  variant="text"
                  size="x-small"
                  :aria-label="t('link.titleSuggest')"
                  @click="suggestTitle"
                />
              </template>
            </v-tooltip>
          </template>
        </v-text-field>
      </v-card-text>
      <v-card-actions>
        <v-btn v-if="canRemove" variant="text" color="warning" @click="remove">
          {{ t('link.remove') }}
        </v-btn>
        <v-spacer />
        <v-btn variant="text" @click="close">{{ t('link.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" @click="submit">
          {{ canRemove ? t('link.apply') : t('link.insert') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
