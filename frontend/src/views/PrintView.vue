<script setup>
import { ref, watch } from 'vue'
import { get } from '../api.js'

/** Aperçu d'un document PDF généré par le serveur, avec téléchargement. */
const props = defineProps({ id: String, doc: String })
const TITLES = { roadmap: 'Feuille de route', rooming: 'Rooming-list', perdiem: 'Défraiements', travelcost: 'Suivi des frais de voyage', contacts: "Coordonnées de l'équipe", quote: 'Devis' }
const ev = ref(null)
watch(() => props.id, async (id) => {
  ev.value = await get(`/events/${id}`)
  document.title = `${TITLES[props.doc]} — ${ev.value.showTitle} — ${ev.value.venue}`
}, { immediate: true })
const pdf = (download = false) => `/api/events/${props.id}/pdf/${props.doc}${download ? '?download=1' : ''}`
</script>

<template>
  <div class="pdf-page">
    <div class="docbar">
      <RouterLink class="btn sec" style="background:#fff" :to="`/events/${id}`">← Retour</RouterLink>
      <b v-if="ev" class="pdf-title">{{ TITLES[doc] }} · {{ ev.showTitle }} · {{ ev.venue }}</b>
      <a class="btn" :href="pdf(true)">⬇ Télécharger le PDF</a>
    </div>
    <iframe :src="pdf()" :title="TITLES[doc]" class="pdf-frame" />
  </div>
</template>
