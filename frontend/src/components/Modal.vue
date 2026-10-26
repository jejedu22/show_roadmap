<script setup>
import { ref, watch, onMounted } from 'vue'
import Icon from './Icon.vue'

/** Fenêtre modale accessible (élément <dialog> natif : focus piégé, Échap pour fermer). */
const props = defineProps({ open: Boolean, title: String })
const emit = defineEmits(['close'])
const dialog = ref(null)
const sync = () => {
  if (!dialog.value) return
  if (props.open && !dialog.value.open) dialog.value.showModal()
  if (!props.open && dialog.value.open) dialog.value.close()
}
watch(() => props.open, sync)
onMounted(sync)
</script>

<template>
  <dialog ref="dialog" class="modal" :aria-label="title" @close="emit('close')" @cancel.prevent="emit('close')">
    <div class="modal-head">
      <h2>{{ title }}</h2>
      <button type="button" aria-label="Fermer" @click="emit('close')"><Icon name="close" :size="22" /></button>
    </div>
    <div class="modal-body"><slot /></div>
  </dialog>
</template>
