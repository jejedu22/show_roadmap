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
const blank = () => ({ kind: 'company', lastName: '', firstName: '', structure: '', places: 1, performanceId: ev.value.invitationPerformances?.[0]?.id ?? '' })
const form = ref(blank())
const editing = ref(null)
const formEl = ref(null)

// Serveur pas encore à jour (déploiement ou migration manquants) : l'onglet le dit au lieu de rester blanc
const ready = computed(() => Array.isArray(ev.value.invitationPerformances) && !!ev.value.invitationSummary)

watch(ev, (e) => {
  if (!Array.isArray(e.invitationPerformances)) return
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
  if (await apply(put(`/events/${ev.value.id}/invitation-quotas`, quotas))) toast('Nombre d\'invitations enregistré')
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
  <div v-if="!ready" class="card empty"><h3>Invitations indisponibles</h3>
    <p>Le serveur n'a pas encore la mise à jour des invitations : redéployez l'application et appliquez la migration de la base de données.</p></div>
  <div v-else class="two-cols">
    <div class="main-col">
      <div class="page-head">
        <h2>Invitations</h2>
        <span class="muted">Non communiquées dans la feuille de route</span>
        <RouterLink class="btn btn-sm" style="margin-left:auto" :to="`/events/${ev.id}/print/invitations`" target="_blank"><Icon name="file" :size="16" />Liste PDF</RouterLink>
      </div>

      <form class="card quota-card" :class="{ unset: !summary.quota }" @submit.prevent="saveQuotas">
        <fieldset :disabled="!allowed('events.edit')" style="border:0;padding:0;margin:0;min-width:0">
          <h3 style="margin-bottom:4px">Nombre d'invitations mises à disposition par le lieu</h3>
          <p v-if="!allowed('events.edit')" class="muted small" style="margin:0 0 10px">Le droit « Organiser les dates » est nécessaire pour le modifier.</p>
          <template v-if="ev.invitationPerformances.length">
            <p class="muted small" style="margin:0 0 12px">Une case par représentation du planning
              (onglet <RouterLink :to="`/events/${ev.id}/roadmap`">Feuille de route</RouterLink>).</p>
            <div class="quota-list">
              <div v-for="s in ev.invitationPerformances" :key="s.id" class="quota-row">
                <label class="field quota-field"><span><b>{{ perfLabel(s) }}</b></span>
                  <span class="quota-input"><input v-model.number="quotas.performances[s.id]" type="number" min="0" inputmode="numeric"> invitations</span></label>
                <span class="small muted">Cie {{ summary.byPerformance[s.id].company }} · Pro {{ summary.byPerformance[s.id].pro }}</span>
                <span class="chip" :class="summary.byPerformance[s.id].left < 0 ? 'bad' : summary.byPerformance[s.id].left ? 'ok' : 'info'">
                  {{ summary.byPerformance[s.id].left < 0 ? `dépassement de ${-summary.byPerformance[s.id].left}` : `${summary.byPerformance[s.id].left} restante${summary.byPerformance[s.id].left > 1 ? 's' : ''}` }}</span>
              </div>
            </div>
          </template>
          <template v-else>
            <label class="field quota-field"><span>Pour toute la date</span>
              <span class="quota-input"><input v-model.number="quotas.total" type="number" min="0" inputmode="numeric"> invitations</span></label>
            <p class="muted small" style="margin:8px 0 0">Pour un nombre par représentation, ajoutez les lignes « Représentation » au planning
              (onglet <RouterLink :to="`/events/${ev.id}/roadmap`">Feuille de route</RouterLink>).</p>
          </template>
          <div v-if="allowed('events.edit')" class="actions" style="margin-top:14px"><button class="btn btn-primary">Enregistrer</button></div>
        </fieldset>
      </form>

      <div class="tiles inv-tiles">
        <div class="card"><span class="muted small">Mises à disposition</span><b class="inv-num">{{ summary.quota }}</b></div>
        <div class="card"><span class="muted small">Attribuées</span><b class="inv-num">{{ summary.used }}</b>
          <span class="small muted">Cie {{ summary.company }} · Pro {{ summary.pro }}</span></div>
        <div class="card" :class="{ warn: summary.left < 0 }"><span class="muted small">{{ summary.left < 0 ? 'Dépassement' : 'Restantes' }}</span>
          <b class="inv-num" :style="summary.left < 0 ? 'color:var(--coral-dark)' : ''">{{ Math.abs(summary.left) }}</b></div>
      </div>

      <p v-if="!ev.invitations.length" class="card empty">Aucune invitation pour l'instant.<template v-if="allowed('events.edit')"><br>
        Ajoutez chaque invité avec le formulaire « Nouvelle invitation ».</template></p>
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

    <aside class="side-col">
      <details class="card help" :open="!ev.invitations.length">
        <summary><b>Comment ça marche</b></summary>
        <ol>
          <li><b>Nombre d'invitations</b> : saisissez en haut le nombre d'invitations données par le lieu, puis « Enregistrer ».
            Pour le répartir par représentation, ajoutez d'abord les lignes « Représentation » (date, heure) au planning de l'onglet
            <RouterLink :to="`/events/${ev.id}/roadmap`">Feuille de route</RouterLink> : une case apparaît alors pour chacune.</li>
          <li><b>Invités</b> : ajoutez chaque personne avec le formulaire « Nouvelle invitation » — Invité Cie (nom, prénom)
            ou Professionnel (nom, prénom, structure), son nombre de places et sa représentation.</li>
          <li><b>Suivi</b> : les compteurs montrent les invitations attribuées et restantes ; « Liste PDF » donne la liste pour la billetterie.</li>
        </ol>
        <p class="muted small" style="margin:0">Les invitations n'apparaissent pas dans la feuille de route envoyée à l'équipe.</p>
      </details>
      <form v-if="allowed('events.edit')" ref="formEl" class="card" style="display:flex;flex-direction:column;gap:10px" @submit.prevent="save">
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
.help summary { cursor: pointer; }
.help ol { margin: 10px 0 8px; padding-left: 20px; display: flex; flex-direction: column; gap: 8px; font-size: 14px; }
.quota-card.unset { border: 2px solid var(--sun); }
.quota-list { display: flex; flex-direction: column; gap: 8px; }
.quota-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: 8px 16px; padding: 10px 12px; border-radius: var(--radius-sm); background: var(--violet-soft); }
.quota-field { display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 12px; color: var(--ink); }
@media (max-width: 600px) { .quota-row { grid-template-columns: 1fr auto; } .quota-row .quota-field { grid-column: 1 / -1; } }
.quota-field > span:first-child { flex: 1 1 auto; min-width: 0; }
.quota-input { display: inline-flex; flex: none; align-items: center; gap: 8px; font-weight: 600; }
@media (min-width: 601px) { .quota-row .quota-field { flex-wrap: nowrap; } }
.quota-input input { width: 96px; font-size: 18px; font-weight: 700; text-align: center; }
@media (max-width: 600px) { .inv-tiles .card { padding: 12px; } .inv-num { font-size: 22px; } }
</style>
