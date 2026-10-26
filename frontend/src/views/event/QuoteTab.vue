<script setup>
import { inject, reactive, ref, watch } from 'vue'
import { post, put, del, toast } from '../../api.js'
import { money } from '../../format.js'
import Icon from '../../components/Icon.vue'

const { ev, apply } = inject('event')
const RATES = [['fee', 'Cachet HT (€)'], ['mealRate', 'Repas / personne / jour'], ['breakfastRate', 'Petit-déjeuner'], ['nightRate', 'Nuitée (0 = prise en charge)'],
  ['truckRate', 'Camion (€ HT / km)'], ['vatRate', 'TVA (%)']]
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
const saved = async (p, msg = 'Devis mis à jour') => { if (await apply(p)) { toast(msg); return true } }
const saveRates = () => saved(put(`/events/${ev.value.id}/rates`, rates))
async function addTruck() { if (await saved(post(url('truck'), newTruck.value))) newTruck.value = { label: '', km: '' } }
async function addExtra() { if (await saved(post(url('extra'), newExtra.value))) newExtra.value = { label: '', amount: '', kind: 'team_transport' } }
</script>

<template>
  <div class="two-cols">
    <div class="main-col">
      <form class="card" @submit.prevent="saveRates">
        <h2 style="margin-bottom:14px">Cachet et tarifs</h2>
        <div class="form-grid"><label v-for="[f, label] in RATES" :key="f" class="field">{{ label }}<input v-model="rates[f]" inputmode="decimal"></label></div>
        <div class="actions" style="margin-top:14px"><button class="btn btn-primary">Enregistrer</button></div>
      </form>

      <section class="card">
        <h2 style="margin-bottom:10px">Transport du décor</h2>
        <form v-for="t in ev.trucks" :key="t.id" class="actions" style="margin-bottom:8px" @submit.prevent="saved(put(url('truck', t.id), trucks[t.id]))">
          <label class="field" style="flex:2 1 200px">Trajet<input v-model="trucks[t.id].label"></label>
          <label class="field" style="flex:1 1 90px">Km<input v-model="trucks[t.id].km" inputmode="decimal"></label>
          <span class="actions" style="align-self:flex-end"><button class="btn btn-sm">OK</button>
            <button type="button" class="btn btn-sm btn-icon btn-danger" aria-label="Supprimer ce trajet" @click="saved(del(url('truck', t.id)))"><Icon name="trash" :size="16" /></button></span>
        </form>
        <form class="actions" @submit.prevent="addTruck">
          <label class="field" style="flex:2 1 200px">Nouveau trajet<input v-model="newTruck.label" placeholder="Paris → Beauvais"></label>
          <label class="field" style="flex:1 1 90px">Km<input v-model="newTruck.km" inputmode="decimal"></label>
          <button class="btn btn-sm" style="align-self:flex-end"><Icon name="plus" :size="16" />Ajouter</button>
        </form>
        <p v-if="ev.quote.truckScale" class="muted small" style="margin:10px 0 0">Barème indicatif : {{ ev.quote.truckScale }}</p>
      </section>

      <section class="card">
        <h2 style="margin-bottom:10px">Frais supplémentaires</h2>
        <form v-for="x in ev.extras" :key="x.id" class="actions" style="margin-bottom:8px" @submit.prevent="saved(put(url('extra', x.id), extras[x.id]))">
          <label class="field" style="flex:2 1 200px">Libellé<input v-model="extras[x.id].label" required></label>
          <label class="field" style="flex:1 1 90px">Montant HT<input v-model="extras[x.id].amount" inputmode="decimal"></label>
          <label class="field" style="flex:1 1 150px">Catégorie<select v-model="extras[x.id].kind"><option value="team_transport">Transport équipe</option><option value="other">Autre</option></select></label>
          <span class="actions" style="align-self:flex-end"><button class="btn btn-sm">OK</button>
            <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer ${x.label}`" @click="saved(del(url('extra', x.id)))"><Icon name="trash" :size="16" /></button></span>
        </form>
        <form class="actions" @submit.prevent="addExtra">
          <label class="field" style="flex:2 1 200px">Nouveau frais<input v-model="newExtra.label" required placeholder="Taxi, métro, véhicule perso…"></label>
          <label class="field" style="flex:1 1 90px">Montant HT<input v-model="newExtra.amount" inputmode="decimal"></label>
          <label class="field" style="flex:1 1 150px">Catégorie<select v-model="newExtra.kind"><option value="team_transport">Transport équipe</option><option value="other">Autre</option></select></label>
          <button class="btn btn-sm" style="align-self:flex-end"><Icon name="plus" :size="16" />Ajouter</button>
        </form>
      </section>
    </div>

    <aside class="side-col">
      <section class="card dark" style="position:sticky;top:20px">
        <h2 style="color:#fff;margin-bottom:12px">Devis</h2>
        <table class="table" style="color:#fff">
          <tr><td style="border-color:var(--ink-3)">Cachet</td><td class="r" style="border-color:var(--ink-3)">{{ money(ev.quote.fee) }}</td></tr>
          <template v-for="l in ev.quote.lines" :key="l.label"><tr v-if="l.amount"><td style="border-color:var(--ink-3);color:var(--muted-on-dark)">{{ l.label }}</td>
            <td class="r" style="border-color:var(--ink-3)">{{ money(l.amount) }}</td></tr></template>
          <tr><td style="border-color:var(--ink-3)">Total HT</td><td class="r" style="border-color:var(--ink-3)"><b>{{ money(ev.quote.totalHt) }}</b></td></tr>
          <tr><td style="border-color:var(--ink-3)">TVA {{ ev.rates.vatRate.toLocaleString('fr-FR') }} %</td><td class="r" style="border-color:var(--ink-3)">{{ money(ev.quote.vat) }}</td></tr>
        </table>
        <p style="display:flex;justify-content:space-between;align-items:baseline;margin:14px 0">
          <span class="eyebrow" style="color:var(--sun)">Total TTC</span><b style="font-family:var(--display);font-size:28px;color:var(--sun)">{{ money(ev.quote.totalTtc) }}</b></p>
        <p class="small" style="color:var(--muted-on-dark);margin:0 0 14px">Billets ({{ money(ev.quote.travelTickets) }}), repas, petits-déjeuners et nuitées viennent des étapes Séjour et Voyages.</p>
        <div class="actions">
          <RouterLink class="btn btn-sun" :to="`/events/${ev.id}/print/quote`" target="_blank"><Icon name="file" :size="16" />Devis PDF</RouterLink>
          <a class="btn" :href="`/api/events/${ev.id}/pdf/quote?download=1`"><Icon name="download" :size="16" />Télécharger</a>
        </div>
      </section>
    </aside>
  </div>
</template>
