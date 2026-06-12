<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { EditorView, basicSetup } from 'codemirror'
import { EditorState, Prec } from '@codemirror/state'
import { keymap } from '@codemirror/view'
import { StreamLanguage } from '@codemirror/language'
import { html } from '@codemirror/lang-html'
import { markdown } from '@codemirror/lang-markdown'
import { css } from '@codemirror/lang-css'
import { javascript } from '@codemirror/lang-javascript'
import { json } from '@codemirror/lang-json'
import { yaml } from '@codemirror/lang-yaml'
import { xml } from '@codemirror/lang-xml'
import { toml } from '@codemirror/legacy-modes/mode/toml'

const props = defineProps({
  modelValue: { type: String, default: '' },
  filename: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'save'])

const host = ref(null)
let view = null

function languageExtension(name) {
  const ext = name.split('.').pop()?.toLowerCase()
  switch (ext) {
    case 'html':
    case 'htm':
      return [html()]
    case 'xml':
    case 'svg':
    case 'rss':
      return [xml()]
    case 'md':
    case 'markdown':
      return [markdown()]
    case 'css':
      return [css()]
    case 'js':
      return [javascript()]
    case 'json':
      return [json()]
    case 'yaml':
    case 'yml':
      return [yaml()]
    case 'toml':
      return [StreamLanguage.define(toml)]
    default:
      return []
  }
}

onMounted(() => {
  const saveKeymap = Prec.highest(
    keymap.of([
      { key: 'Mod-s', preventDefault: true, run: () => { emit('save'); return true } },
    ]),
  )
  const updateListener = EditorView.updateListener.of((v) => {
    if (v.docChanged) emit('update:modelValue', v.state.doc.toString())
  })

  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        basicSetup,
        saveKeymap,
        updateListener,
        EditorView.lineWrapping,
        ...languageExtension(props.filename),
      ],
    }),
  })
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
