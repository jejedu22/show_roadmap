<script setup>
import { inject, reactive, ref, watch } from 'vue'
import { post, put, del, toast } from '../../api.js'
import { fdate } from '../../format.js'
import Icon from '../../components/Icon.vue'

const { ev, apply } = inject('event')
const GRIDS = [['night', 'Nuitées', 1, 'Nuit du jour au lendemain'], ['meals', 'Repas', 2, 'Midi + soir : 0 à 2 par jour'], ['breakfast', 'Petits-déjeuners', 1, '']]
const grid = reactive({})
const lodgings = reactive({})
const kind = ref('night')
const blankLodging = () => ({ name: '', address: '', phone: '', details: '' })
const fresh = ref(blankLodging())
const editing = ref(null)

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
const guests = (l) => ev.value.participations.filter((p) => p.lodgingId === l.id).map((p) => p.person.firstName || p.person.lastName)
const homeless = () => ev.value.participations.filter((p) => !p.lodgingId && Object.values(p.days).some((d) => d.night))

async function save() { if (await apply(put(`/events/${ev.value.id}/days`, { days: grid }))) toast('Grilles enregistrées') }
const reset = () => confirm('Recalculer toutes les grilles à partir des dates d\'arrivée et de départ ?') && apply(post(`/events/${ev.value.id}/days/reset`))
async function saveLodging(l) { if (await apply(put(`/events/${ev.value.id}/items/lodging/${l.id}`, lodgings[l.id]))) { editing.value = null; toast('Hébergement enregistré') } }
const removeLodging = (l) => confirm(`Supprimer ${l.name} ?`) && apply(del(`/events/${ev.value.id}/items/lodging/${l.id}`))
async function addLodging() { if (await apply(post(`/events/${ev.value.id}/items/lodging`, fresh.value))) { toast(`${fresh.value.name} ajouté`); fresh.value = blankLodging() } }
</script>

<template>
  <div class="two-cols">
    <div class="main-col">
      <section class="card">
        <div class="card-head">
          <h2>Présence jour par jour</h2>
          <div class="filters" role="group" aria-label="Grille affichée">
            <button v-for="[k, label] in GRIDS" :key="k" type="button" :aria-pressed="kind === k" @click="kind = k">{{ label }} · {{ total(k) }}</button>
          </div>
        </div>
        <p v-if="!ev.participations.length" class="empty">Ajoutez d'abord des personnes à l'étape Équipe.</p>
        <form v-else @submit.prevent="save">
          <p class="muted small" style="margin:0 0 8px">{{ GRIDS.find((g) => g[0] === kind)[3] }}</p>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Nom</th><th v-for="d in ev.dates" :key="d" class="c">{{ fdate(d, true) }}</th><th class="r">Total</th></tr></thead>
              <tbody>
                <tr v-for="p in ev.participations" :key="p.id">
                  <td style="white-space:nowrap"><b>{{ p.person.firstName || p.person.lastName }}</b></td>
                  <td v-for="d in ev.dates" :key="d" class="c">
                    <input v-model.number="grid[p.id][d][kind]" class="grid-input" type="number" min="0" :max="GRIDS.find((g) => g[0] === kind)[2]"
                      :aria-label="`${p.person.fullName}, ${fdate(d)}`"></td>
                  <td class="r"><b>{{ rowTotal(p.id, kind) }}</b></td>
                </tr>
              </tbody>
              <tfoot><tr><td>Total</td><td v-for="d in ev.dates" :key="d" class="c">{{ colTotal(d, kind) }}</td><td class="r">{{ total(kind) }}</td></tr></tfoot>
            </table>
          </div>
          <div class="actions" style="margin-top:14px">
            <button class="btn btn-primary">Enregistrer les grilles</button>
            <button type="button" class="btn" @click="reset">Recalculer depuis les dates</button>
            <RouterLink class="btn btn-ghost" :to="`/events/${ev.id}/print/rooming`" target="_blank">Rooming-list PDF</RouterLink>
            <RouterLink class="btn btn-ghost" :to="`/events/${ev.id}/print/perdiem`" target="_blank">Défraiements PDF</RouterLink>
          </div>
        </form>
      </section>
    </div>

    <aside class="side-col">
      <h2>Hébergements</h2>
      <p v-if="homeless().length" class="alert" style="margin:0">Sans hébergement : {{ homeless().map((p) => p.person.firstName || p.person.lastName).join(', ') }}.
        <RouterLink :to="`/events/${ev.id}/team`">Attribuer</RouterLink></p>
      <article v-for="l in ev.lodgings" :key="l.id" class="card">
        <form v-if="editing === l.id" style="display:flex;flex-direction:column;gap:10px" @submit.prevent="saveLodging(l)">
          <label class="field">Nom<input v-model="lodgings[l.id].name" required></label>
          <label class="field">Adresse<input v-model="lodgings[l.id].address"></label>
          <label class="field">Téléphone<input v-model="lodgings[l.id].phone" type="tel"></label>
          <label class="field">Infos pratiques<textarea v-model="lodgings[l.id].details" placeholder="Horaires, code d'accès, distances…" /></label>
          <div class="actions"><button class="btn btn-primary">Enregistrer</button><button type="button" class="btn btn-ghost" @click="editing = null">Annuler</button></div>
        </form>
        <template v-else>
          <div class="card-head" style="margin-bottom:4px"><h3><Icon name="bed" :size="16" /> {{ l.name }}</h3>
            <span class="actions"><button class="btn btn-sm btn-icon" :aria-label="`Modifier ${l.name}`" @click="editing = l.id"><Icon name="edit" :size="16" /></button>
              <button class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer ${l.name}`" @click="removeLodging(l)"><Icon name="trash" :size="16" /></button></span></div>
          <p class="muted small" style="margin:0">{{ [l.address, l.phone].filter(Boolean).join(' · ') }}</p>
          <p v-if="l.details" class="small" style="margin:6px 0 0;white-space:pre-wrap">{{ l.details }}</p>
          <p class="small" style="margin:8px 0 0"><b>{{ guests(l).length ? guests(l).join(', ') : 'Personne attribué' }}</b></p>
        </template>
      </article>
      <form class="card" style="display:flex;flex-direction:column;gap:10px" @submit.prevent="addLodging">
        <h3>Nouvel hébergement</h3>
        <label class="field">Nom<input v-model="fresh.name" required placeholder="Hôtel, appartement du théâtre…"></label>
        <label class="field">Adresse<input v-model="fresh.address"></label>
        <label class="field">Téléphone<input v-model="fresh.phone" type="tel"></label>
        <button class="btn btn-primary">Ajouter</button>
      </form>
    </aside>
  </div>
</template>
