<script setup>
import { inject, reactive, watch } from 'vue'
import { useRouter } from 'vue-router'
import { put, post, del, showError } from '../../api.js'
import { fperiod, money } from '../../format.js'

const { ev, apply } = inject('event')
const router = useRouter()
const FIELDS = ['venue', 'city', 'address', 'firstDate', 'lastDate', 'performances', 'techArrival', 'artisticArrival', 'departure', 'status', 'venueContact', 'notes']
const form = reactive({})
watch(ev, (e) => FIELDS.forEach((f) => { form[f] = e[f] ?? '' }), { immediate: true })

const conflictNames = () => ev.value.participations.filter((p) => p.conflicts.length).map((p) => p.person.fullName)
const save = () => apply(put(`/events/${ev.value.id}`, form))
async function duplicate() {
  try { const copy = await post(`/events/${ev.value.id}/duplicate`); router.push(`/events/${copy.id}`) } catch (e) { showError(e) }
}
async function remove() {
  if (!confirm('Supprimer définitivement cette date ?')) return
  try { const r = await del(`/events/${ev.value.id}`); router.push(`/companies/${r.companyId}`) } catch (e) { showError(e) }
}
</script>

<template>
  <div v-if="ev.overlaps.length" class="alert">⚠ Cette date chevauche
    <template v-for="(o, i) in ev.overlaps" :key="o.id"><RouterLink :to="`/events/${o.id}`">{{ o.showTitle }} — {{ o.venue }} ({{ fperiod(o.firstDate, o.lastDate) }})</RouterLink>{{ i < ev.overlaps.length - 1 ? ', ' : '' }}</template>.
    <template v-if="conflictNames().length"> Personnes engagées sur les deux : <b>{{ conflictNames().join(', ') }}</b>.</template>
    <template v-else> Aucune personne en commun pour l'instant.</template>
  </div>
  <div class="kpis">
    <div class="kpi"><span class="muted small">Équipe</span><b>{{ ev.participations.length }}</b></div>
    <div class="kpi"><span class="muted small">Total HT</span><b>{{ money(ev.quote.totalHt) }}</b></div>
    <div class="kpi"><span class="muted small">Total TTC</span><b>{{ money(ev.quote.totalTtc) }}</b></div>
    <div class="kpi"><span class="muted small">Nuitées</span><b>{{ ev.quote.nightsCount }}</b></div>
  </div>
  <form class="card grid g3" @submit.prevent="save">
    <div><label>Lieu / théâtre</label><input v-model="form.venue" required></div>
    <div><label>Ville</label><input v-model="form.city"></div>
    <div><label>Adresse</label><input v-model="form.address"></div>
    <div><label>1re représentation</label><input v-model="form.firstDate" type="date" required></div>
    <div><label>Dernière représentation</label><input v-model="form.lastDate" type="date" required></div>
    <div><label>Nb de représentations</label><input v-model="form.performances" type="number" min="1"></div>
    <div><label>Arrivée équipe technique</label><input v-model="form.techArrival" type="date"></div>
    <div><label>Arrivée équipe artistique</label><input v-model="form.artisticArrival" type="date"></div>
    <div><label>Départ équipe</label><input v-model="form.departure" type="date"></div>
    <div><label>Statut</label><select v-model="form.status"><option value="confirmed">Confirmé</option><option value="option">Option</option><option value="cancelled">Annulé</option></select></div>
    <div style="grid-column:1/-1"><label>Contacts du lieu (accueil, technique…)</label><textarea v-model="form.venueContact" /></div>
    <div style="grid-column:1/-1"><label>Notes internes</label><textarea v-model="form.notes" /></div>
    <div class="actions"><button>Enregistrer</button></div>
  </form>
  <div class="actions">
    <a class="btn sec" :href="`/api/events/${ev.id}/export.xlsx`">⬇ Export Excel</a>
    <button class="sec" @click="duplicate">Dupliquer cette date</button>
    <button class="danger" @click="remove">Supprimer</button>
  </div>
</template>
