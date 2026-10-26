<script setup>
import { inject, reactive, ref, watch } from 'vue'
import { post, put, del } from '../../api.js'

const { ev, apply } = inject('event')
const slots = reactive({})
const sections = reactive({})
const newSlot = ref({})
const newSection = ref({})
const TITLES = ['ATELIERS', 'REPAS', 'TRANSFERTS', 'ACCÈS AU THÉÂTRE', 'WIFI', 'CONTACTS SUR PLACE']
watch(ev, (e) => {
  for (const s of e.schedule) slots[s.id] = { ...s }
  for (const s of e.sections) sections[s.id] = { ...s }
  newSlot.value = { day: newSlot.value.day || e.firstDate, time: '', label: '' }
  newSection.value = { title: '', body: '', position: (e.sections.length + 1) * 10 }
}, { immediate: true })

const url = (kind, id = '') => `/events/${ev.value.id}/items/${kind}${id ? '/' + id : ''}`
</script>

<template>
  <div class="actions" style="margin-bottom:12px">
    <RouterLink class="btn" target="_blank" :to="`/events/${ev.id}/print/roadmap`">Feuille de route (PDF)</RouterLink>
    <a class="btn sec" :href="`/api/events/${ev.id}/pdf/roadmap?download=1`">⬇ Télécharger</a>
    <RouterLink class="btn sec" target="_blank" :to="`/events/${ev.id}/print/contacts`">Coordonnées de l'équipe (PDF)</RouterLink>
  </div>
  <p class="muted">La feuille de route est générée à partir des voyages, des hébergements, de l'équipe et des blocs ci-dessous.</p>

  <h2>Planning</h2>
  <div class="card">
    <form v-for="s in ev.schedule" :key="s.id" class="row" style="grid-template-columns:1fr 1fr 3fr auto" @submit.prevent="apply(put(url('schedule', s.id), slots[s.id]))">
      <div><label>Jour</label><input v-model="slots[s.id].day" type="date" required></div><div><label>Heure</label><input v-model="slots[s.id].time"></div>
      <div><label>Intitulé</label><input v-model="slots[s.id].label" required></div>
      <div class="actions"><button class="sec">OK</button><button type="button" class="danger" @click="apply(del(url('schedule', s.id)))">✕</button></div>
    </form>
    <form class="row" style="grid-template-columns:1fr 1fr 3fr auto" @submit.prevent="apply(post(url('schedule'), newSlot))">
      <div><label>Jour</label><input v-model="newSlot.day" type="date" required></div><div><label>Heure</label><input v-model="newSlot.time" placeholder="10h–13h"></div>
      <div><label>Intitulé (montage, répétition, représentation…)</label><input v-model="newSlot.label" required></div><button>Ajouter</button>
    </form>
  </div>

  <h2>Blocs d'information</h2>
  <p class="muted small">Ateliers, repas, transferts, accès au théâtre, wifi… Affichés dans l'ordre (position croissante).</p>
  <form v-for="s in ev.sections" :key="s.id" class="card grid g2" @submit.prevent="apply(put(url('section', s.id), sections[s.id]))">
    <div><label>Titre</label><input v-model="sections[s.id].title" required></div><div><label>Position</label><input v-model="sections[s.id].position" type="number"></div>
    <div style="grid-column:1/-1"><label>Contenu</label><textarea v-model="sections[s.id].body" rows="4" /></div>
    <div class="actions"><button class="sec">Enregistrer</button><button type="button" class="danger" @click="apply(del(url('section', s.id)))">Supprimer</button></div>
  </form>
  <form class="card grid g2" @submit.prevent="apply(post(url('section'), newSection))">
    <datalist id="sec-titles"><option v-for="t in TITLES" :key="t">{{ t }}</option></datalist>
    <div><label>Nouveau bloc</label><input v-model="newSection.title" list="sec-titles" required></div><div><label>Position</label><input v-model="newSection.position" type="number"></div>
    <div style="grid-column:1/-1"><textarea v-model="newSection.body" rows="3" /></div>
    <div><button>Ajouter</button></div>
  </form>
</template>
