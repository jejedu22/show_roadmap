<script setup>
import { inject, reactive, watch } from 'vue'
import { post, put, del } from '../../api.js'
import { money, sum, byDeparture, DIRECTIONS } from '../../format.js'
import SncfSearch from '../../components/SncfSearch.vue'

const { ev, apply } = inject('event')
const blank = (pid, direction) => ({ _key: `new-${pid}-${direction}`, participationId: pid, direction, day: '', origin: '', destination: '', depTime: '', arrTime: '', mode: 'Train', reference: '', price: '', bookedOn: '', note: '', bookingUrl: '' })
const legs = reactive({})
const fresh = reactive({})
const opened = reactive({})
watch(ev, (e) => {
  for (const p of e.participations) {
    for (const l of p.legs) legs[l.id] = { ...l, _key: l.id, bookedOn: l.bookedOn || '' }
    for (const [dir] of DIRECTIONS) fresh[`${p.id}-${dir}`] ??= blank(p.id, dir)
  }
}, { immediate: true })

const MODES = ['Train', 'TER', 'TGV', 'Avion', 'Véhicule perso', 'Bus', 'Taxi']
const FIELDS = [['day', 'Date', 'date'], ['origin', 'De'], ['destination', 'À'], ['depTime', 'Départ', 'text', '14h27'], ['arrTime', 'Arrivée', 'text', '17h25'],
  ['mode', 'Mode'], ['reference', 'Référence'], ['price', 'Prix (€)'], ['bookedOn', 'Réservé le', 'date'], ['note', 'Note'],
  ['bookingUrl', 'Lien réservation / billet', 'url', 'https://…']]

const save = (l) => apply(put(`/events/${ev.value.id}/items/leg/${l.id}`, legs[l.id]))
const remove = (l) => apply(del(`/events/${ev.value.id}/items/leg/${l.id}`))
async function add(p, dir) {
  const key = `${p.id}-${dir}`
  if (await apply(post(`/events/${ev.value.id}/items/leg`, fresh[key]))) fresh[key] = blank(p.id, dir)
}
const legsOf = (p, dir) => p.legs.filter((l) => l.direction === dir).sort(byDeparture)
/** Formulaire d'ajout ouvert là où il reste à saisir : l'aller d'abord, puis le retour une fois l'aller saisi. */
const openByDefault = (p, dir) => dir === 'outbound' ? !legsOf(p, 'outbound').length : legsOf(p, 'outbound').length > 0 && !legsOf(p, 'return').length
const pick = (target, values) => Object.assign(target, Object.fromEntries(Object.entries(values).filter(([k, v]) => v && !(k === 'note' && target.note))))
</script>

<template>
  <p class="muted">Un trajet = une ligne de billet. Un prix négatif correspond à un échange ou une annulation.
    Le lien de réservation apparaît sous forme de bouton « Billet » dans la feuille de route PDF : chacun récupère son billet sans passer par vous.</p>
  <datalist id="modes"><option v-for="m in MODES" :key="m">{{ m }}</option></datalist>
  <div v-if="!ev.participations.length" class="alert">Ajoutez d'abord des personnes dans l'onglet Équipe.</div>
  <div v-for="p in ev.participations" :key="p.id" class="card">
    <h3>{{ p.person.fullName }} <span class="muted small">départ de {{ p.departureCity || '?' }}</span></h3>
    <section v-for="[dir, label, arrow] in DIRECTIONS" :key="dir" class="leg-group">
      <h4>{{ arrow }} {{ label }} <span class="muted small">({{ legsOf(p, dir).length }} trajet{{ legsOf(p, dir).length > 1 ? 's' : '' }})</span></h4>
      <form v-for="l in legsOf(p, dir)" :key="l.id" class="row" style="grid-template-columns:repeat(auto-fit,minmax(110px,1fr))" @submit.prevent="save(l)">
        <div><label>Sens</label><select v-model="legs[l.id].direction"><option value="outbound">Aller</option><option value="return">Retour</option></select></div>
        <div v-for="[f, flabel, type, ph] in FIELDS" :key="f"><label>{{ flabel }}</label>
          <input v-model="legs[l.id][f]" :type="type || 'text'" :placeholder="ph" :list="f === 'mode' ? 'modes' : null" :required="f === 'day'"></div>
        <div class="actions"><button class="sec">OK</button><button type="button" class="danger" @click="remove(l)">✕</button>
          <a v-if="l.bookingUrl" class="btn sec" :href="l.bookingUrl" target="_blank" rel="noopener noreferrer" title="Ouvrir la réservation">Billet ↗</a></div>
      </form>
      <details :open="opened[`${p.id}-${dir}`] ?? openByDefault(p, dir)" @toggle="opened[`${p.id}-${dir}`] = $event.target.open">
        <summary>Ajouter un trajet {{ label.toLowerCase() }}</summary>
        <form class="row" style="grid-template-columns:repeat(auto-fit,minmax(110px,1fr))" @submit.prevent="add(p, dir)">
          <div v-for="[f, flabel, type, ph] in FIELDS" :key="f"><label>{{ flabel }}</label>
            <input v-model="fresh[`${p.id}-${dir}`][f]" :type="type || 'text'" :placeholder="ph" :list="f === 'mode' ? 'modes' : null" :required="f === 'day'"></div>
          <div><button>Ajouter</button></div>
          <SncfSearch :event-id="ev.id" :leg="fresh[`${p.id}-${dir}`]" @pick="pick(fresh[`${p.id}-${dir}`], $event)" />
        </form>
      </details>
    </section>
  </div>

  <h2>Récapitulatif</h2>
  <div class="card scroll">
    <table>
      <thead><tr><th>Nom</th><th>Ville de départ</th><th class="r">Aller</th><th class="r">Retour</th><th class="r">Total</th></tr></thead>
      <tbody><tr v-for="c in ev.personCosts" :key="c.participationId"><td>{{ c.name }}</td><td>{{ c.departureCity }}</td>
        <td class="r">{{ money(c.outbound) }}</td><td class="r">{{ money(c.return) }}</td><td class="r">{{ money(c.outbound + c.return) }}</td></tr></tbody>
      <tfoot><tr><td colspan="2">Total</td><td class="r">{{ money(sum(ev.personCosts, 'outbound')) }}</td><td class="r">{{ money(sum(ev.personCosts, 'return')) }}</td>
        <td class="r">{{ money(ev.quote.travelTickets) }}</td></tr></tfoot>
    </table>
  </div>
  <div class="actions"><RouterLink class="btn sec" target="_blank" :to="`/events/${ev.id}/print/travelcost`">Suivi des frais de voyage (PDF)</RouterLink></div>
</template>
