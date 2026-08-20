<script setup>
// Visueller Markdown-Editor (Stufe 4): TipTap mit Markdown-Round-Trip über
// tiptap-markdown. Erhält/liefert den Markdown-BODY (ohne Front-Matter —
// das schützt das EditorPanel davor, YAML durch den Editor zu schicken).
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import { Markdown } from 'tiptap-markdown'
import { useAuthStore } from '../stores/auth'
import LinkDialog from './LinkDialog.vue'
import { domainFromText } from '../util/linkSnippet'

const props = defineProps({
  modelValue: { type: String, default: '' },
  // Zustand des Speichern-Knopfs in der Formatleiste (vom EditorPanel).
  saveDisabled: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  // Wird ein Entwurf gerade abgelegt? (Ladezustand des Entwurf-Knopfs.)
  savingDraft: { type: Boolean, default: false },
  // Seitenvorschau anbieten? Nur für Content-Dateien eines Hugo-Projekts; das
  // entscheidet das EditorPanel, hier zählt nur der Zustand des Knopfs.
  canPreview: { type: Boolean, default: false },
  previewing: { type: Boolean, default: false },
})

const authStore = useAuthStore()
const emit = defineEmits(['update:modelValue', 'clipboard-denied', 'save', 'save-draft', 'preview'])
const { t } = useI18n()

// tiptap-markdown maskiert beim Serialisieren jedes < und > eines Text-Knotens
// zu &lt;/&gt;. Das zerstört Hugo-Shortcodes der Winkelklammer-Form
// ({{< … >}}), deren Delimiter genau diese Zeichen tragen. In manchen Fällen
// geht beim DOM-Round-Trip sogar die Richtung des Zeichens verloren (> wird zu
// <). Da die Winkelklammer-Delimiter IMMER {{< und >}} lauten, normalisieren
// wir sie direkt an den {{ / }}-Grenzen wieder auf die kanonische Form — das
// stellt auch ein verlorengegangenes > wieder her. Einzelne <, > in normaler
// Prosa bleiben unberührt; die Prozentform ({{% … %}}) ebenfalls.
function restoreHugoShortcodes(md) {
  return md
    .replace(/\{\{(?:&lt;|&gt;|<|>)/g, '{{<')
    .replace(/(?:&lt;|&gt;|<|>)\}\}/g, '>}}')
}

// Serialisierter Markdown-Body inklusive Shortcode-Wiederherstellung. In
// onUpdate UND im modelValue-watch dieselbe Quelle nutzen, sonst löst der
// Vergleich ein überflüssiges setContent aus (Cursor-Sprung bei jeder Eingabe).
function currentMarkdown(ed) {
  return restoreHugoShortcodes(ed.storage.markdown.getMarkdown())
}

// Zwischenablage: writeText/readText verlangen einen sicheren Kontext;
// beim Scheitern meldet clipboard-denied einen Hinweis auf Strg+X/C/V.
function selectionText(ed) {
  const { from, to } = ed.state.selection
  return ed.state.doc.textBetween(from, to, '\n')
}

function clipCut(ed) {
  const text = selectionText(ed)
  if (!text) return
  navigator.clipboard
    .writeText(text)
    .then(() => ed.chain().focus().deleteSelection().run())
    .catch(() => emit('clipboard-denied'))
}

function clipCopy(ed) {
  const text = selectionText(ed)
  if (!text) return
  navigator.clipboard.writeText(text).catch(() => emit('clipboard-denied'))
}

function clipPaste(ed) {
  navigator.clipboard
    .readText()
    .then((text) => {
      if (text === '') return
      // Über die reguläre Einfüge-Pipeline — so greift auch die
      // Markdown-Umwandlung von tiptap-markdown.
      ed.view.focus()
      ed.view.pasteText(text)
    })
    .catch(() => emit('clipboard-denied'))
}

// Markdown-Links tragen optional einen Titel ([Text](url "Titel")), aus dem
// Hugo das title-Attribut erzeugt. Die Link-Erweiterung kennt ihn nicht von
// Haus aus — ohne dieses Attribut ginge er beim Round-Trip verloren.
const MarkdownLink = Link.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      title: { default: null },
    }
  },
})

