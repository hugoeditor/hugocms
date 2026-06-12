<script setup>
// Visueller Markdown-Editor (Stufe 4): TipTap mit Markdown-Round-Trip über
// tiptap-markdown. Erhält/liefert den Markdown-BODY (ohne Front-Matter —
// das schützt das EditorPanel davor, YAML durch den Editor zu schicken).
import { computed, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from 'tiptap-markdown'

const props = defineProps({
  modelValue: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit,
    // html:false — vorhandenes Inline-HTML wird nicht gerendert, sondern
    // als Text erhalten; Hugo-Markdown bleibt so unangetastet wie möglich.
    Markdown.configure({ html: false, breaks: false }),
  ],
  onUpdate: ({ editor: ed }) => emit('update:modelValue', ed.storage.markdown.getMarkdown()),
})

// Externe Änderungen (Moduswechsel) übernehmen, ohne Cursor-Schleifen.
watch(
  () => props.modelValue,
  (value) => {
    const ed = editor.value
    if (ed && value !== ed.storage.markdown.getMarkdown()) {
      ed.commands.setContent(value, false)
    }
  },
)

onBeforeUnmount(() => editor.value?.destroy())

const tools = computed(() => {
  const ed = editor.value
  if (!ed) return []
  return [
    { icon: 'mdi-format-bold', label: t('wysiwyg.bold'), active: ed.isActive('bold'), run: () => ed.chain().focus().toggleBold().run() },
    { icon: 'mdi-format-italic', label: t('wysiwyg.italic'), active: ed.isActive('italic'), run: () => ed.chain().focus().toggleItalic().run() },
    { icon: 'mdi-format-strikethrough', label: t('wysiwyg.strike'), active: ed.isActive('strike'), run: () => ed.chain().focus().toggleStrike().run() },
    { divider: true },
    { icon: 'mdi-format-header-1', label: t('wysiwyg.h1'), active: ed.isActive('heading', { level: 1 }), run: () => ed.chain().focus().toggleHeading({ level: 1 }).run() },
    { icon: 'mdi-format-header-2', label: t('wysiwyg.h2'), active: ed.isActive('heading', { level: 2 }), run: () => ed.chain().focus().toggleHeading({ level: 2 }).run() },
    { icon: 'mdi-format-header-3', label: t('wysiwyg.h3'), active: ed.isActive('heading', { level: 3 }), run: () => ed.chain().focus().toggleHeading({ level: 3 }).run() },
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
.wysiwyg-btn:hover {
  background: var(--mint-panel-hover);
  border-color: var(--mint-border);
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
