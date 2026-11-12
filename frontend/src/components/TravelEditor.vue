<script setup>
// Formulaire de saisie d'un trajet ou d'une dépense, déplié sous l'élément cliqué de l'onglet Voyages
import { onMounted, ref } from 'vue'
import ExpensePanel from './ExpensePanel.vue'
import LegPanel from './LegPanel.vue'

defineProps({ panel: { type: Object, required: true }, ev: { type: Object, required: true }, apply: { type: Function, required: true } })
const emit = defineEmits(['close'])
const el = ref(null)
onMounted(() => el.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
</script>

<template>
  <div ref="el" class="inline-editor">
    <ExpensePanel v-if="panel.isExpense" :key="`x-${panel.part.id}-${panel.direction}-${panel.expense?.id}`" :ev="ev" :part="panel.part"
      :direction="panel.direction" :expense="panel.expense" :apply="apply" @close="emit('close')" />
    <LegPanel v-else :key="`${panel.part.id}-${panel.direction}-${panel.leg?.id}`" :ev="ev" :part="panel.part" :direction="panel.direction"
      :leg="panel.leg" :apply="apply" @close="emit('close')" />
  </div>
</template>

<style scoped>
.inline-editor { scroll-margin: 20px 0; }
@media (max-width: 899px) { .inline-editor { scroll-margin-top: 84px; } }
.inline-editor :deep(.panel) { position: static; border: 2px solid var(--violet); }
/* Pleine largeur : champs sur plusieurs colonnes (2 sur téléphone) */
.inline-editor :deep(.form-grid) { grid-template-columns: repeat(auto-fill, minmax(min(170px, 40%), 1fr)) !important; }
.inline-editor :deep(.route) { max-width: 560px; }
</style>