// --- Tabulator in der Schreibfläche ---------------------------------------
// Ohne eigene Behandlung reicht Tab den Fokus an das nächste Bedienelement
// weiter. Die Taste setzt jetzt ein Tabulator-Zeichen an der Cursorposition,
// Umschalt+Tab nimmt einen Einzug zurück. Ausnahme Listen: dort rückt die
// Taste den Listenpunkt eine Ebene ein beziehungsweise aus.
// Escape gibt die Taste für den nächsten Druck frei, damit die
// Tastaturbedienung nicht in der Schreibfläche gefangen bleibt.
let tabReleased = false

// Entfernt vor dem Cursor einen Tabulator oder bis zu vier Leerzeichen. Steht
// dort nichts dergleichen, bleibt der Text unverändert.
function outdent(ed) {
  const { $from, empty } = ed.state.selection
  if (!empty) return
  const before = $from.parent.textBetween(0, $from.parentOffset)
  const match = before.match(/(\t| {1,4})$/)
  if (!match) return
  ed.view.dispatch(ed.state.tr.delete($from.pos - match[0].length, $from.pos))
}

function handleKeyDown(view, event) {
  if (event.key === 'Escape') {
    tabReleased = true
    return false
  }
  if (event.key !== 'Tab') {
    tabReleased = false
    return false
  }
  const ed = editor.value
  if (tabReleased || !ed) return false
  // Ab hier gilt die Taste als behandelt (Rückgabe true), auch wenn sie nichts
  // bewirkt — sonst verschöbe der Browser den Fokus, sobald gerade nichts
  // ein- oder auszurücken ist (etwa Umschalt+Tab am Zeilenanfang).
  if (ed.isActive('listItem')) {
    if (event.shiftKey) ed.commands.liftListItem('listItem')
    else ed.commands.sinkListItem('listItem')
  } else if (event.shiftKey) {
    outdent(ed)
  } else {
    // insertText statt insertContent: letzteres schickt die Zeichenkette durch
    // den HTML-Parser, der den Tabulator zu einem Leerzeichen zusammenzieht.
    ed.view.dispatch(ed.state.tr.insertText('\t'))
  }
  return true
}

const editor = useEditor({
  content: props.modelValue,
  editorProps: { handleKeyDown },
  extensions: [
    StarterKit,
    // Zurückhaltend konfiguriert: keine Automatik, die aus getipptem oder
    // eingefügtem Text ungefragt einen Link macht — Links entstehen nur über
    // den Dialog. openOnClick würde im Editor zur Zielseite navigieren.
    MarkdownLink.configure({ openOnClick: false, autolink: false, linkOnPaste: false }),
    // html:false — vorhandenes Inline-HTML wird nicht gerendert, sondern
    // als Text erhalten; Hugo-Markdown bleibt so unangetastet wie möglich.
    Markdown.configure({ html: false, breaks: false }),
  ],
  onUpdate: ({ editor: ed }) => emit('update:modelValue', currentMarkdown(ed)),
})

// --- Link einfügen/bearbeiten ---------------------------------------------
const linkDialog = ref(false)
const linkInitial = ref({ href: '', text: '', title: '' })
const linkExisting = ref(false)
const linkExternal = ref(false)

// Externer Link: die Adresse steht schon auf „https://“. Der markierte Text
// füllt alle drei Felder — in der Adresse nur so weit, wie seine Zeichen in
// einem Rechnernamen zulässig sind (siehe domainFromText).
function openExternalLinkDialog(ed) {
  const selection = selectionText(ed).trim()
  linkExisting.value = false
  linkExternal.value = true
  linkInitial.value = {
    href: 'https://' + domainFromText(selection),
    text: selection,
    title: selection,
  }
  linkDialog.value = true
}

function openLinkDialog(ed) {
  // Steht der Cursor in einem Link, dessen ganze Spanne auswählen — so trägt
  // der Dialog den vorhandenen Text und das Übernehmen ersetzt den ganzen Link.
  const inLink = ed.isActive('link')
  if (inLink) ed.chain().focus().extendMarkRange('link').run()
  const attrs = ed.getAttributes('link')
  linkExisting.value = inLink
  linkExternal.value = false
  linkInitial.value = {
    href: attrs.href ?? '',
    title: attrs.title ?? '',
    text: selectionText(ed),
  }
  linkDialog.value = true
}

function applyLink({ href, text, title }) {
  const ed = editor.value
  if (!ed) return
  const attrs = { href, title: title === '' ? null : title }
  const label = text === '' ? href : text
  ed.chain()
    .focus()
    .extendMarkRange('link')
    .insertContent({ type: 'text', text: label, marks: [{ type: 'link', attrs }] })
    // Ohne das trüge der direkt danach getippte Text den Link weiter.
    .unsetMark('link')
    .run()
}

