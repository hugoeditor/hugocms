<script setup>
// Rekursive Knotenzeile des JSON-Baums — im Stil des Front-Matter-Editors:
// Typ als Icon-Menü, dann Schlüssel/Index, dann typgerechtes Eingabefeld, dann
// Aktionen. Objekte und Arrays werden als klappbare Panels mit ihren Kindern
// dargestellt.
//
// Strukturelle Aktionen an einem Knoten betreffen Daten, die der ELTERN-Knoten
// besitzt — ein Knoten meldet daher „remove“/„move“ nach oben und verwaltet
// umgekehrt die Aktionen SEINER Kinder selbst. Inhaltliche Änderungen blubbern
// als „changed“ hoch.
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { NODE_TYPES, emptyNode, coerceNodeType, uniqueKey } from '../util/jsonFormat'

defineOptions({ name: 'JsonNode' })

const props = defineProps({
  node: { type: Object, required: true },
  parentType: { type: String, default: 'root' }, // 'root' | 'object' | 'array'
  index: { type: Number, default: 0 },
})
const emit = defineEmits(['changed', 'remove', 'move'])
const { t } = useI18n()

const TYPE_ICON = {
  object: 'mdi-code-braces',
  array: 'mdi-code-brackets',
  string: 'mdi-format-text',
  number: 'mdi-numeric',
  boolean: 'mdi-toggle-switch-outline',
  null: 'mdi-circle-off-outline',
}

const isContainer = computed(() => props.node.type === 'object' || props.node.type === 'array')
const isRoot = computed(() => props.parentType === 'root')
const showActions = computed(() => props.parentType === 'object' || props.parentType === 'array')
const showKey = computed(() => props.parentType === 'object')
const showIndex = computed(() => props.parentType === 'array')

const expanded = ref(true)
const typeItems = computed(() => NODE_TYPES.map((ty) => ({ value: ty, title: t(`json.types.${ty}`) })))

// Kurzfassung des Container-Inhalts für den Panel-Kopf.
const summary = computed(() => {
  const n = props.node.children.length
  const braces = props.node.type === 'array' ? '[ ]' : '{ }'
  return n ? `${braces} ${n}` : `${braces} ${t('json.empty')}`
})

// Zahlenfeld mit eigenem Textzustand: ungültige Eingaben markieren das Feld,
// ohne den (gültigen) Knotenwert zu überschreiben.
const numText = ref(String(props.node.value ?? ''))
const numError = ref(false)
watch(
  () => props.node.value,
  (v) => { if (props.node.type === 'number') numText.value = String(v ?? '') },
)

function onKey(v) {
  props.node.key = v
  emit('changed')
}
function onString(v) {
  props.node.value = v
  emit('changed')
}
function onBool(v) {
  props.node.value = !!v
  emit('changed')
}
function onNumber(v) {
  numText.value = v
  const n = Number(v)
  if (v !== '' && Number.isFinite(n)) {
    numError.value = false
    props.node.value = n
    emit('changed')
  } else {
    numError.value = true
  }
}
function onType(ty) {
  coerceNodeType(props.node, ty)
  if (ty === 'number') {
    numText.value = String(props.node.value ?? '0')
    numError.value = false
  }
  if (isContainer.value) expanded.value = true
  emit('changed')
}

function addChild() {
  if (props.node.type === 'array') {
    props.node.children.push(emptyNode('string', null))
  } else {
    props.node.children.push(emptyNode('string', uniqueKey(props.node.children)))
  }
  expanded.value = true
  emit('changed')
}
function removeChild(i) {
  props.node.children.splice(i, 1)
  emit('changed')
}
function moveChild(i, dir) {
  const j = dir === 'up' ? i - 1 : i + 1
  if (j < 0 || j >= props.node.children.length) return
  const arr = props.node.children
  ;[arr[i], arr[j]] = [arr[j], arr[i]]
  emit('changed')
}
</script>

