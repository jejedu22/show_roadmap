<script setup>
import { ref, watch } from 'vue'
import { post, put, toast } from '../api.js'
import { fdate } from '../format.js'

/** Échéance d'un lien de partage : date modifiable ; lien expiré → réactivation (nouvelle échéance par défaut). */
const props = defineProps({ ev: Object, link: Object, path: String, apply: Function })
const value = ref('')
watch(() => props.link.expiresOn, (v) => { value.value = v || '' }, { immediate: true })
const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Paris' })

async function save() {
  if (value.value && value.value !== props.link.expiresOn && await props.apply(put(`/events/${props.ev.id}/${props.path}`, { expiresOn: value.value }))) {
    toast(`Lien valable jusqu'au ${fdate(value.value)}`)
  }
}
async function renew() {
  if (await props.apply(post(`/events/${props.ev.id}/${props.path}`))) toast('Lien réactivé')
}
</script>

<template>
  <p v-if="!link.active" class="alert" style="margin:0">Ce lien a expiré{{ link.expiresOn ? ` le ${fdate(link.expiresOn)}` : '' }} : il ne s'ouvre plus.
    <button type="button" class="btn btn-sm" style="margin-left:6px" @click="renew">Réactiver jusqu'au {{ fdate(ev.share.defaultExpiresOn) }}</button></p>
  <label v-else class="field" style="max-width:260px">Valable jusqu'au (inclus)
    <input v-model="value" type="date" :min="today" :max="ev.share.maxExpiresOn" required @change="save"></label>
</template>
