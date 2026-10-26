<script setup>
import { ref, provide, watch } from 'vue'
import { get, showError } from '../api.js'
import { fperiod } from '../format.js'

const props = defineProps({ id: String })
const ev = ref(null)
const TABS = [['', 'Aperçu'], ['team', 'Équipe'], ['stay', 'Séjour'], ['travel', 'Voyages'], ['quote', 'Devis'], ['roadmap', 'Feuille de route']]

watch(() => props.id, async (id) => { ev.value = null; ev.value = await get(`/events/${id}`) }, { immediate: true })

/** Chaque modification renvoie la date complète : on remplace l'état. Retourne true si l'enregistrement a réussi. */
async function apply(promise) {
  try { ev.value = await promise; return true } catch (e) { showError(e); return false }
}
provide('event', { ev, apply })
</script>

<template>
  <template v-if="ev">
    <div class="muted small"><RouterLink :to="`/companies/${ev.companyId}`">{{ ev.companyName }}</RouterLink> › {{ ev.showTitle }}</div>
    <h1>{{ ev.venue }}<span v-if="ev.city" class="muted"> · {{ ev.city }}</span></h1>
    <div class="muted">{{ ev.showTitle }} — {{ fperiod(ev.firstDate, ev.lastDate) }}
      <span v-if="ev.status === 'option'" class="badge warn">option</span><span v-else-if="ev.status === 'cancelled'" class="badge bad">annulé</span>
    </div>
    <div class="tabs">
      <RouterLink v-for="[key, label] in TABS" :key="key" :to="`/events/${ev.id}${key ? '/' + key : ''}`"
        :class="{ on: $route.path === `/events/${ev.id}${key ? '/' + key : ''}` }">{{ label }}</RouterLink>
    </div>
    <RouterView />
  </template>
</template>