<template>
  <!-- Wurzel-Container: nur die Kinder, ohne eigene Panel-Hülle (die Sektion
       darüber ist bereits der Rahmen). -->
  <div v-if="isRoot && isContainer" class="jn-root">
    <JsonNode
      v-for="(child, i) in node.children"
      :key="child.id"
      :node="child"
      :parent-type="node.type"
      :index="i"
      @changed="$emit('changed')"
      @remove="removeChild(i)"
      @move="moveChild(i, $event)"
    />
    <div class="jn-add">
      <v-btn size="small" variant="text" color="primary" prepend-icon="mdi-plus" @click="addChild">
        {{ node.type === 'array' ? t('json.addItem') : t('json.addProperty') }}
      </v-btn>
    </div>
  </div>

  <!-- Objekt/Array: klappbares Panel -->
  <div v-else-if="isContainer" class="jn-panel">
    <div class="jn-panel-head">
      <button type="button" class="jn-toggle" @click="expanded = !expanded">
        <v-icon :icon="expanded ? 'mdi-menu-down' : 'mdi-menu-right'" size="20" />
      </button>

      <v-menu>
        <template #activator="{ props: menu }">
          <v-btn v-bind="menu" :icon="TYPE_ICON[node.type]" size="small" variant="text" density="comfortable" class="jn-type" />
        </template>
        <v-list density="compact" min-width="170">
          <v-list-subheader>{{ t('settings.fieldType') }}</v-list-subheader>
          <v-list-item
            v-for="it in typeItems"
            :key="it.value"
            :prepend-icon="TYPE_ICON[it.value]"
            :title="it.title"
            :active="node.type === it.value"
            @click="onType(it.value)"
          />
        </v-list>
      </v-menu>

      <span v-if="showIndex" class="jn-index">{{ index }}</span>
      <v-text-field
        v-else-if="showKey"
        :model-value="node.key"
        :placeholder="t('json.key')"
        variant="plain"
        density="compact"
        hide-details
        spellcheck="false"
        class="jn-key"
        @update:model-value="onKey"
      />

      <button type="button" class="jn-summary" @click="expanded = !expanded">{{ summary }}</button>

      <v-spacer />

      <div v-if="showActions" class="jn-actions">
        <v-btn icon="mdi-arrow-up" size="x-small" variant="text" :title="t('json.moveUp')" @click="$emit('move', 'up')" />
        <v-btn icon="mdi-arrow-down" size="x-small" variant="text" :title="t('json.moveDown')" @click="$emit('move', 'down')" />
        <v-btn icon="mdi-close" size="x-small" variant="text" :title="t('json.remove')" @click="$emit('remove')" />
      </div>
    </div>

    <div v-show="expanded" class="jn-panel-body">
      <JsonNode
        v-for="(child, i) in node.children"
        :key="child.id"
        :node="child"
        :parent-type="node.type"
        :index="i"
        @changed="$emit('changed')"
        @remove="removeChild(i)"
        @move="moveChild(i, $event)"
      />
      <div class="jn-add">
        <v-btn size="small" variant="text" color="primary" prepend-icon="mdi-plus" @click="addChild">
          {{ node.type === 'array' ? t('json.addItem') : t('json.addProperty') }}
        </v-btn>
      </div>
    </div>
  </div>

  <!-- Skalarer Knoten: einzeilige Zeile -->
  <div v-else class="jn-row">
    <v-menu>
      <template #activator="{ props: menu }">
        <v-btn v-bind="menu" :icon="TYPE_ICON[node.type]" size="small" variant="text" density="comfortable" class="jn-type" />
      </template>
      <v-list density="compact" min-width="170">
        <v-list-subheader>{{ t('settings.fieldType') }}</v-list-subheader>
        <v-list-item
          v-for="it in typeItems"
          :key="it.value"
          :prepend-icon="TYPE_ICON[it.value]"
          :title="it.title"
          :active="node.type === it.value"
          @click="onType(it.value)"
        />
      </v-list>
    </v-menu>

    <span v-if="showIndex" class="jn-index">{{ index }}</span>
    <v-text-field
      v-else-if="showKey"
      :model-value="node.key"
      :placeholder="t('json.key')"
      variant="plain"
      density="compact"
      hide-details
      spellcheck="false"
      class="jn-key"
      @update:model-value="onKey"
    />

    <div class="jn-value">
      <v-switch
        v-if="node.type === 'boolean'"
        :model-value="node.value"
        :label="node.value ? 'true' : 'false'"
        color="primary"
        density="comfortable"
        hide-details
        @update:model-value="onBool"
      />
      <span v-else-if="node.type === 'null'" class="jn-null">null</span>
      <v-text-field
        v-else-if="node.type === 'number'"
        :model-value="numText"
        type="number"
        variant="outlined"
        density="compact"
        hide-details
        :error="numError"
        @update:model-value="onNumber"
      />
      <v-text-field
        v-else
        :model-value="node.value"
        variant="outlined"
        density="compact"
        hide-details
        spellcheck="false"
        @update:model-value="onString"
      />
    </div>

    <div v-if="showActions" class="jn-actions">
      <v-btn icon="mdi-arrow-up" size="x-small" variant="text" :title="t('json.moveUp')" @click="$emit('move', 'up')" />
      <v-btn icon="mdi-arrow-down" size="x-small" variant="text" :title="t('json.moveDown')" @click="$emit('move', 'down')" />
      <v-btn icon="mdi-close" size="x-small" variant="text" :title="t('json.remove')" @click="$emit('remove')" />
    </div>
  </div>
</template>

<style scoped>
.jn-root {
  display: flex;
  flex-direction: column;
}
.jn-panel {
  border: 1px solid var(--mint-border);
  border-radius: 6px;
  margin: 3px 0;
  background: var(--mint-panel);
}
.jn-panel-head {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 2px 4px 2px 2px;
}
.jn-panel-body {
  padding: 2px 8px 6px 20px;
  border-top: 1px solid var(--mint-border);
}
.jn-toggle {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  padding: 0;
  border: none;
  background: transparent;
  cursor: pointer;
  color: var(--mint-text-muted);
}
.jn-row {
  display: flex;
  align-items: flex-start;
  gap: 4px;
  padding: 3px 0;
}
.jn-type {
  flex: 0 0 auto;
  margin-top: 2px;
  color: var(--mint-text-muted);
}
.jn-index {
  flex: 0 0 auto;
  width: 32px;
  margin-top: 6px;
  text-align: right;
  font-size: 0.8rem;
  color: var(--mint-text-muted);
  font-variant-numeric: tabular-nums;
}
.jn-key {
  flex: 0 0 200px;
  font-weight: 600;
}
.jn-key :deep(input) {
  font-weight: 600;
  font-size: 0.86rem;
}
.jn-summary {
  flex: 0 0 auto;
  margin-left: 4px;
  border: none;
  background: transparent;
  cursor: pointer;
  font-size: 0.82rem;
  color: var(--mint-text-muted);
}
.jn-value {
  flex: 1 1 auto;
  min-width: 0;
}
.jn-null {
  display: inline-block;
  margin-top: 6px;
  font-style: italic;
  color: var(--mint-text-muted);
}
.jn-actions {
  flex: 0 0 auto;
  display: flex;
  margin-top: 2px;
}
.jn-add {
  padding: 2px 0;
}
</style>
