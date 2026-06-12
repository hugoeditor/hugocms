<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EditorView, basicSetup } from 'codemirror'
import { EditorState, Prec } from '@codemirror/state'
import { keymap } from '@codemirror/view'
import { StreamLanguage, foldAll, unfoldAll } from '@codemirror/language'
import { undo, redo, undoDepth, redoDepth, indentMore, indentLess, toggleComment } from '@codemirror/commands'
import { openSearchPanel, gotoLine } from '@codemirror/search'
import { html } from '@codemirror/lang-html'
import { markdown } from '@codemirror/lang-markdown'
import { css } from '@codemirror/lang-css'
import { javascript } from '@codemirror/lang-javascript'
import { json } from '@codemirror/lang-json'
import { yaml } from '@codemirror/lang-yaml'
import { xml } from '@codemirror/lang-xml'
import { toml } from '@codemirror/legacy-modes/mode/toml'
import { linter, lintGutter } from '@codemirror/lint'
import { jsonParseLinter } from '@codemirror/lang-json'
import { parseDocument } from 'yaml'

const props = defineProps({
  modelValue: { type: String, default: '' },
  filename: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'save', 'cursor', 'language', 'history'])

const { locale } = useI18n()
const host = ref(null)
let view = null

// Deutsche Texte für die eingebauten CodeMirror-Panels (Suche, Gehe zu
// Zeile, Diagnosen). Die Schlüssel sind von CodeMirror vorgegeben.
const PHRASES_DE = {
  'Find': 'Suchen',
  'Replace': 'Ersetzen',
  'next': 'weiter',
  'previous': 'zurück',
  'all': 'alle',
  'match case': 'Groß-/Kleinschreibung',
  'by word': 'ganzes Wort',
  'regexp': 'regulärer Ausdruck',
  'replace': 'ersetzen',
  'replace all': 'alle ersetzen',
  'close': 'schließen',
  'current match': 'aktueller Treffer',
  'replaced $ matches': '$ Treffer ersetzt',
  'replaced match on line $': 'Treffer in Zeile $ ersetzt',
  'on line': 'in Zeile',
  'Go to line': 'Gehe zu Zeile',
  'go': 'los',
  'Diagnostics': 'Diagnosen',
  'No diagnostics': 'Keine Diagnosen',
}

// Von der Werkzeugleiste im EditorPanel aufrufbare CodeMirror-Befehle.
const commands = { undo, redo, indentMore, indentLess, toggleComment, openSearchPanel, gotoLine, foldAll, unfoldAll }
// Befehle, die ein Eingabe-Panel öffnen — der Fokus gehört danach dem Panel.
const panelCommands = new Set(['openSearchPanel', 'gotoLine'])

function exec(name) {
  const cmd = commands[name]
  if (!view || !cmd) return
  cmd(view)
  if (!panelCommands.has(name)) view.focus()
}
defineExpose({ exec })

// Prüft YAML beim Tippen mit dem yaml-Parser; Fehler und Warnungen tragen
// Byte-Bereiche (pos), die direkt als Diagnose-Spannen dienen.
function yamlLinter() {
  return linter((view) => {
    const doc = parseDocument(view.state.doc.toString())
    const max = view.state.doc.length
    const toDiagnostic = (severity) => (e) => {
      const [from = 0, to = from] = e.pos ?? []
      return {
        from: Math.min(from, max),
        to: Math.min(Math.max(to, from), max),
        severity,
        message: e.message,
      }
    }
    return [
      ...doc.errors.map(toDiagnostic('error')),
      ...doc.warnings.map(toDiagnostic('warning')),
    ]
  })
}

// Liefert zur Dateiendung das Anzeige-Etikett (für die Statuszeile) und die
// CodeMirror-Erweiterungen (Hervorhebung, ggf. Linting). label null = Klartext.
function fileLanguage(name) {
  const ext = name.split('.').pop()?.toLowerCase()
  switch (ext) {
    case 'html':
    case 'htm':
      return { label: 'HTML', extensions: [html()] }
    case 'xml':
    case 'svg':
    case 'rss':
      return { label: 'XML', extensions: [xml()] }
    case 'md':
    case 'markdown':
      return { label: 'Markdown', extensions: [markdown()] }
    case 'css':
      return { label: 'CSS', extensions: [css()] }
    case 'js':
      return { label: 'JavaScript', extensions: [javascript()] }
    case 'json':
      return { label: 'JSON', extensions: [json(), linter(jsonParseLinter()), lintGutter()] }
    case 'yaml':
    case 'yml':
      return { label: 'YAML', extensions: [yaml(), yamlLinter(), lintGutter()] }
    case 'toml':
      return { label: 'TOML', extensions: [StreamLanguage.define(toml)] }
    default:
      return { label: null, extensions: [] }
  }
}

// Cursorposition (1-basiert) für die Statuszeile melden.
function emitCursor(state) {
  const head = state.selection.main.head
  const line = state.doc.lineAt(head)
  emit('cursor', { line: line.number, column: head - line.from + 1 })
}

onMounted(() => {
  const saveKeymap = Prec.highest(
    keymap.of([
      { key: 'Mod-s', preventDefault: true, run: () => { emit('save'); return true } },
    ]),
  )
  const updateListener = EditorView.updateListener.of((v) => {
    if (v.docChanged) {
      emit('update:modelValue', v.state.doc.toString())
      emit('history', { undo: undoDepth(v.state) > 0, redo: redoDepth(v.state) > 0 })
    }
    if (v.docChanged || v.selectionSet) emitCursor(v.state)
  })

  const lang = fileLanguage(props.filename)
  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        ...(locale.value === 'de' ? [EditorState.phrases.of(PHRASES_DE)] : []),
        basicSetup,
        saveKeymap,
        updateListener,
        EditorView.lineWrapping,
        ...lang.extensions,
      ],
    }),
  })
  emit('language', lang.label)
  emit('history', { undo: false, redo: false })
  emitCursor(view.state)
})

onBeforeUnmount(() => {
  view?.destroy()
})
</script>

<template>
  <div ref="host" class="cm-host"></div>
</template>

<style scoped>
.cm-host {
  height: 100%;
  overflow: auto;
}
.cm-host :deep(.cm-editor) {
  height: 100%;
}
</style>
