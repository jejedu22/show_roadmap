<script setup>
import { inject, reactive, ref, watch } from 'vue'
import { post, put, del } from '../../api.js'
import { money } from '../../format.js'

const { ev, apply } = inject('event')
const RATES = [['fee', 'Cachet HT (€)'], ['mealRate', 'Repas / pers. / jour (€)'],
  ['breakfastRate', 'Petit-déj. (€)'], ['nightRate', 'Nuitée (€, 0 = pris en charge)'], ['truckRate', 'Camion (€ HT / km)'], ['vatRate', 'TVA (%)']]
const rates = reactive({})
const trucks = reactive({})
const extras = reactive({})
const newTruck = ref({ label: '', km: '' })
const newExtra = ref({ label: '', amount: '', kind: 'team_transport' })
watch(ev, (e) => {
  Object.assign(rates, e.rates)
  for (const t of e.trucks) trucks[t.id] = { ...t }
  for (const x of e.extras) extras[x.id] = { ...x }
}, { immediate: true })

const url = (kind, id = '') => `/events/${ev.value.id}/items/${kind}${id ? '/' + id : ''}`
const saveRates = () => apply(put(`/events/${ev.value.id}/rates`, rates))
async function addTruck() { if (await apply(post(url('truck'), newTruck.value))) newTruck.value = { label: '', km: '' } }
async function addExtra() { if (await apply(post(url('extra'), newExtra.value))) newExtra.value = { label: '', amount: '', kind: 'team_transport' } }
</script>

<template>
  <div class="grid g2">
    <div>
      <form class="card grid g3" @submit.prevent="saveRates">
        <div v-for="[f, label] in RATES" :key="f"><label>{{ label }}</label><input v-model="rates[f]" inputmode="decimal"></div>
        <div class="actions"><button>Enregistrer</button></div>
      </form>

      <h2>Transport du décor</h2>
      <div class="card">
        <form v-for="t in ev.trucks" :key="t.id" class="row" style="grid-template-columns:2fr 1fr auto" @submit.prevent="apply(put(url('truck', t.id), trucks[t.id]))">
          <div><label>Trajet</label><input v-model="trucks[t.id].label"></div><div><label>Km</label><input v-model="trucks[t.id].km" inputmode="decimal"></div>
          <div class="actions"><button class="sec">OK</button><button type="button" class="danger" @click="apply(del(url('truck', t.id)))">✕</button></div>
        </form>
        <form class="row" style="grid-template-columns:2fr 1fr auto" @submit.prevent="addTruck">
          <div><label>Nouveau trajet (ex. Paris → Beauvais)</label><input v-model="newTruck.label"></div>
          <div><label>Km</label><input v-model="newTruck.km" inputmode="decimal"></div><button>Ajouter</button>
        </form>
        <p v-if="ev.quote.truckScale" class="muted small">Barème indicatif : {{ ev.quote.truckScale }}</p>
      </div>

      <h2>Frais supplémentaires</h2>
      <div class="card">
        <form v-for="x in ev.extras" :key="x.id" class="row" style="grid-template-columns:2fr 1fr 1.3fr auto" @submit.prevent="apply(put(url('extra', x.id), extras[x.id]))">
          <div><label>Libellé</label><input v-model="extras[x.id].label" required></div><div><label>Montant HT</label><input v-model="extras[x.id].amount" inputmode="decimal"></div>
          <div><label>Catégorie</label><select v-model="extras[x.id].kind"><option value="team_transport">Transport équipe</option><option value="other">Autre</option></select></div>
          <div class="actions"><button class="sec">OK</button><button type="button" class="danger" @click="apply(del(url('extra', x.id)))">✕</button></div>
        </form>
        <form class="row" style="grid-template-columns:2fr 1fr 1.3fr auto" @submit.prevent="addExtra">
          <div><label>Nouveau (taxi, métro, véhicule perso…)</label><input v-model="newExtra.label" required></div>
          <div><label>Montant HT</label><input v-model="newExtra.amount" inputmode="decimal"></div>
          <div><label>Catégorie</label><select v-model="newExtra.kind"><option value="team_transport">Transport équipe</option><option value="other">Autre</option></select></div><button>Ajouter</button>
        </form>
      </div>
    </div>

    <div>
      <div class="card">
        <h3>Devis</h3>
        <table>
          <tr><td>Cachet</td><td class="r">{{ money(ev.quote.fee) }}</td></tr>
          <tr v-for="l in ev.quote.lines" :key="l.label"><td>{{ l.label }}</td><td class="r">{{ money(l.amount) }}</td></tr>
          <tr><td><b>Total frais annexes</b></td><td class="r"><b>{{ money(ev.quote.annexTotal) }}</b></td></tr>
          <tr><td><b>Total HT</b></td><td class="r"><b>{{ money(ev.quote.totalHt) }}</b></td></tr>
          <tr><td>TVA {{ ev.rates.vatRate.toLocaleString("fr-FR") }} %</td><td class="r">{{ money(ev.quote.vat) }}</td></tr>
          <tr><td><b>Total TTC</b></td><td class="r"><b>{{ money(ev.quote.totalTtc) }}</b></td></tr>
        </table>
        <p class="muted small">Les billets de l'onglet Voyages ({{ money(ev.quote.travelTickets) }}) et les repas/petits-déjeuners/nuitées des grilles alimentent automatiquement le devis.</p>
        <div class="actions"><RouterLink class="btn" target="_blank" :to="`/events/${ev.id}/print/quote`">Version imprimable</RouterLink></div>
      </div>
    </div>
  </div>
</template>