function removeLink() {
  editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
}

// Externe Änderungen (Moduswechsel) übernehmen, ohne Cursor-Schleifen.
watch(
  () => props.modelValue,
  (value) => {
    const ed = editor.value
    if (ed && value !== currentMarkdown(ed)) {
      ed.commands.setContent(value, false)
    }
  },
)

onBeforeUnmount(() => editor.value?.destroy())

const tools = computed(() => {
  const ed = editor.value
  if (!ed) return []
  return [
    {
      icon: 'mdi-content-save',
      label: t('editor.save'),
      active: false,
      disabled: props.saveDisabled || props.saving,
      run: () => emit('save'),
    },
    // Als Entwurf zur Freigabe ablegen (nur bei Hugo-Projekt).
    ...(authStore.review ? [{
      icon: 'mdi-content-save-edit-outline',
      label: t('editor.saveDraft'),
      active: false,
      disabled: props.saveDisabled || props.savingDraft,
      run: () => emit('save-draft'),
    }] : []),
    // Seitenvorschau — zeigt auch den ungespeicherten Stand, daher ohne
    // Abhängigkeit vom Speichern-Zustand.
    ...(props.canPreview ? [{
      icon: 'mdi-eye-outline',
      label: t('editor.preview'),
      active: false,
      disabled: props.previewing,
      run: () => emit('preview'),
    }] : []),
    { divider: true },
    { icon: 'mdi-undo', label: t('editor.undo'), active: false, disabled: !ed.can().undo(), run: () => ed.chain().focus().undo().run() },
    { icon: 'mdi-redo', label: t('editor.redo'), active: false, disabled: !ed.can().redo(), run: () => ed.chain().focus().redo().run() },
    { divider: true },
    { icon: 'mdi-content-cut', label: t('editor.cut'), active: false, run: () => clipCut(ed) },
    { icon: 'mdi-content-copy', label: t('editor.copy'), active: false, run: () => clipCopy(ed) },
    { icon: 'mdi-content-paste', label: t('editor.paste'), active: false, run: () => clipPaste(ed) },
    { divider: true },
    { icon: 'mdi-format-bold', label: t('wysiwyg.bold'), active: ed.isActive('bold'), run: () => ed.chain().focus().toggleBold().run() },
    { icon: 'mdi-format-italic', label: t('wysiwyg.italic'), active: ed.isActive('italic'), run: () => ed.chain().focus().toggleItalic().run() },
    { icon: 'mdi-format-strikethrough', label: t('wysiwyg.strike'), active: ed.isActive('strike'), run: () => ed.chain().focus().toggleStrike().run() },
    { divider: true },
    { icon: 'mdi-format-header-1', label: t('wysiwyg.h1'), active: ed.isActive('heading', { level: 1 }), run: () => ed.chain().focus().toggleHeading({ level: 1 }).run() },
    { icon: 'mdi-format-header-2', label: t('wysiwyg.h2'), active: ed.isActive('heading', { level: 2 }), run: () => ed.chain().focus().toggleHeading({ level: 2 }).run() },
    { icon: 'mdi-format-header-3', label: t('wysiwyg.h3'), active: ed.isActive('heading', { level: 3 }), run: () => ed.chain().focus().toggleHeading({ level: 3 }).run() },
    { divider: true },
    { icon: 'mdi-link-variant', label: ed.isActive('link') ? t('link.editTitle') : t('link.insertTitle'), active: ed.isActive('link'), run: () => openLinkDialog(ed) },
    { icon: 'mdi-open-in-new', label: t('link.insertExternalTitle'), active: false, run: () => openExternalLinkDialog(ed) },
    { icon: 'mdi-link-variant-off', label: t('link.remove'), active: false, disabled: !ed.isActive('link'), run: removeLink },
    { divider: true },
    { icon: 'mdi-format-list-bulleted', label: t('wysiwyg.bulletList'), active: ed.isActive('bulletList'), run: () => ed.chain().focus().toggleBulletList().run() },
    { icon: 'mdi-format-list-numbered', label: t('wysiwyg.orderedList'), active: ed.isActive('orderedList'), run: () => ed.chain().focus().toggleOrderedList().run() },
    { icon: 'mdi-format-quote-close', label: t('wysiwyg.blockquote'), active: ed.isActive('blockquote'), run: () => ed.chain().focus().toggleBlockquote().run() },
    { divider: true },
    { icon: 'mdi-code-tags', label: t('wysiwyg.inlineCode'), active: ed.isActive('code'), run: () => ed.chain().focus().toggleCode().run() },
    { icon: 'mdi-code-braces', label: t('wysiwyg.codeBlock'), active: ed.isActive('codeBlock'), run: () => ed.chain().focus().toggleCodeBlock().run() },
    { icon: 'mdi-minus', label: t('wysiwyg.hr'), active: false, run: () => ed.chain().focus().setHorizontalRule().run() },
  ]
})
</script>

