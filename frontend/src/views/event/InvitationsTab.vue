<script setup>
import { computed, inject, reactive, ref, watch } from 'vue'
import { post, put, del, toast } from '../../api.js'
import { fdate } from '../../format.js'
import Icon from '../../components/Icon.vue'

/**
 * Invitations : places mises à disposition par le lieu (par représentation du planning, sinon pour toute la date),
 * invités de la compagnie (nom, prénom) et professionnels (nom, prénom, structure). Jamais dans la feuille de route.
 */
const { ev, apply, allowed } = inject('event')
const KINDS = { company: 'Cie', pro: 'Pro' }
const quotas = reactive({ total: 0, performances: {} })
const blank = () => ({ kind: 'company', lastName: '', firstName: '', structure: '', places: 1, performanceId: ev.value.invitationPerformances[0]?.id ?? '' })
const form = ref(blank())
const editing = ref(null)
const formEl = ref(null)

watch(ev, (e) => {
  quotas.total = e.invitationQuota
  quotas.performances = Object.fromEntries(e.invitationPerformances.map((s) => [s.id, s.quota]))
}, { immediate: true })

const summary = computed(() => ev.value.invitationSummary)
const perfLabel = (s) => [fdate(s.day, true), s.time, s.detail].filter(Boolean).join(' · ')
// Groupes affichés : une représentation chacun, puis « À répartir » (ou « Toute la date » sans représentation au planning)
const groups = computed(() => {
  const list = ev.value.invitationPerformances.map((s) => ({ key: s.id, title: perfLabel(s), stats: summary.value.byPerformance[s.id] }))
  const none = summary.value.byPerformance.none
  const ids = new Set(ev.value.invitationPerformances.map((s) => s.id))
  const loose = ev.value.invitations.filter((i) => !ids.has(i.performanceId))
  if (!ev.value.invitationPerformances.length) list.push({ key: 'none', title: 'Toute la date', stats: none })
  else if (loose.length) list.push({ key: 'none', title: 'À répartir sur une représentation', stats: none, loose: true })
  return list.map((g) => ({ ...g, rows: g.key === 'none' ? loose : ev.value.invitations.filter((i) => i.performanceId === g.key) }))
})
const sortRows = (rows) => [...rows].sort((a, b) => (a.kind === b.kind ? 0 : a.kind === 'company' ? -1 : 1) || a.lastName.localeCompare(b.lastName, 'fr'))

async function saveQuotas() {
  if (await apply(put(`/events/${ev.value.id}/invitation-quotas`, quotas))) toast('Places enregistrées')
}
function edit(i) {
  editing.value = i.id
  form.value = { ...i, performanceId: i.performanceId ?? '' }
  formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}
function cancel() { editing.value = null; form.value = blank() }
async function save() {
  const url = `/events/${ev.value.id}/items/invitation${editing.value ? '/' + editing.value : ''}`
  const body = { ...form.value, performanceId: form.value.performanceId || null }
  if (!await apply(editing.value ? put(url, body) : post(url, body))) return
  toast(editing.value ? 'Invitation modifiée' : `Invitation de ${form.value.firstName || form.value.lastName} ajoutée`)
  const keep = { kind: form.value.kind, performanceId: form.value.performanceId }
  cancel()
  Object.assign(form.value, keep) // saisie en série : même type, même représentation
}
async function remove(i) {
  if (confirm(`Supprimer l'invitation de ${[i.firstName, i.lastName].filter(Boolean).join(' ')} ?`) && await apply(del(`/events/${ev.value.id}/items/invitation/${i.id}`))) {
    if (editing.value === i.id) cancel()
    toast('Invitation supprimée')
  }
}
</script>

