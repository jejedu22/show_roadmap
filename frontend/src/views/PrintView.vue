<script setup>
import { computed, ref, watch } from 'vue'
import { get } from '../api.js'
import { byDeparture, ddmm, fdate, fperiod, money, sum } from '../format.js'

const props = defineProps({ id: String, doc: String })
const ev = ref(null)
const TITLES = { roadmap: 'Feuille de route', rooming: 'Rooming-list', perdiem: 'Défraiements', travelcost: 'Suivi des frais de voyage', contacts: "Coordonnées de l'équipe", quote: 'Devis' }
watch(() => props.id, async (id) => {
  ev.value = await get(`/events/${id}`)
  document.title = `${TITLES[props.doc]} — ${ev.value.showTitle} — ${ev.value.venue}`
}, { immediate: true })

const night = (p, d) => p.days[d]?.night || 0
const nights = (p) => Object.values(p.days).reduce((s, d) => s + d.night, 0)
/** Trajets d'un sens, regroupés par jour et dans l'ordre des départs : [[jour, [{ p, l }]]]. */
const travel = (dir) => {
  const days = {}
  for (const p of ev.value.participations) for (const l of p.legs) if (l.direction === dir) (days[l.day] ||= []).push({ p, l })
  return Object.keys(days).sort().map((d) => [d, days[d].sort((a, b) => byDeparture(a.l, b.l))])
}
const outbound = computed(() => travel('outbound'))
const inbound = computed(() => travel('return'))
const sortedLegs = (p) => [...p.legs].sort(byDeparture)
const scheduleByDay = computed(() => {
  const days = {}
  for (const s of ev.value.schedule) (days[s.day] ||= []).push(s)
  return Object.entries(days)
})
const print = () => window.print()
const lodgers = (l) => ev.value.participations.filter((p) => p.lodgingId === l.id).map((p) => p.person.firstName).join(', ')
</script>