<template>
  <div class="wysiwyg">
    <div class="wysiwyg-toolbar nemo-noselect">
      <template v-for="(tool, i) in tools" :key="i">
        <span v-if="tool.divider" class="wysiwyg-divider" />
        <v-tooltip v-else :text="tool.label" location="bottom">
          <template #activator="{ props: tip }">
            <button
              v-bind="tip"
              class="wysiwyg-btn"
              :class="{ active: tool.active }"
              :disabled="tool.disabled"
              @mousedown.prevent
              @click="tool.run()"
            >
              <v-icon :icon="tool.icon" size="18" />
            </button>
          </template>
        </v-tooltip>
      </template>
    </div>

    <EditorContent :editor="editor" class="wysiwyg-content nemo-scroll" />

    <!-- Link im Markdown-Body: Adresse, Text und Titel (siehe MarkdownLink). -->
    <LinkDialog
      v-model="linkDialog"
      :initial="linkInitial"
      :can-remove="linkExisting"
      :external="linkExternal"
      @submit="applyLink"
      @remove="removeLink"
    />
  </div>
</template>

<style scoped>
.wysiwyg {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.wysiwyg-toolbar {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 8px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  flex: 0 0 auto;
}
.wysiwyg-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: 1px solid transparent;
  border-radius: var(--mint-radius);
  background: transparent;
  color: var(--mint-text);
  cursor: pointer;
}
.wysiwyg-btn:hover:not(:disabled) {
  background: var(--mint-panel-hover);
  border-color: var(--mint-border);
}
.wysiwyg-btn:disabled {
  color: #b6b6b3;
  cursor: default;
}
.wysiwyg-btn.active {
  background: var(--mint-green-soft);
  border-color: #cfe0c5;
  color: var(--mint-green-soft-text);
}
.wysiwyg-divider {
  width: 1px;
  height: 20px;
  margin: 0 5px;
  background: var(--mint-border);
}

.wysiwyg-content {
  flex: 1 1 auto;
  overflow: auto;
  background: #fff;
}
.wysiwyg-content :deep(.ProseMirror) {
  min-height: 100%;
  padding: 24px 32px 48px;
  outline: none;
  /* Aus dem Standard-Stil von prosemirror-view (den TipTap nicht mitbringt):
     ohne pre-wrap zöge der Browser einen mit Tab gesetzten Tabulator zu einem
     Leerzeichen zusammen — er stünde im Markdown, wäre aber nicht zu sehen. */
  white-space: pre-wrap;
  word-wrap: break-word;
  font-size: 0.95rem;
  line-height: 1.6;
  color: var(--mint-text);
}
.wysiwyg-content :deep(.ProseMirror h1),
.wysiwyg-content :deep(.ProseMirror h2),
.wysiwyg-content :deep(.ProseMirror h3) {
  margin: 1.1em 0 0.4em;
  line-height: 1.25;
}
.wysiwyg-content :deep(.ProseMirror a) {
  color: var(--mint-green-soft-text);
  text-decoration: underline;
  cursor: text; /* openOnClick ist aus — der Link wird bearbeitet, nicht gefolgt */
}
.wysiwyg-content :deep(.ProseMirror blockquote) {
  border-left: 3px solid var(--mint-green);
  margin: 0.6em 0;
  padding: 2px 12px;
  color: var(--mint-text-muted);
}
.wysiwyg-content :deep(.ProseMirror pre) {
  background: #f4f4f3;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  padding: 10px 12px;
  font-size: 0.85rem;
  overflow-x: auto;
}
.wysiwyg-content :deep(.ProseMirror code) {
  background: #f4f4f3;
  border-radius: 3px;
  padding: 1px 4px;
  font-size: 0.85em;
}
.wysiwyg-content :deep(.ProseMirror pre code) {
  background: none;
  padding: 0;
}
</style>
