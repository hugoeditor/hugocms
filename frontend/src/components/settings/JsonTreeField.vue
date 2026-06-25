<script setup>
// Bettet den rekursiven Baum (JsonNode) für EINEN Wert ein (Objekt oder Array)
// und vermittelt zwischen JS-Wert (modelValue) und Knotenmodell. Genutzt im
// Hugo-Editor für strukturierte bekannte Blöcke und für unbekannte Schlüssel.
//
// Eigene Meldungen werden über Referenzgleichheit erkannt (der Aufrufer schreibt
// exakt den emittierten Wert zurück), damit der Baum bei eigenen Änderungen nicht
// neu aufgebaut wird und Fokus/Aufklappzustand behält.
import { ref, watch } from 'vue'
import JsonNode from '../JsonNode.vue'
import { valueToNode, nodeToValue } from '../../util/jsonFormat'

const props = defineProps({
  modelValue: { default: undefined },
})
const emit = defineEmits(['update:modelValue'])

const node = ref(valueToNode(props.modelValue, null))
let lastEmitted = props.modelValue

watch(
  () => props.modelValue,
  (v) => {
    if (v === lastEmitted) return
    node.value = valueToNode(v, null)
    lastEmitted = v
  },
)

function onChanged() {
  const value = nodeToValue(node.value)
  lastEmitted = value
  emit('update:modelValue', value)
}
</script>

<template>
  <JsonNode :node="node" parent-type="root" @changed="onChanged" />
</template>