<template>
  <div v-if="ev" style="background:#eee;min-height:100vh;padding:1px 0">
    <div class="docbar noprint">
      <RouterLink class="btn sec" style="background:#fff" :to="`/events/${ev.id}`">← Retour</RouterLink>
      <button @click="print">Imprimer / PDF</button>
    </div>
    <div class="doc">
      <h1>{{ TITLES[doc] }}</h1>
      <p><b>{{ ev.showTitle }}</b> — {{ ev.venue }}<template v-if="ev.city">, {{ ev.city }}</template> — {{ fperiod(ev.firstDate, ev.lastDate) }}</p>

      <template v-if="doc === 'roadmap'">
        <p v-if="ev.address">{{ ev.address }}</p><pre v-if="ev.venueContact">{{ ev.venueContact }}</pre>
        <h2>Voyages aller</h2>
        <template v-for="[d, items] in outbound" :key="d">
          <h3>{{ fdate(d) }}</h3>
          <table><tbody><tr v-for="{ p, l } in items" :key="l.id">
            <td><b>{{ p.person.firstName }}</b></td><td>{{ l.origin }} › {{ l.destination }}</td>
            <td>{{ l.depTime }}<template v-if="l.arrTime"> – {{ l.arrTime }}</template></td>
            <td>{{ l.reference }}</td><td>{{ l.mode }}</td></tr></tbody></table>
        </template>
        <p v-if="!outbound.length" class="muted">Aucun voyage aller saisi.</p>
        <h2>Hébergement</h2>
        <template v-for="l in ev.lodgings" :key="l.id">
          <h3>{{ l.name }} — {{ lodgers(l) }}</h3>
          <pre>{{ l.address }}<template v-if="l.phone"> · {{ l.phone }}</template>
{{ l.details }}</pre>
        </template>
        <p v-if="!ev.lodgings.length" class="muted">Aucun hébergement renseigné.</p>
        <template v-if="ev.schedule.length">
          <h2>Planning</h2>
          <template v-for="[d, slots] in scheduleByDay" :key="d"><h3>{{ fdate(d) }}</h3><div v-for="s in slots" :key="s.id">{{ s.time }} — {{ s.label }}</div></template>
        </template>
        <template v-for="s in ev.sections" :key="s.id"><h2>{{ s.title }}</h2><pre>{{ s.body }}</pre></template>
        <h2>Voyages retour</h2>
        <template v-for="[d, items] in inbound" :key="d">
          <h3>{{ fdate(d) }}</h3>
          <table><tbody><tr v-for="{ p, l } in items" :key="l.id">
            <td><b>{{ p.person.firstName }}</b></td><td>{{ l.origin }} › {{ l.destination }}</td>
            <td>{{ l.depTime }}<template v-if="l.arrTime"> – {{ l.arrTime }}</template></td>
            <td>{{ l.reference }}</td><td>{{ l.mode }}</td></tr></tbody></table>
        </template>
        <p v-if="!inbound.length" class="muted">Aucun voyage retour saisi.</p>
      </template>

      <table v-else-if="doc === 'rooming'">
        <thead><tr><th>Nom</th><th>Fonction</th><th>Chambre</th><th>Hébergement</th><th v-for="d in ev.dates" :key="d">{{ ddmm(d) }}</th><th>Total</th></tr></thead>
        <tbody><tr v-for="p in ev.participations" :key="p.id"><td>{{ p.person.fullName }}</td><td>{{ p.role }}</td><td>{{ p.roomType }}</td>
          <td>{{ ev.lodgings.find((l) => l.id === p.lodgingId)?.name }}</td><td v-for="d in ev.dates" :key="d">{{ night(p, d) || '' }}</td><td><b>{{ nights(p) }}</b></td></tr></tbody>
        <tfoot><tr><td colspan="4">Nuitées prises en charge par l'organisateur</td><td v-for="d in ev.dates" :key="d">{{ sum(ev.participations, (p) => night(p, d)) }}</td><td>{{ ev.quote.nightsCount }}</td></tr></tfoot>
      </table>

      <template v-else-if="doc === 'perdiem'">
        <p>Base repas : {{ money(ev.rates.mealRate) }} · petit-déjeuner : {{ money(ev.rates.breakfastRate) }}</p>
        <table>
          <thead><tr><th>Nom</th><th>Repas</th><th>Montant repas</th><th>Petits-déj.</th><th>Montant PDJ</th><th>Total</th></tr></thead>
          <tbody><tr v-for="c in ev.personCosts" :key="c.participationId"><td>{{ c.name }}</td><td>{{ c.meals }}</td><td>{{ money(c.meals * ev.rates.mealRate) }}</td>
            <td>{{ c.breakfasts }}</td><td>{{ money(c.breakfasts * ev.rates.breakfastRate) }}</td><td><b>{{ money(c.perDiem) }}</b></td></tr></tbody>
          <tfoot><tr><td>Total</td><td>{{ ev.quote.mealsCount }}</td><td>{{ money(ev.quote.meals) }}</td><td>{{ ev.quote.breakfastsCount }}</td>
            <td>{{ money(ev.quote.breakfasts) }}</td><td>{{ money(ev.quote.meals + ev.quote.breakfasts) }}</td></tr></tfoot>
        </table>
      </template>

      <template v-else-if="doc === 'travelcost'">
        <table>
          <thead><tr><th>Nom</th><th>Ville de départ</th><th>Aller</th><th>Retour</th><th>Total</th></tr></thead>
          <tbody><tr v-for="c in ev.personCosts" :key="c.participationId"><td>{{ c.name }}</td><td>{{ c.departureCity }}</td><td>{{ money(c.outbound) }}</td>
            <td>{{ money(c.return) }}</td><td><b>{{ money(c.outbound + c.return) }}</b></td></tr></tbody>
          <tfoot><tr><td colspan="2">Total</td><td>{{ money(sum(ev.personCosts, 'outbound')) }}</td><td>{{ money(sum(ev.personCosts, 'return')) }}</td><td>{{ money(ev.quote.travelTickets) }}</td></tr></tfoot>
        </table>
        <h2>Détail des billets</h2>
        <table><thead><tr><th>Nom</th><th>Date</th><th>Trajet</th><th>Réf.</th><th>Réservé le</th><th>Prix</th></tr></thead>
          <tbody><template v-for="p in ev.participations" :key="p.id"><tr v-for="l in sortedLegs(p)" :key="l.id"><td>{{ p.person.firstName }}</td><td>{{ fdate(l.day, true) }}</td>
            <td>{{ l.origin }} › {{ l.destination }} ({{ l.mode }})</td><td>{{ l.reference }}</td><td>{{ fdate(l.bookedOn, true) }}</td><td>{{ money(l.price) }}</td></tr></template></tbody></table>
      </template>

      <table v-else-if="doc === 'contacts'">
        <thead><tr><th>Nom</th><th>Fonction</th><th>Téléphone</th><th>E-mail</th><th>Régime alimentaire</th></tr></thead>
        <tbody><tr v-for="p in ev.participations" :key="p.id"><td>{{ p.person.fullName }}</td><td>{{ p.role }}</td><td>{{ p.person.phone }}</td><td>{{ p.person.email }}</td><td>{{ p.person.diet }}</td></tr></tbody>
      </table>

      <table v-else-if="doc === 'quote'">
        <tr><td>Cachet ({{ ev.performances }} représentation(s))</td><td style="text-align:right">{{ money(ev.quote.fee) }} HT</td></tr>
        <template v-for="l in ev.quote.lines" :key="l.label"><tr v-if="l.amount"><td>{{ l.label }}</td><td style="text-align:right">{{ money(l.amount) }} HT</td></tr></template>
        <tr><td><b>Total HT</b></td><td style="text-align:right"><b>{{ money(ev.quote.totalHt) }}</b></td></tr>
        <tr><td>TVA {{ ev.rates.vatRate.toLocaleString("fr-FR") }} %</td><td style="text-align:right">{{ money(ev.quote.vat) }}</td></tr>
        <tr><td><b>Total TTC</b></td><td style="text-align:right"><b>{{ money(ev.quote.totalTtc) }}</b></td></tr>
      </table>
    </div>
  </div>
</template>
