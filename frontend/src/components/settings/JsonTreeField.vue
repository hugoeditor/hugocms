<script setup>
// Bettet den rekursiven Baum (JsonNode) für EINEN Wert ein (Objekt oder Array)
// und vermittelt zwischen JS-Wert (modelValue) und Knotenmodell. Genutzt im
// Hugo-Editor für strukturierte bekannte Blöcke und für unbekannte Schlüssel.
//
// Eigene Meldungen werden STRUKTURELL erkannt (per JSON-Vergleich), damit der
// Baum bei eigenen Änderungen nicht neu aufgebaut wird und Fokus/Aufklappzustand
// behält. Referenzgleichheit reicht nicht: Vue verpackt den im Aufrufer
// abgelegten Wert in einen reaktiven Proxy, der beim Zurücklesen eine andere
// Objektreferenz ist als der gemeldete Rohwert.
import { ref, watch } from 'vue'
import JsonNode from '../JsonNode.vue'
import { valueToNode, nodeToValue } from '../../util/jsonFormat'

const props = defineProps({
  modelValue: { default: undefined },
})
const emit = defineEmits(['update:modelValue'])

const node = ref(valueToNode(props.modelValue, null))
let lastEmitted = JSON.stringify(props.modelValue ?? null)

watch(
  () => props.modelValue,
  (v) => {
    const sig = JSON.stringify(v ?? null)
    if (sig === lastEmitted) return // eigene Meldung – Baum nicht neu aufbauen
    node.value = valueToNode(v, null)
    lastEmitted = sig
  },
)

function onChanged() {
  const value = nodeToValue(node.value)
  lastEmitted = JSON.stringify(value)
  emit('update:modelValue', value)
}
</script>

<template>
  <JsonNode :node="node" parent-type="root" @changed="onChanged" />
</template>
