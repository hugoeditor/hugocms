<script setup>
// Editor für ein einzelnes Hugo-Menü (z. B. menu.main): Liste der Einträge mit
// Hinzufügen/Bearbeiten/Entfernen und Reihenfolge (weight). Meldet die geänderte
// Eintragsliste zurück; das Zusammensetzen in die Konfiguration macht der Aufrufer.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MenuItemDialog from './MenuItemDialog.vue'

const props = defineProps({
  menuName: { type: String, required: true },
  modelValue: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const dialogOpen = ref(false)
const editingItem = ref(null)
const editingIndex = ref(-1)

function openAdd() {
  editingItem.value = null
  editingIndex.value = -1
  dialogOpen.value = true
}
function openEdit(item, index) {
  editingItem.value = item
  editingIndex.value = index
  dialogOpen.value = true
}

function onSave(item) {
  const items = [...props.modelValue]
  if (editingIndex.value >= 0) {
    items[editingIndex.value] = item
  } else {
    item.weight = items.length > 0 ? Math.max(...items.map((i) => Number(i.weight) || 0)) + 1 : 1
    items.push(item)
  }
  emit('update:modelValue', items)
}

function onRemove(index) {
  const items = [...props.modelValue]
  items.splice(index, 1)
  emit('update:modelValue', items)
}

function move(index, dir) {
  const j = dir === 'up' ? index - 1 : index + 1
  if (j < 0 || j >= props.modelValue.length) return
  const items = [...props.modelValue]
  // weight mit tauschen, damit die Reihenfolge auch in Hugo erhalten bleibt.
  const w = items[j].weight
  items[j].weight = items[index].weight
  items[index].weight = w
  ;[items[index], items[j]] = [items[j], items[index]]
  emit('update:modelValue', items)
}
</script>

<template>
  <div>
    <div class="d-flex align-center mb-2">
      <span class="text-subtitle-2">{{ t('settings.menu.title', { name: menuName }) }}</span>
      <v-spacer />
      <v-btn size="small" variant="text" color="primary" prepend-icon="mdi-plus" @click="openAdd">
        {{ t('settings.menu.addItem') }}
      </v-btn>
    </div>

    <v-list density="compact" class="rounded border">
      <v-list-item
        v-for="(item, index) in modelValue"
        :key="item.identifier ?? index"
        :title="item.name"
        :subtitle="`${item.url} (${item.identifier})`"
        @click="openEdit(item, index)"
      >
        <template #append>
          <v-btn icon="mdi-arrow-up" size="x-small" variant="text" :disabled="index === 0" :title="t('settings.menu.moveUp')" @click.stop="move(index, 'up')" />
          <v-btn icon="mdi-arrow-down" size="x-small" variant="text" :disabled="index === modelValue.length - 1" :title="t('settings.menu.moveDown')" @click.stop="move(index, 'down')" />
          <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" :title="t('settings.menu.removeItem')" @click.stop="onRemove(index)" />
        </template>
      </v-list-item>

      <v-list-item v-if="modelValue.length === 0">
        <v-list-item-title class="text-medium-emphasis text-body-2">{{ t('common.noData') }}</v-list-item-title>
      </v-list-item>
    </v-list>

    <MenuItemDialog v-model="dialogOpen" :item="editingItem" @save="onSave" />
  </div>
</template>
