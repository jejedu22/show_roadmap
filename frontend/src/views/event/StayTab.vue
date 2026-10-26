<script setup>
import { inject, reactive, ref, watch } from 'vue'
import { post, put, del } from '../../api.js'
import { fdate } from '../../format.js'

const { ev, apply } = inject('event')
const GRIDS = [['night', 'Hébergement (nuitées)', 1], ['meals', 'Repas midi + soir (0 à 2 par jour)', 2], ['breakfast', 'Petits-déjeuners', 1]]
const grid = reactive({})
const lodgings = reactive({})
const blankLodging = () => ({ name: '', address: '', phone: '', details: '' })
const fresh = ref(blankLodging())

watch(ev, (e) => {
  for (const p of e.participations) {
    grid[p.id] = {}
    for (const d of e.dates) grid[p.id][d] = { night: 0, meals: 0, breakfast: 0, ...(p.days[d] || {}) }
  }
  for (const l of e.lodgings) lodgings[l.id] = { ...l }
}, { immediate: true })

const rowTotal = (pid, k) => ev.value.dates.reduce((s, d) => s + Number(grid[pid][d][k] || 0), 0)
const colTotal = (d, k) => ev.value.participations.reduce((s, p) => s + Number(grid[p.id][d][k] || 0), 0)
const total = (k) => ev.value.participations.reduce((s, p) => s + rowTotal(p.id, k), 0)

const save = () => apply(put(`/events/${ev.value.id}/days`, { days: grid }))
const reset = () => confirm('Recalculer toutes les grilles avec les valeurs par défaut ?') && apply(post(`/events/${ev.value.id}/days/reset`))
const saveLodging = (l) => apply(put(`/events/${ev.value.id}/items/lodging/${l.id}`, lodgings[l.id]))
const removeLodging = (l) => confirm(`Supprimer ${l.name} ?`) && apply(del(`/events/${ev.value.id}/items/lodging/${l.id}`))
async function addLodging() { if (await apply(post(`/events/${ev.value.id}/items/lodging`, fresh.value))) fresh.value = blankLodging() }
</script>

<template>
  <div v-if="!ev.participations.length" class="alert">Ajoutez d'abord des personnes dans l'onglet Équipe.</div>
  <form @submit.prevent="save">
    <template v-for="[k, label, max] in GRIDS" :key="k">
      <h2>{{ label }}</h2>
      <div class="card scroll">
        <table>
          <thead><tr><th>Nom</th><th v-for="d in ev.dates" :key="d" class="cell">{{ fdate(d, true) }}</th><th class="r">Total</th></tr></thead>
          <tbody>
            <tr v-for="p in ev.participations" :key="p.id">
              <td>{{ p.person.fullName }}</td>
              <td v-for="d in ev.dates" :key="d"><input v-model.number="grid[p.id][d][k]" class="cell" type="number" min="0" :max="max"></td>
              <td class="r"><b>{{ rowTotal(p.id, k) }}</b></td>
            </tr>
          </tbody>
          <tfoot><tr><td>Total</td><td v-for="d in ev.dates" :key="d" class="cell">{{ colTotal(d, k) }}</td><td class="r">{{ total(k) }}</td></tr></tfoot>
        </table>
      </div>
    </template>
    <div class="actions"><button>Enregistrer les grilles</button>
      <button type="button" class="sec" @click="reset">Réinitialiser (valeurs par défaut)</button></div>
  </form>

  <h2>Lieux d'hébergement</h2>
  <form v-for="l in ev.lodgings" :key="l.id" class="card grid g3" @submit.prevent="saveLodging(l)">
    <div><label>Nom</label><input v-model="lodgings[l.id].name" required></div>
    <div><label>Adresse</label><input v-model="lodgings[l.id].address"></div>
    <div><label>Téléphone</label><input v-model="lodgings[l.id].phone"></div>
    <div style="grid-column:1/-1"><label>Infos pratiques (horaires, accès, distances…)</label><textarea v-model="lodgings[l.id].details" /></div>
    <div class="actions"><button class="sec">Enregistrer</button><button type="button" class="danger" @click="removeLodging(l)">Supprimer</button></div>
  </form>
  <form class="card grid g3" @submit.prevent="addLodging">
    <div><label>Nouvel hébergement</label><input v-model="fresh.name" required></div>
    <div><label>Adresse</label><input v-model="fresh.address"></div>
    <div><label>Téléphone</label><input v-model="fresh.phone"></div>
    <div class="actions"><button>Ajouter</button></div>
  </form>
  <div class="actions">
    <RouterLink class="btn sec" target="_blank" :to="`/events/${ev.id}/print/rooming`">Rooming-list</RouterLink>
    <RouterLink class="btn sec" target="_blank" :to="`/events/${ev.id}/print/perdiem`">Défraiements</RouterLink>
  </div>
</template>