<template>
  <div class="two-cols">
    <div class="main-col">
      <div class="page-head">
        <h2>Invitations</h2>
        <span class="muted">Non communiquées dans la feuille de route</span>
      </div>

      <div class="tiles inv-tiles">
        <div class="card"><span class="muted small">Mises à disposition</span><b class="inv-num">{{ summary.quota }}</b></div>
        <div class="card"><span class="muted small">Attribuées</span><b class="inv-num">{{ summary.used }}</b>
          <span class="small muted">Cie {{ summary.company }} · Pro {{ summary.pro }}</span></div>
        <div class="card" :class="{ warn: summary.left < 0 }"><span class="muted small">{{ summary.left < 0 ? 'Dépassement' : 'Restantes' }}</span>
          <b class="inv-num" :style="summary.left < 0 ? 'color:var(--coral-dark)' : ''">{{ Math.abs(summary.left) }}</b></div>
      </div>

      <form class="card" @submit.prevent="saveQuotas">
        <fieldset :disabled="!allowed('events.edit')" style="border:0;padding:0;margin:0;min-width:0">
          <h3 style="margin-bottom:6px">Places mises à disposition par le lieu</h3>
          <template v-if="ev.invitationPerformances.length">
            <p class="muted small" style="margin:0 0 10px">Par représentation, d'après le planning (onglet <RouterLink :to="`/events/${ev.id}/roadmap`">Feuille de route</RouterLink>).</p>
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>Représentation</th><th class="r">Places</th><th class="c">Cie</th><th class="c">Pro</th><th class="r">Restantes</th></tr></thead>
                <tbody><tr v-for="s in ev.invitationPerformances" :key="s.id">
                  <td><b>{{ perfLabel(s) }}</b></td>
                  <td class="r"><input v-model.number="quotas.performances[s.id]" class="grid-input" type="number" min="0" :aria-label="`Places pour ${perfLabel(s)}`"></td>
                  <td class="c">{{ summary.byPerformance[s.id].company }}</td><td class="c">{{ summary.byPerformance[s.id].pro }}</td>
                  <td class="r"><span class="chip" :class="summary.byPerformance[s.id].left < 0 ? 'bad' : summary.byPerformance[s.id].left ? 'ok' : 'info'">{{ summary.byPerformance[s.id].left }}</span></td>
                </tr></tbody>
              </table>
            </div>
          </template>
          <template v-else>
            <p class="muted small" style="margin:0 0 10px">Pour répartir les places par représentation, ajoutez les lignes « Représentation » au planning
              (onglet <RouterLink :to="`/events/${ev.id}/roadmap`">Feuille de route</RouterLink>).</p>
            <label class="field" style="max-width:220px">Places pour toute la date<input v-model.number="quotas.total" type="number" min="0"></label>
          </template>
          <div v-if="allowed('events.edit')" class="actions" style="margin-top:12px"><button class="btn btn-primary">Enregistrer les places</button></div>
        </fieldset>
      </form>

      <p v-if="!ev.invitations.length" class="card empty">Aucune invitation pour l'instant.</p>
      <section v-for="g in groups.filter((g) => g.rows.length || g.key !== 'none')" :key="g.key" class="card">
        <div class="card-head" style="margin-bottom:8px">
          <h3>{{ g.title }}</h3>
          <span class="small muted">{{ g.stats.company + g.stats.pro }}<template v-if="!g.loose"> / {{ g.stats.quota }}</template> place{{ g.stats.company + g.stats.pro > 1 ? 's' : '' }}
            · Cie {{ g.stats.company }} · Pro {{ g.stats.pro }}</span>
        </div>
        <p v-if="!g.rows.length" class="muted small" style="margin:0">Aucune invitation sur cette représentation.</p>
        <div v-else class="table-wrap">
          <table class="table">
            <thead><tr><th>Type</th><th>Nom</th><th>Prénom</th><th>Structure</th><th class="c">Places</th><th v-if="allowed('events.edit')"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody><tr v-for="i in sortRows(g.rows)" :key="i.id" :class="{ 'row-editing': editing === i.id }">
              <td><span class="chip" :class="i.kind === 'pro' ? 'info' : 'ok'">{{ KINDS[i.kind] }}</span></td>
              <td><b>{{ i.lastName }}</b></td><td>{{ i.firstName }}</td><td>{{ i.structure }}</td><td class="c">{{ i.places }}</td>
              <td v-if="allowed('events.edit')" class="r" style="white-space:nowrap">
                <button type="button" class="btn btn-sm btn-icon" :aria-label="`Modifier l'invitation de ${i.lastName}`" @click="edit(i)"><Icon name="edit" :size="16" /></button>
                <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer l'invitation de ${i.lastName}`" @click="remove(i)"><Icon name="trash" :size="16" /></button>
              </td>
            </tr></tbody>
          </table>
        </div>
      </section>
    </div>

    <aside v-if="allowed('events.edit')" class="side-col">
      <form ref="formEl" class="card" style="display:flex;flex-direction:column;gap:10px" @submit.prevent="save">
        <h3>{{ editing ? "Modifier l'invitation" : 'Nouvelle invitation' }}</h3>
        <div class="filters" role="group" aria-label="Type d'invitation">
          <button v-for="(label, k) in KINDS" :key="k" type="button" :aria-pressed="form.kind === k" @click="form.kind = k">{{ label === 'Cie' ? 'Invité Cie' : 'Professionnel' }}</button>
        </div>
        <label v-if="ev.invitationPerformances.length" class="field">Représentation
          <select v-model="form.performanceId"><option v-for="s in ev.invitationPerformances" :key="s.id" :value="s.id">{{ perfLabel(s) }}</option><option value="">À répartir</option></select></label>
        <label class="field">Nom<input v-model="form.lastName" required maxlength="120"></label>
        <label class="field">Prénom<input v-model="form.firstName" maxlength="120"></label>
        <label v-if="form.kind === 'pro'" class="field">Structure<input v-model="form.structure" maxlength="255" placeholder="Théâtre, festival, compagnie…"></label>
        <label class="field" style="max-width:120px">Places<input v-model.number="form.places" type="number" min="1" max="50" required></label>
        <div class="actions">
          <button class="btn btn-primary" style="flex:1">{{ editing ? 'Enregistrer' : 'Ajouter' }}</button>
          <button v-if="editing" type="button" class="btn" @click="cancel">Annuler</button>
        </div>
      </form>
    </aside>
  </div>
</template>

<style scoped>
.inv-tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.inv-tiles .card { display: flex; flex-direction: column; gap: 2px; }
.inv-num { font-family: var(--display); font-size: 26px; line-height: 1.1; }
.row-editing td { background: var(--sun-soft); }
@media (max-width: 600px) { .inv-tiles .card { padding: 12px; } .inv-num { font-size: 22px; } }
</style>
