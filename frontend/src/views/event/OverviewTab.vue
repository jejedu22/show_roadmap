<script setup>
import { inject, reactive, watch } from 'vue'
import { useRouter } from 'vue-router'
import { put, post, del, showError, toast } from '../../api.js'
import { money } from '../../format.js'
import Icon from '../../components/Icon.vue'

const { ev, apply, allowed } = inject('event')
const router = useRouter()
const FIELDS = ['venue', 'city', 'address', 'firstDate', 'lastDate', 'performances', 'techArrival', 'artisticArrival', 'departure', 'status', 'venueContact', 'notes']
const form = reactive({})
watch(ev, (e) => FIELDS.forEach((f) => { form[f] = e[f] ?? '' }), { immediate: true })
const PATHS = { info: '', team: '/team', stay: '/stay', travel: '/travel', quote: '/quote', roadmap: '/roadmap' }

async function save() { if (await apply(put(`/events/${ev.value.id}`, form))) toast('Informations enregistrées') }
async function duplicate() {
  try { const copy = await post(`/events/${ev.value.id}/duplicate`); toast('Date dupliquée'); router.push(`/events/${copy.id}`) } catch (e) { showError(e) }
}
async function remove() {
  if (!confirm('Supprimer définitivement cette date, son équipe, ses voyages et son devis ?')) return
  try { const r = await del(`/events/${ev.value.id}`); toast('Date supprimée'); router.push(`/companies/${r.companyId}`) } catch (e) { showError(e) }
}
</script>

<template>
  <div class="two-cols">
    <div class="main-col">
      <form class="card" @submit.prevent="save"><fieldset :disabled="!allowed('events.edit')" style="border:0;padding:0;margin:0">
        <h2 style="margin-bottom:16px">Le lieu et les dates</h2>
        <div class="form-grid">
          <label class="field">Lieu / théâtre<input v-model="form.venue" required></label>
          <label class="field">Ville<input v-model="form.city"></label>
          <label class="field wide">Adresse<input v-model="form.address" placeholder="3 place Georges Brassens, 60000 Beauvais"></label>
          <label class="field">1re représentation<input v-model="form.firstDate" type="date" required></label>
          <label class="field">Dernière représentation<input v-model="form.lastDate" type="date" required :min="form.firstDate"></label>
          <label class="field">Représentations<input v-model="form.performances" type="number" min="1"></label>
          <label class="field">Arrivée équipe technique<input v-model="form.techArrival" type="date"></label>
          <label class="field">Arrivée équipe artistique<input v-model="form.artisticArrival" type="date"></label>
          <label class="field">Départ de l'équipe<input v-model="form.departure" type="date"></label>
          <label class="field">Statut<select v-model="form.status"><option value="confirmed">Confirmé</option><option value="option">Option</option><option value="cancelled">Annulé</option></select></label>
          <label class="field wide">Contacts du lieu (accueil, technique…)<textarea v-model="form.venueContact" placeholder="Accueil : Prénom Nom — 06 …" /></label>
          <label class="field wide">Notes internes<textarea v-model="form.notes" /></label>
        </div>
        <div v-if="allowed('events.edit')" class="actions" style="margin-top:16px"><button class="btn btn-primary">Enregistrer</button></div>
      </fieldset></form>
    </div>
    <aside class="side-col">
      <div class="card">
        <h2 style="margin-bottom:10px">Reste à faire</h2>
        <div v-if="ev.readiness.todo.length" class="todo">
          <RouterLink v-for="(t, i) in ev.readiness.todo" :key="i" :to="`/events/${ev.id}${PATHS[t.step]}`"><span class="dot" aria-hidden="true" /><span>{{ t.label }}</span></RouterLink>
        </div>
        <p v-else class="muted" style="margin:0">Tout est prêt pour cette date.</p>
      </div>
      <div v-if="ev.quote" class="card">
        <div class="tiles" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">
          <div><span class="muted small">Total HT</span><br><b style="font-family:var(--display);font-size:20px">{{ money(ev.quote.totalHt) }}</b></div>
          <div><span class="muted small">Total TTC</span><br><b style="font-family:var(--display);font-size:20px">{{ money(ev.quote.totalTtc) }}</b></div>
        </div>
      </div>
      <div v-if="allowed('finance.view') || allowed('events.edit')" class="card">
        <h3 style="margin-bottom:10px">Autres actions</h3>
        <div class="actions">
          <a v-if="allowed('finance.view')" class="btn btn-sm" :href="`/api/events/${ev.id}/export.xlsx`"><Icon name="download" :size="16" />Export Excel</a>
          <button v-if="allowed('events.edit')" class="btn btn-sm" @click="duplicate"><Icon name="copy" :size="16" />Dupliquer</button>
          <button v-if="allowed('events.edit')" class="btn btn-sm btn-danger" @click="remove"><Icon name="trash" :size="16" />Supprimer</button>
        </div>
      </div>
    </aside>
  </div>
</template>
