<script setup>
import { ref, reactive, watch } from 'vue'
import { useRouter } from 'vue-router'
import { get, post, put, del, showError } from '../api.js'
import { fperiod } from '../format.js'

const props = defineProps({ id: String })
const router = useRouter()
const company = ref(null)
const showEdits = reactive({})
const newShow = reactive({ title: '', description: '' })
const newEvent = reactive({})

function load(c) {
  company.value = c
  for (const s of c.shows) {
    showEdits[s.id] = { title: s.title, description: s.description }
    newEvent[s.id] ??= { venue: '', city: '', firstDate: '', lastDate: '' }
  }
}
watch(() => props.id, async (id) => { company.value = null; load(await get(`/companies/${id}`)) }, { immediate: true })

const run = async (p) => { try { load(await p) } catch (e) { showError(e) } }
const saveShow = (s) => run(put(`/shows/${s.id}`, showEdits[s.id]))
const deleteShow = (s) => confirm(`Supprimer « ${s.title} » ?`) && run(del(`/shows/${s.id}`))
async function addShow() { await run(post(`/companies/${props.id}/shows`, newShow)); Object.assign(newShow, { title: '', description: '' }) }
async function addEvent(s) {
  try { const ev = await post(`/shows/${s.id}/events`, newEvent[s.id]); router.push(`/events/${ev.id}`) } catch (e) { showError(e) }
}
const overlapTitles = (e) => [...new Set(e.overlaps.map((o) => o.showTitle))].join(', ')
</script>

<template>
  <template v-if="company">
    <h1>{{ company.name }}</h1>
    <div class="tabs">
      <RouterLink class="on" :to="`/companies/${company.id}`">Planning</RouterLink>
      <RouterLink :to="`/companies/${company.id}/people`">Équipe de la compagnie</RouterLink>
    </div>

    <h2>Dates de tournée</h2>
    <div class="card scroll">
      <table>
        <thead><tr><th>Période</th><th>Spectacle</th><th>Lieu</th><th>Équipe</th><th>Statut</th><th></th></tr></thead>
        <tbody>
          <tr v-for="e in company.events" :key="e.id">
            <td><RouterLink :to="`/events/${e.id}`">{{ fperiod(e.firstDate, e.lastDate) }}</RouterLink></td>
            <td>{{ e.showTitle }}</td>
            <td>{{ e.venue }}<span v-if="e.city" class="muted"> · {{ e.city }}</span></td>
            <td>{{ e.teamSize }}</td>
            <td>
              <span v-if="e.status === 'option'" class="badge warn">option</span><span v-else class="badge">confirmé</span>
              <span v-if="e.overlaps.length" class="badge bad" :title="e.overlaps.map((o) => `${o.showTitle} (${o.venue})`).join(', ')">⚠ simultané · {{ overlapTitles(e) }}</span>
            </td>
            <td><RouterLink :to="`/events/${e.id}`">Ouvrir</RouterLink></td>
          </tr>
          <tr v-if="!company.events.length"><td colspan="6" class="muted">Aucune date. Ajoutez-en une depuis un spectacle ci-dessous.</td></tr>
        </tbody>
      </table>
      <details v-if="company.cancelledEvents.length" style="margin-top:8px"><summary class="muted small">Dates annulées ({{ company.cancelledEvents.length }})</summary>
        <div v-for="e in company.cancelledEvents" :key="e.id" class="small"><RouterLink :to="`/events/${e.id}`">{{ fperiod(e.firstDate, e.lastDate) }} — {{ e.showTitle }} — {{ e.venue }}</RouterLink></div>
      </details>
    </div>

    <h2>Spectacles</h2>
    <div v-for="s in company.shows" :key="s.id" class="card">
      <form class="grid g2" @submit.prevent="saveShow(s)">
        <div><label>Titre</label><input v-model="showEdits[s.id].title" required></div>
        <div><label>Description</label><input v-model="showEdits[s.id].description"></div>
        <div class="actions"><button class="sec">Enregistrer</button>
          <button v-if="!s.eventCount" type="button" class="danger" @click="deleteShow(s)">Supprimer</button></div>
      </form>
      <details style="margin-top:8px"><summary>Ajouter une date pour « {{ s.title }} »</summary>
        <form class="grid g3" style="margin-top:8px" @submit.prevent="addEvent(s)">
          <div><label>Lieu / théâtre</label><input v-model="newEvent[s.id].venue" required></div>
          <div><label>Ville</label><input v-model="newEvent[s.id].city"></div>
          <div><label>1re représentation</label><input v-model="newEvent[s.id].firstDate" type="date" required></div>
          <div><label>Dernière représentation</label><input v-model="newEvent[s.id].lastDate" type="date"></div>
          <div style="align-self:end"><button>Créer la date</button></div>
        </form>
      </details>
    </div>
    <form class="card grid g2" @submit.prevent="addShow">
      <div><label>Nouveau spectacle</label><input v-model="newShow.title" required></div>
      <div><label>Description</label><input v-model="newShow.description"></div>
      <div><button>Ajouter le spectacle</button></div>
    </form>
  </template>
</template>
