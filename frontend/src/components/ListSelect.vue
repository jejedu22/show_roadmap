<script setup>
import { computed } from 'vue'
import { lists, loadLists } from '../lists.js'

/**
 * Liste déroulante alimentée par une liste paramétrée. Une valeur déjà enregistrée mais absente de la liste
 * (ancienne saisie, entrée retirée) reste affichée « hors liste » pour ne rien perdre.
 */
const props = defineProps({ modelValue: { type: String, default: '' }, kind: String, required: Boolean, empty: { type: String, default: '—' } })
const emit = defineEmits(['update:modelValue'])
loadLists()
const entries = computed(() => lists[props.kind] || [])
const outside = computed(() => props.modelValue && !entries.value.includes(props.modelValue))
</script>

<template>
  <select :value="modelValue" :required="required" @change="emit('update:modelValue', $event.target.value)">
    <option value="" :disabled="required">{{ empty }}</option>
    <option v-if="outside" :value="modelValue">{{ modelValue }} (hors liste)</option>
    <option v-for="e in entries" :key="e" :value="e">{{ e }}</option>
  </select>
</template>
