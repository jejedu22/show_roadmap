<script setup>
import { ref, watch } from 'vue'
import { get } from '../api.js'
import Icon from '../components/Icon.vue'

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
    <div class="pdf-bar">
      <RouterLink class="btn btn-sm" :to="`/events/${id}`"><Icon name="left" :size="16" />Retour</RouterLink>
      <b v-if="ev" class="pdf-title">{{ TITLES[doc] }} · {{ ev.showTitle }} · {{ ev.venue }}</b>
      <a class="btn btn-sm btn-primary" :href="pdf(true)"><Icon name="download" :size="16" />Télécharger le PDF</a>
    </div>
    <iframe :src="pdf()" :title="TITLES[doc]" class="pdf-frame" />
  </div>
</template>
